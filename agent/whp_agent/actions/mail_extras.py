"""Mail extras: autoresponders (Sieve vacation) and IMAP import from another provider.

Autoresponders
  mail.autoreply_sync gets the complete list from the panel. Every mailbox with
  an autoreply gets /var/vmail/<domain>/<user>/whp-autoreply.sieve (vmail 0600),
  a Dovecot "before" script (installer/templates/dovecot/dovecot.conf,
  sieve_script whp_autoreply) that runs after the spam routing and before the
  user's own filters. Mailboxes that are not in the list lose their script.
  Start and end dates are checked by Sieve itself (currentdate), so nothing
  has to switch it on or off at midnight.

IMAP import
  imapsync copies a mailbox from another provider into a local mailbox. It runs
  as a transient systemd unit (whp-imap-import-<id>.service, user whp) so it
  survives an agent restart, and writes its output to
  /var/lib/webhosting-panel/mail/imports/<id>/log. The local side logs in over
  loopback IMAP with the Dovecot master user "whp-import" (installer,
  /etc/dovecot/master-users), so the customer's own password is never needed.
  The source password lives in a 0600 file of the job only while imapsync runs.
  imapsync never deletes anything on either side; running it again copies only
  what is new.
"""

from __future__ import annotations

import imaplib
import ipaddress
import json
import os
import pwd
import re
import shutil
import socket
import ssl
import time
from pathlib import Path

from whp_agent import paths
from whp_agent.registry import ActionError, action
from whp_agent.run import run
from whp_agent.actions.mailbox import DOVECOT_USERS, VMAIL_GID, VMAIL_ROOT, VMAIL_UID, _address

AUTOREPLY_FILE = "whp-autoreply.sieve"
AUTOREPLY_HEADER = "# Managed by the Webhosting Panel (mail.autoreply_sync). Do not edit.\n"
MAX_SUBJECT = 200
MAX_BODY = 10_000

IMPORT_DIR = Path(paths.STATE_DIR) / "mail" / "imports"
IMPORT_CONF = Path(paths.CONFIG_DIR) / "mail-import.conf"
SECRETS_FILE = Path(paths.CONFIG_DIR) / "secrets.env"
MASTER_USERS = Path("/etc/dovecot/master-users")
IMAPSYNC = "/usr/local/bin/imapsync"  # upstream script, installer/modules/60-mail.sh
SYSTEMD_RUN = "/usr/bin/systemd-run"
SYSTEMCTL = "/usr/bin/systemctl"
RUN_AS = "whp"
MASTER_USER = "whp-import"
UNIT = "whp-imap-import-{id}.service"
MAX_RUNTIME = 48 * 3600
SECURITY = ("ssl", "starttls", "none")

_HOST = re.compile(r"^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$")
_DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
_CTRL = re.compile(r"[\x00-\x08\x0b-\x1f\x7f]")

# Runs inside the unit: imapsync with the argv from argv.json, output to log,
# exit code to exit. No "$" or "%" so systemd leaves it alone.
RUNNER = (
    "import json,os,subprocess,sys\n"
    "d=sys.argv[1]\n"
    "a=json.load(open(os.path.join(d,'argv.json')))\n"
    "log=open(os.path.join(d,'log'),'ab',buffering=0)\n"
    "rc=subprocess.call(a,stdout=log,stderr=subprocess.STDOUT,stdin=subprocess.DEVNULL,cwd=d)\n"
    "for f in ('pass1','pass2'):\n"
    "    try: os.unlink(os.path.join(d,f))\n"
    "    except OSError: pass\n"
    "open(os.path.join(d,'exit.tmp'),'w').write(str(rc))\n"
    "os.replace(os.path.join(d,'exit.tmp'),os.path.join(d,'exit'))\n"
)


# ---------------------------------------------------------------- autoreplies

def _sieve_string(value: str) -> str:
    """A Sieve quoted string (RFC 5228 2.4.2): escape backslash and double quote."""
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def _text(value: object, field: str, max_len: int, multiline: bool) -> str:
    if not isinstance(value, str):
        raise ActionError("invalid_params", f"{field} must be text")
    value = value.replace("\r\n", "\n").replace("\r", "\n")
    if not multiline:
        value = value.replace("\n", " ")
    value = _CTRL.sub("", value).strip()
    if not value or len(value) > max_len:
        raise ActionError("invalid_params", f"{field} must be 1-{max_len} characters")
    return value


def _date(value: object, field: str) -> str | None:
    if value in (None, ""):
        return None
    if not isinstance(value, str) or not _DATE.match(value):
        raise ActionError("invalid_params", f"{field} must be YYYY-MM-DD")
    try:
        time.strptime(value, "%Y-%m-%d")
    except ValueError:
        raise ActionError("invalid_params", f"{field} is not a real date")
    return value


def parse_autoreplies(params: dict) -> dict[str, dict]:
    """Validate mail.autoreply_sync params (pure, unit tested)."""
    raw = params.get("autoreplies")
    if not isinstance(raw, list) or len(raw) > 50_000:
        raise ActionError("invalid_params", "autoreplies must be a list")
    out: dict[str, dict] = {}
    for i, r in enumerate(raw):
        if not isinstance(r, dict):
            raise ActionError("invalid_params", f"autoreplies[{i}] must be an object")
        email = _address(r.get("email"), f"autoreplies[{i}].email")
        if email in out:
            raise ActionError("invalid_params", f"duplicate autoreply for {email}")
        days = r.get("days", 1)
        if isinstance(days, bool) or not isinstance(days, int) or not 1 <= days <= 30:
            raise ActionError("invalid_params", f"autoreplies[{i}].days must be 1-30")
        start, end = _date(r.get("start"), f"autoreplies[{i}].start"), _date(r.get("end"), f"autoreplies[{i}].end")
        if start and end and end < start:
            raise ActionError("invalid_params", f"autoreplies[{i}].end is before the start")
        addresses = r.get("addresses", [])
        if not isinstance(addresses, list) or len(addresses) > 200:
            raise ActionError("invalid_params", f"autoreplies[{i}].addresses must be a list")
        name = r.get("from_name")
        out[email] = {
            "subject": _text(r.get("subject"), f"autoreplies[{i}].subject", MAX_SUBJECT, False),
            "body": _text(r.get("body"), f"autoreplies[{i}].body", MAX_BODY, True),
            "start": start,
            "end": end,
            "days": days,
            "addresses": sorted({email, *(_address(a, f"autoreplies[{i}].addresses") for a in addresses)}),
            "from_name": _text(name, f"autoreplies[{i}].from_name", 120, False).replace('"', "'").replace("<", "").replace(">", "") if name else None,
        }
    return out


def render_autoreply(email: str, r: dict) -> str:
    conditions = ['not header :is "X-Spam" "Yes"']
    if r["start"]:
        conditions.append(f'currentdate :value "ge" "date" {_sieve_string(r["start"])}')
    if r["end"]:
        conditions.append(f'currentdate :value "le" "date" {_sieve_string(r["end"])}')
    sender = f'"{r["from_name"]}" <{email}>' if r["from_name"] else email
    addresses = ", ".join(_sieve_string(a) for a in r["addresses"])
    return (
        AUTOREPLY_HEADER
        + 'require ["vacation", "date", "relational"];\n\n'
        # Spam and mailing lists never get an answer (vacation itself skips lists and auto-replies).
        + "if allof (" + ",\n          ".join(conditions) + ") {\n"
        + f"    vacation :days {r['days']} :subject {_sieve_string(r['subject'])}\n"
        + f"        :from {_sieve_string(sender)}\n"
        + f"        :addresses [{addresses}]\n"
        + f"        {_sieve_string(r['body'])};\n"
        + "}\n"
    )


def _home(email: str) -> Path:
    local, domain = email.split("@", 1)
    return VMAIL_ROOT / domain / local


def _chown_vmail(path: Path) -> None:
    try:
        os.chown(path, VMAIL_UID, VMAIL_GID)
    except (PermissionError, FileNotFoundError):
        pass


def _existing_scripts() -> dict[str, Path]:
    found = {}
    if VMAIL_ROOT.is_dir():
        for script in VMAIL_ROOT.glob(f"*/*/{AUTOREPLY_FILE}"):
            found[f"{script.parent.name}@{script.parent.parent.name}"] = script
    return found


@action("mail.autoreply_sync")
def autoreply_sync(params: dict) -> dict:
    """Replace all autoresponders. Send the complete list every time."""
    plan = parse_autoreplies(params)
    existing = _existing_scripts()
    written, removed, missing = [], [], []
    for email, r in sorted(plan.items()):
        home = _home(email)
        if not home.is_dir():
            missing.append(email)  # mail.mailboxes_sync creates the home; the next sync writes it
            continue
        script = home / AUTOREPLY_FILE
        content = render_autoreply(email, r)
        if script.exists() and script.read_text(encoding="utf-8") == content:
            continue
        tmp = home / f".{AUTOREPLY_FILE}.tmp"
        tmp.write_text(content, encoding="utf-8")
        os.chmod(tmp, 0o600)
        _chown_vmail(tmp)
        os.replace(tmp, script)
        # Compile now, so a broken script shows up here and not at delivery.
        proc = run(["sievec", str(script)], timeout=30, check=False)
        if proc.returncode != 0:
            script.unlink(missing_ok=True)
            raise ActionError("command_failed", f"Autoreply for {email} does not compile", {"stderr": proc.stderr[-1000:]})
        _chown_vmail(script.with_suffix(".svbin"))
        written.append(email)
    for email, script in sorted(existing.items()):
        if email not in plan:
            script.unlink(missing_ok=True)
            script.with_suffix(".svbin").unlink(missing_ok=True)
            removed.append(email)
    return {"autoreplies": len(plan), "written": written, "removed": removed, "missing_home": missing}


# ---------------------------------------------------------------- IMAP import

def _config() -> dict:
    cfg = {"IMAP_IMPORT_ALLOW_PRIVATE": "no", "IMAP_IMPORT_MAX_RUNNING": "3"}
    try:
        for line in IMPORT_CONF.read_text(encoding="utf-8").splitlines():
            key, sep, value = line.partition("=")
            if sep and key.strip() in cfg:
                cfg[key.strip()] = value.strip().strip("'\"")
    except OSError:
        pass
    return cfg


def allow_private() -> bool:
    return os.environ.get("WHP_IMAP_IMPORT_ALLOW_PRIVATE") == "yes" or _config()["IMAP_IMPORT_ALLOW_PRIVATE"] == "yes"


def _import_id(params: dict) -> int:
    value = params.get("id")
    if isinstance(value, bool) or not isinstance(value, int) or not 1 <= value <= 2 ** 40:
        raise ActionError("invalid_params", "id must be a positive number")
    return value


def parse_source(params: dict) -> dict:
    """Validate the source mailbox (pure apart from nothing; unit tested)."""
    host = params.get("host")
    host = host.strip().lower().rstrip(".") if isinstance(host, str) else ""
    if not _HOST.match(host) or ".." in host:
        raise ActionError("invalid_params", "host must be a host name or IP address")
    security = params.get("security", "ssl")
    if security not in SECURITY:
        raise ActionError("invalid_params", f"security must be one of {', '.join(SECURITY)}")
    port = params.get("port", 993 if security == "ssl" else 143)
    if isinstance(port, bool) or not isinstance(port, int) or not 1 <= port <= 65535:
        raise ActionError("invalid_params", "port must be 1-65535")
    user = params.get("user")
    if not isinstance(user, str) or not 1 <= len(user) <= 255 or _CTRL.search(user) or "\n" in user:
        raise ActionError("invalid_params", "user must be 1-255 characters")
    password = params.get("password")
    if not isinstance(password, str) or not 1 <= len(password) <= 1024 or "\n" in password or "\x00" in password:
        raise ActionError("invalid_params", "password must be 1-1024 characters")
    return {"host": host, "port": port, "security": security, "user": user, "password": password}


def resolve_host(host: str) -> str:
    """Refuse private and local addresses, so customers cannot reach the server's own network."""
    try:
        infos = socket.getaddrinfo(host, None, type=socket.SOCK_STREAM)
    except (socket.gaierror, UnicodeError):
        raise ActionError("not_found", f"Cannot resolve {host}")
    addrs = []
    for info in infos:
        ip = ipaddress.ip_address(info[4][0].split("%")[0])
        if getattr(ip, "ipv4_mapped", None):
            ip = ip.ipv4_mapped
        addrs.append(ip)
    if not addrs:
        raise ActionError("not_found", f"Cannot resolve {host}")
    if not allow_private():
        for ip in addrs:
            if not ip.is_global or ip.is_multicast:
                raise ActionError("forbidden", f"{host} points to a private or local address ({ip}); that is not allowed")
    return str(addrs[0])


def _connect(src: dict, timeout: int = 20) -> imaplib.IMAP4:
    ctx = ssl.create_default_context()
    try:
        if src["security"] == "ssl":
            conn = imaplib.IMAP4_SSL(src["host"], src["port"], ssl_context=ctx, timeout=timeout)
        else:
            conn = imaplib.IMAP4(src["host"], src["port"], timeout=timeout)
            if src["security"] == "starttls":
                conn.starttls(ssl_context=ctx)
    except ssl.SSLCertVerificationError as e:
        raise ActionError("command_failed", f"The certificate of {src['host']} is not valid: {e.verify_message}")
    except (OSError, imaplib.IMAP4.error) as e:
        raise ActionError("command_failed", f"Cannot connect to {src['host']}:{src['port']}: {e}")
    return conn


_LIST = re.compile(r'^\((?P<flags>[^)]*)\) (?:"(?P<sep>[^"]*)"|NIL) (?P<name>.+)$')


def _folders(conn: imaplib.IMAP4) -> list[tuple[str, bool]]:
    """(raw folder name, selectable) from LIST."""
    typ, data = conn.list()
    out = []
    for raw in data or []:
        if not isinstance(raw, bytes):
            continue
        m = _LIST.match(raw.decode("utf-8", "replace"))
        if not m:
            continue
        name = m.group("name")
        out.append((name, "\\noselect" not in m.group("flags").lower() and "\\nonexistent" not in m.group("flags").lower()))
    return out


@action("mail.imap_import_test")
def imap_import_test(params: dict) -> dict:
    """Log in at the source and count its folders and messages (the import total)."""
    src = parse_source(params)
    resolve_host(src["host"])
    conn = _connect(src)
    try:
        try:
            conn.login(src["user"], src["password"])
        except imaplib.IMAP4.error as e:
            raise ActionError("forbidden", f"Login refused: {e}")
        folders, messages = [], 0
        for name, selectable in _folders(conn)[:500]:
            if not selectable:
                continue
            count = None
            try:
                typ, data = conn.status(name, "(MESSAGES)")
                m = re.search(rb"MESSAGES (\d+)", data[0] or b"") if typ == "OK" and data else None
                count = int(m.group(1)) if m else None
            except imaplib.IMAP4.error:
                pass
            messages += count or 0
            folders.append({"name": name.strip('"'), "messages": count})
        return {"ok": True, "host": src["host"], "folders": folders, "messages": messages}
    finally:
        try:
            conn.logout()
        except (OSError, imaplib.IMAP4.error):
            pass


def _master_password() -> str:
    try:
        for line in SECRETS_FILE.read_text(encoding="utf-8").splitlines():
            key, _, value = line.partition("=")
            if key.strip() == "IMAP_IMPORT_MASTER_PASS" and value.strip():
                return value.strip().strip("'\"")
    except OSError:
        pass
    raise ActionError("command_failed", "The mail import login is not set up yet; update the panel (installer) first")


def _mailbox_exists(email: str) -> bool:
    try:
        lines = DOVECOT_USERS.read_text(encoding="utf-8").splitlines()
    except OSError:
        return False
    return any(line.split(":", 1)[0] == email for line in lines)


def imapsync_argv(src: dict, email: str, job: Path) -> list[str]:
    argv = [
        IMAPSYNC,
        "--host1", src["host"], "--port1", str(src["port"]), "--user1", src["user"],
        "--passfile1", str(job / "pass1"),
        "--host2", "127.0.0.1", "--port2", "143", "--user2", email,
        "--authuser2", MASTER_USER, "--authmech2", "PLAIN", "--passfile2", str(job / "pass2"),
        "--nossl2", "--notls2",
        "--automap", "--addheader", "--nofoldersizesatend",
        "--timeout1", "120", "--timeout2", "120",
        "--tmpdir", str(job), "--nolog", "--noreleasecheck",
    ]
    if src["security"] == "ssl":
        argv += ["--ssl1"]
    elif src["security"] == "starttls":
        argv += ["--tls1"]
    else:
        argv += ["--nossl1", "--notls1"]
    if src["host"] in ("imap.gmail.com", "imap.googlemail.com"):
        argv += ["--gmail1"]  # skips the "All Mail" copies and maps Gmail's folders
    return argv


def unit_argv(import_id: int, job: Path) -> list[str]:
    return [
        SYSTEMD_RUN, "--quiet", "--collect", f"--unit={UNIT.format(id=import_id)}",
        f"--uid={RUN_AS}", f"--gid={RUN_AS}",
        "-p", f"RuntimeMaxSec={MAX_RUNTIME}", "-p", "Nice=10", "-p", "IOSchedulingClass=idle",
        "-p", "MemoryMax=1G", "-p", "TasksMax=64", "-p", "NoNewPrivileges=yes",
        "-p", "PrivateTmp=yes", "-p", "ProtectSystem=strict", "-p", "ProtectHome=yes",
        "-p", f"ReadWritePaths={job}", "-p", "UMask=0077",
        "-E", f"HOME={job}", "-E", "LANG=C.UTF-8",
        "/usr/bin/python3", "-I", "-c", RUNNER, str(job),
    ]


def _active(import_id: int) -> bool:
    return run([SYSTEMCTL, "is-active", "--quiet", UNIT.format(id=import_id)], timeout=15, check=False).returncode == 0


def _job_dir(import_id: int) -> Path:
    return IMPORT_DIR / str(import_id)


def _owner(path: Path) -> None:
    try:
        pw = pwd.getpwnam(RUN_AS)
        os.chown(path, pw.pw_uid, pw.pw_gid)
    except (KeyError, PermissionError):
        pass


def _write_private(path: Path, content: str) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        fh.write(content)
    _owner(path)


@action("mail.imap_import_start")
def imap_import_start(params: dict) -> dict:
    """Start (or run again) the import of one source mailbox into a local mailbox."""
    import_id = _import_id(params)
    email = _address(params.get("email"), "email")
    src = parse_source(params)
    if not os.access(IMAPSYNC, os.X_OK):
        raise ActionError("command_failed", "imapsync is not installed; update the panel (installer) first")
    if not _mailbox_exists(email):
        raise ActionError("not_found", f"Mailbox {email} does not exist on this server")
    resolve_host(src["host"])
    if _active(import_id):
        raise ActionError("conflict", "This import is already running")
    running = sum(1 for d in IMPORT_DIR.glob("*") if d.name.isdigit() and _active(int(d.name))) if IMPORT_DIR.is_dir() else 0
    limit = int(_config()["IMAP_IMPORT_MAX_RUNNING"] or 3)
    if running >= limit:
        raise ActionError("conflict", f"{running} imports are running already (limit {limit}); try again later", {"busy": True})
    master = _master_password()

    IMPORT_DIR.mkdir(parents=True, exist_ok=True)
    os.chmod(IMPORT_DIR, 0o750)
    job = _job_dir(import_id)
    shutil.rmtree(job, ignore_errors=True)
    job.mkdir(mode=0o700)
    _owner(job)
    _write_private(job / "pass1", src["password"] + "\n")
    _write_private(job / "pass2", master + "\n")
    _write_private(job / "argv.json", json.dumps(imapsync_argv(src, email, job)))
    _write_private(job / "meta.json", json.dumps({"email": email, "host": src["host"], "user": src["user"], "started_at": int(time.time())}))
    (job / "log").touch(mode=0o600)
    _owner(job / "log")
    proc = run(unit_argv(import_id, job), timeout=30, check=False)
    if proc.returncode != 0:
        shutil.rmtree(job, ignore_errors=True)
        raise ActionError("command_failed", "The import could not be started", {"stderr": proc.stderr[-1000:]})
    return {"id": import_id, "state": "running", "unit": UNIT.format(id=import_id)}


_TOTAL = re.compile(r"^Host1 Nb messages:\s+(\d+)", re.M)
_LEFT = re.compile(r"(\d+)/(\d+) msgs left")
_STAT = re.compile(r"^(Messages transferred|Messages skipped|Total bytes transferred)\s*:\s*(\d+)", re.M)
_ERRORS = re.compile(r"^Detected (\d+) errors?", re.M)
_ERROR_LINE = re.compile(r"(?i)\b(err|error|failure|failed|refused|NO \[)")


def parse_log(head: str, tail: str) -> dict:
    """Progress from imapsync's output (pure, unit tested)."""
    total = None
    m = _TOTAL.search(head) or _TOTAL.search(tail)
    if m:
        total = int(m.group(1))
    left = None
    lefts = _LEFT.findall(tail)
    if lefts:
        left, of = int(lefts[-1][0]), int(lefts[-1][1])
        total = total if total is not None else of
    stats = {k: int(v) for k, v in _STAT.findall(tail)}
    errors = _ERRORS.findall(tail)
    out = {
        "total": total,
        "left": left,
        "transferred": stats.get("Messages transferred"),
        "skipped": stats.get("Messages skipped"),
        "bytes": stats.get("Total bytes transferred"),
        "errors": int(errors[-1]) if errors else None,
        "finished_stats": "Messages transferred" in stats,
    }
    if total:
        if out["finished_stats"]:
            done = total
        elif left is not None:
            done = max(0, total - left)
        else:
            done = 0
        out["done"] = min(done, total)
        out["percent"] = int(min(100, round(out["done"] * 100 / total)))
    else:
        out["done"] = out["transferred"] or 0
        out["percent"] = 100 if out["finished_stats"] else 0
    return out


def _read_ends(path: Path, size: int = 65536) -> tuple[str, str]:
    try:
        with open(path, "rb") as fh:
            head = fh.read(size)
            fh.seek(0, os.SEEK_END)
            end = fh.tell()
            fh.seek(max(0, end - size))
            tail = fh.read(size)
    except OSError:
        return "", ""
    return head.decode("utf-8", "replace"), tail.decode("utf-8", "replace")


def _clean_tail(tail: str, lines: int = 40) -> list[str]:
    # Lines that never carry secrets; imapsync reads passwords from files and does not print them.
    out = [line.rstrip() for line in tail.splitlines() if line.strip()]
    return [line[:300] for line in out[-lines:]]


@action("mail.imap_import_status")
def imap_import_status(params: dict) -> dict:
    import_id = _import_id(params)
    job = _job_dir(import_id)
    if not job.is_dir():
        return {"id": import_id, "state": "unknown"}
    active = _active(import_id)
    head, tail = _read_ends(job / "log")
    progress = parse_log(head, tail)
    exit_code = None
    try:
        exit_code = int((job / "exit").read_text().strip())
    except (OSError, ValueError):
        pass
    if active:
        state = "running"
    elif (job / "stopped").exists():
        state = "stopped"
    elif exit_code == 0:
        state = "done"
    elif exit_code is not None and progress["finished_stats"]:
        state = "partial"  # finished, but some messages failed (quota, too large, ...)
    else:
        state = "failed"
    if not active:
        for f in ("pass1", "pass2"):
            (job / f).unlink(missing_ok=True)
    errors = [line for line in _clean_tail(tail, 400) if _ERROR_LINE.search(line)][-10:]
    return {
        "id": import_id,
        "state": state,
        "exit_code": exit_code,
        "progress": progress,
        "log": _clean_tail(tail),
        "error_lines": errors,
    }


@action("mail.imap_import_stop")
def imap_import_stop(params: dict) -> dict:
    import_id = _import_id(params)
    job = _job_dir(import_id)
    if not job.is_dir():
        raise ActionError("not_found", "Unknown import")
    (job / "stopped").touch()
    run([SYSTEMCTL, "stop", UNIT.format(id=import_id)], timeout=60, check=False)
    for f in ("pass1", "pass2"):
        (job / f).unlink(missing_ok=True)
    return {"id": import_id, "state": "stopped"}


@action("mail.imap_import_forget")
def imap_import_forget(params: dict) -> dict:
    """Remove the files of a finished import (its log)."""
    import_id = _import_id(params)
    if _active(import_id):
        raise ActionError("conflict", "Stop the import first")
    shutil.rmtree(_job_dir(import_id), ignore_errors=True)
    return {"id": import_id, "removed": True}


@action("mail.imap_import_available")
def imap_import_available(params: dict) -> dict:
    return {"available": os.access(IMAPSYNC, os.X_OK) and MASTER_USERS.exists(), "max_running": int(_config()["IMAP_IMPORT_MAX_RUNNING"] or 3)}
