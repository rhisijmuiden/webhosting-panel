"""Source independent copy steps. Every step is safe to run again: the
second run only copies what changed (rsync) or replaces the database with a
fresh dump, which is how the final sync before a DNS switch works.
"""

from __future__ import annotations

import os
import re
import secrets
import subprocess
import tempfile
import threading
from pathlib import Path

from ..registry import ActionError
from ..run import SAFE_ENV, run
from .ssh import Connection

VMAIL_ROOT = Path(os.environ.get("WHP_VMAIL_ROOT", "/var/vmail"))
MARIADB = os.environ.get("WHP_MARIADB", "mariadb")
WHP_SITE = "/usr/local/sbin/whp-site"

DB_NAME = re.compile(r"^[A-Za-z0-9_]{1,64}$")
RESERVED_DATABASES = {"mysql", "information_schema", "performance_schema", "sys", "whp_panel", "snappymail", "phpmyadmin"}
RESERVED_USERS = {"root", "mysql", "mariadb.sys", "whp_panel", "debian-sys-maint"}
DB_USER = re.compile(r"^[A-Za-z0-9_.-]{1,80}$")
# DEFINER clauses name users of the old server; MariaDB refuses or warns on them.
_DEFINER = re.compile(rb"/\*!50013 DEFINER=`[^`]*`@`[^`]*` SQL SECURITY DEFINER \*/|DEFINER=`[^`]*`@`[^`]*`\s*")
# MySQL 8 collations MariaDB before 11.4 does not know.
_COLLATIONS = (
    (rb"utf8mb4_0900_ai_ci", rb"utf8mb4_unicode_ci"),
    (rb"utf8mb4_0900_as_cs", rb"utf8mb4_bin"),
)

RSYNC_BASE = ["rsync", "-rlt", "--partial", "--info=stats1", "--human-readable", "--timeout=600"]
# Index files of the old Dovecot are rebuilt here; uidlist and uidvalidity are
# kept so mail clients do not download everything again.
MAIL_EXCLUDES = ["dovecot.index*", "dovecot.list.index*", "dovecot.mailbox.log", "maildirsize", "courierimap*", "tmp/*"]


def rsync(conn: Connection, source: str, dest: str, owner: str, delete: bool, excludes=(), chmod="Du=rwx,Dgo=rx,Fu=rw,Fgo=r",
          ignore_existing: bool = False, timeout: int = 6 * 3600) -> dict:
    # --perms: apply the --chmod modes exactly. Without it rsync masks new files with the
    # agent's umask (0027), and nginx (www-data) gets 403 on files it cannot read.
    argv = [*RSYNC_BASE, "-og", "--perms", f"--chown={owner}:{owner}", f"--chmod={chmod}", "-e", conn.rsync_shell()]
    if delete:
        argv.append("--delete-after")
    if ignore_existing:
        argv.append("--ignore-existing")
    for e in excludes:
        argv.append(f"--exclude={e}")
    argv += [f"{conn.target}:{source.rstrip('/')}/", dest.rstrip("/") + "/"]
    proc = run(argv, timeout=timeout, check=False)
    # 24: some files vanished during the copy (normal on a live server).
    if proc.returncode not in (0, 24):
        raise ActionError("command_failed", f"rsync failed (exit {proc.returncode})", {"stderr": proc.stderr[-2000:]})
    return {"stats": _rsync_stats(proc.stdout), "vanished": proc.returncode == 24}


def _rsync_stats(out: str) -> dict:
    stats = {}
    for key, pattern in (("files", r"Number of regular files transferred: ([\d,.]+)"), ("total_size", r"Total file size: ([\d,.]+\S*)"),
                         ("sent", r"Total transferred file size: ([\d,.]+\S*)")):
        m = re.search(pattern, out)
        if m:
            stats[key] = m.group(1)
    return stats


def copy_site_files(conn: Connection, source_base: str, site: dict, target_base: str, delete: bool = True) -> dict:
    """Copy the source's web directory into the site, owned by the site user."""
    root = site.get("root") or ""
    user = site.get("user") or ""
    if not root.startswith("/var/www/vhosts/") or not re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", user):
        raise ActionError("internal", "Unexpected site layout from whp-site")
    if not re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}", target_base):
        raise ActionError("invalid_params", "target must be one directory name")
    dest = os.path.join(root, target_base)
    os.makedirs(dest, exist_ok=True)
    os.chown(dest, *_ids(user))
    os.chmod(dest, 0o755)
    result = rsync(conn, source_base, dest, user, delete=delete,
                   chmod="Du=rwx,Dgo=rx,Fu=rwX,Fgo=rX", excludes=[".well-known/acme-challenge/*"])
    result["dest"] = dest
    return result


def copy_maildir(conn: Connection, source: str, domain: str, local: str, mode: str = "mirror") -> dict:
    """mirror: make the box equal to the source (before the DNS switch).
    add: only fetch messages that are not here yet (after the switch, for late mail)."""
    home = VMAIL_ROOT / domain / local
    dest = home / "Maildir"
    for d in (VMAIL_ROOT / domain, home, dest):
        d.mkdir(mode=0o700, exist_ok=True)
        try:
            os.chown(d, 5000, 5000)
        except PermissionError:
            pass
    return rsync(conn, source, str(dest), "vmail", delete=(mode == "mirror"), excludes=MAIL_EXCLUDES,
                 chmod="Du=rwx,Dgo=,Fu=rw,Fgo=", ignore_existing=(mode == "add"))


def _ids(user: str) -> tuple[int, int]:
    import pwd

    try:
        pw = pwd.getpwnam(user)
    except KeyError:
        raise ActionError("not_found", f"System user {user} does not exist")
    return pw.pw_uid, pw.pw_gid


# ---- databases -------------------------------------------------------------------

def local_sql(statement: str, database: str | None = None, timeout: int = 120) -> str:
    argv = [MARIADB, "-N", "-B"]
    if database:
        argv += ["-D", database]
    return run([*argv, "-e", statement], timeout=timeout).stdout


def _ident(name: str) -> str:
    return "`" + name.replace("`", "``") + "`"


def _lit(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def import_database(conn: Connection, dump_command: str, target: str, log=lambda line: None, timeout: int = 6 * 3600) -> dict:
    """Stream the dump over SSH straight into MariaDB (no temp file, compressed in transit)."""
    if not DB_NAME.match(target) or target.lower() in RESERVED_DATABASES:
        raise ActionError("invalid_params", f"Invalid or reserved database name {target!r}")
    local_sql(f"CREATE DATABASE IF NOT EXISTS {_ident(target)} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    src = conn.popen(dump_command, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    dst = subprocess.Popen([MARIADB, "--max-allowed-packet=1G", "-D", target], stdin=subprocess.PIPE,
                           stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, env=dict(SAFE_ENV), shell=False)
    errors: dict[str, bytes] = {}

    def drain(name, stream):
        errors[name] = stream.read()[-4000:]

    threads = [threading.Thread(target=drain, args=("source", src.stderr)), threading.Thread(target=drain, args=("target", dst.stderr))]
    for t in threads:
        t.start()
    copied = 0
    timer = threading.Timer(timeout, lambda: (src.kill(), dst.kill()))
    timer.start()
    try:
        for line in src.stdout:
            copied += len(line)
            dst.stdin.write(filter_dump_line(line))
        dst.stdin.close()
        src.wait()
        dst.wait()
    except BrokenPipeError:
        src.kill()
        dst.wait()
    finally:
        timer.cancel()
        for t in threads:
            t.join(timeout=10)
    if src.returncode != 0:
        raise ActionError("command_failed", f"Dump of the source database failed: {errors.get('source', b'').decode(errors='replace')[-500:]}")
    if dst.returncode != 0:
        raise ActionError("command_failed", f"Import into {target} failed: {errors.get('target', b'').decode(errors='replace')[-500:]}")
    log(f"database {target}: {copied // 1024} KB imported")
    return {"database": target, "bytes": copied}


def filter_dump_line(line: bytes) -> bytes:
    if b"DEFINER" in line:
        line = _DEFINER.sub(b"", line)
    if b"_0900_" in line:
        for old, new in _COLLATIONS:
            line = line.replace(old, new)
    return line


def ensure_db_user(login: str, database: str, native_hash: str | None) -> dict:
    """Create or update the user on localhost with the old password hash, or a new password."""
    if not DB_USER.match(login) or login.lower() in RESERVED_USERS or not DB_NAME.match(database):
        raise ActionError("invalid_params", "Invalid database user or name")
    user = f"{_lit(login)}@'localhost'"
    generated = None
    if native_hash and re.fullmatch(r"\*[0-9A-F]{40}", native_hash):
        auth = f"IDENTIFIED BY PASSWORD {_lit(native_hash)}"
    else:
        generated = secrets.token_urlsafe(18)
        auth = f"IDENTIFIED BY {_lit(generated)}"
    exists = local_sql(f"SELECT COUNT(*) FROM mysql.global_priv WHERE User={_lit(login)} AND Host='localhost'").strip() not in ("", "0")
    if exists and generated:
        # Keep the password a previous run set.
        generated = None
    elif exists:
        local_sql(f"ALTER USER {user} {auth}")
    else:
        local_sql(f"CREATE USER {user} {auth}")
    local_sql(f"GRANT ALL PRIVILEGES ON {_ident(database)}.* TO {user}")
    return {"user": login, "password_kept": generated is None, "new_password": generated}


# ---- certificates ----------------------------------------------------------------

def install_certificate(domain: str, pem: dict) -> dict:
    """Check the pair and hand it to whp-site, which serves it until Let's Encrypt takes over."""
    with tempfile.TemporaryDirectory(prefix="whp-cert-") as tmp:
        cert = Path(tmp) / "fullchain.pem"
        key = Path(tmp) / "privkey.pem"
        cert.write_text(pem["fullchain"], encoding="ascii")
        key.write_text(pem["key"], encoding="ascii")
        os.chmod(key, 0o600)
        if run(["openssl", "x509", "-checkend", "86400", "-noout", "-in", str(cert)], timeout=10, check=False).returncode != 0:
            return {"installed": False, "reason": "expired"}
        pub_cert = run(["openssl", "x509", "-pubkey", "-noout", "-in", str(cert)], timeout=10, check=False).stdout
        pub_key = run(["openssl", "pkey", "-pubout", "-in", str(key)], timeout=10, check=False).stdout
        if not pub_cert or pub_cert.strip() != pub_key.strip():
            return {"installed": False, "reason": "key_mismatch"}
        names = run(["openssl", "x509", "-noout", "-ext", "subjectAltName", "-in", str(cert)], timeout=10, check=False).stdout
        if f"DNS:{domain}" not in names and f"DNS:*.{domain.split('.', 1)[-1]}" not in names:
            return {"installed": False, "reason": "name_mismatch"}
        run([WHP_SITE, "ssl", domain, "--import-cert", str(cert), "--import-key", str(key), "--json"], timeout=300)
    return {"installed": True}
