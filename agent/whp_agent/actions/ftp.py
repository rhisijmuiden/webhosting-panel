"""Extra FTP/SFTP accounts per website (ProFTPD, installer/modules/75-ftp.sh).

The panel owns the accounts and sends the complete list with ``ftp.sync``;
the agent writes ProFTPD's virtual user files from it:

* ``/etc/proftpd/whp/ftpd.passwd``: ``login:hash:uid:gid::home:/usr/sbin/nologin``.
  Every account runs as the website's own Linux user (files keep the right
  owner and count for the site's disk quota) and is locked in ``home``
  (``DefaultRoot ~``), a folder inside ``/var/www/vhosts/<domain>``.
* ``/etc/proftpd/whp/ftpd.group``: the group ``whp-ftp-ro`` lists the read-only
  accounts; ``common.conf`` denies every write command to that group.

Only password hashes reach the agent (SHA-512 crypt or yescrypt, made by the
panel). ProFTPD reads both files at every login, so no reload is needed.
Accounts of suspended or unknown websites are left out. Linux users can never
log in to ProFTPD (``AuthOrder mod_auth_file.c``).
"""

import grp
import os
import pwd
import re

from .. import paths
from .. import validate as v
from ..registry import ActionError, action
from ..run import run
from ..wordpress import site_info

FTP_DIR = os.environ.get("WHP_FTP_DIR", "/etc/proftpd/whp")
VHOSTS = os.environ.get("WHP_VHOSTS", "/var/www/vhosts")
RO_GROUP = "whp-ftp-ro"
SHELL = "/usr/sbin/nologin"
FTPDCTL = "/usr/bin/ftpdctl"
DEFAULT_PORTS = {"WHP_FTP_PORT": "21", "WHP_SFTP_PORT": "2222", "WHP_FTP_PASV": "49152-49407"}

_LOCAL = re.compile(r"^[a-z0-9][a-z0-9._-]{0,31}$")
# SHA-512 crypt ($6$, optional rounds) or yescrypt ($y$), as PHP's crypt() and mkpasswd make them.
_HASH = re.compile(r"^(\$6\$(rounds=\d{4,9}\$)?[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{86}|\$y\$[./A-Za-z0-9]{1,8}\$[./A-Za-z0-9]{1,86}\$[./A-Za-z0-9]{43})$")
_SEGMENT = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9_. -]{0,63}$")


def _folder(value):
    """A folder below the website root: "" (the whole website) or "public", "public/wp-content/uploads"."""
    if value in (None, "", "/"):
        return ""
    if not isinstance(value, str):
        raise ActionError("invalid_params", "folder must be a string")
    parts = [p for p in value.strip().strip("/").split("/") if p not in ("", ".")]
    if len(parts) > 8 or any(p == ".." or not _SEGMENT.fullmatch(p) or p.endswith(" ") for p in parts):
        raise ActionError("invalid_params", "folder has an invalid format")
    return "/".join(parts)


def _account(raw):
    if not isinstance(raw, dict):
        raise ActionError("invalid_params", "Every account must be an object")
    domain = v.domain(raw, "domain")
    login = v.string(raw, "login", max_len=300)
    local, at, rest = login.rpartition("@")
    if not at or rest != domain or not _LOCAL.fullmatch(local):
        raise ActionError("invalid_params", f"login must be <name>@{domain}")
    password_hash = v.string(raw, "password_hash", max_len=200)
    if not _HASH.fullmatch(password_hash):
        raise ActionError("invalid_params", f"password_hash of {login} is not a SHA-512 crypt or yescrypt hash")
    return {
        "login": login,
        "domain": domain,
        "password_hash": password_hash,
        "folder": _folder(raw.get("folder")),
        "read_only": v.boolean(raw, "read_only", required=False, default=False),
    }


def _home(site_root, folder, uid, gid):
    """The account's folder, created as the site user when it is missing. Never outside the site root."""
    root = os.path.realpath(site_root)
    target = os.path.join(root, folder) if folder else root
    real = os.path.realpath(target)
    if real != root and not real.startswith(root + os.sep):
        raise ActionError("forbidden", "The folder points outside the website")
    if os.path.isdir(real):
        return real
    if os.path.lexists(real):
        raise ActionError("conflict", f"{folder} exists but is not a folder")
    current = root
    for part in folder.split("/"):
        current = os.path.join(current, part)
        if os.path.islink(current):
            raise ActionError("forbidden", "The folder points outside the website")
        if not os.path.exists(current):
            os.mkdir(current, 0o755)
            os.chown(current, uid, gid, follow_symlinks=False)
        elif not os.path.isdir(current):
            raise ActionError("conflict", f"{folder} exists but is not a folder")
    return current


def _write(path, content):
    tmp = f"{path}.whp-new"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as fh:
        fh.write(content)
    os.chmod(tmp, 0o600)
    os.replace(tmp, path)


def _read_logins(path):
    try:
        with open(path) as fh:
            return {line.split(":", 1)[0] for line in fh if ":" in line}
    except FileNotFoundError:
        return set()


def _kick(logins):
    """Close the open sessions of accounts that were removed or locked (best effort)."""
    if not logins or not os.path.exists(FTPDCTL):
        return
    for login in sorted(logins):
        try:
            run([FTPDCTL, "-s", "/run/proftpd.sock", "kick", "user", login], timeout=10, check=False)
        except ActionError:
            pass


def _installed():
    try:
        return grp.getgrnam(RO_GROUP).gr_gid
    except KeyError:
        raise ActionError("command_failed", "The FTP server is not installed yet (installer module 75-ftp)")


@action("ftp.sync")
def ftp_sync(params):
    """{accounts: [{login, domain, password_hash, folder, read_only}]}: the complete list."""
    accounts = v.list_of(params, "accounts", _account, max_items=5000)
    ro_gid = _installed()
    os.makedirs(FTP_DIR, mode=0o750, exist_ok=True)

    lines, readonly, skipped, seen = [], [], [], set()
    sites = {}
    for acc in accounts:
        if acc["login"] in seen:
            raise ActionError("invalid_params", f"{acc['login']} is listed twice")
        seen.add(acc["login"])
        domain = acc["domain"]
        if domain not in sites:
            try:
                sites[domain] = site_info(domain)
            except ActionError:
                sites[domain] = None
        site = sites[domain]
        if site is None:
            skipped.append({"login": acc["login"], "reason": "unknown_website"})
            continue
        if site["suspended"]:
            skipped.append({"login": acc["login"], "reason": "suspended"})
            continue
        try:
            pw = pwd.getpwnam(site["user"])
        except (KeyError, TypeError):
            skipped.append({"login": acc["login"], "reason": "no_site_user"})
            continue
        try:
            home = _home(os.path.join(VHOSTS, domain), acc["folder"], pw.pw_uid, pw.pw_gid)
        except ActionError as e:
            skipped.append({"login": acc["login"], "reason": "folder", "message": e.message})
            continue
        except OSError as e:
            skipped.append({"login": acc["login"], "reason": "folder", "message": e.strerror or str(e)})
            continue
        if ":" in home or "\n" in home:
            skipped.append({"login": acc["login"], "reason": "folder", "message": "Folder name not supported"})
            continue
        lines.append(f"{acc['login']}:{acc['password_hash']}:{pw.pw_uid}:{pw.pw_gid}::{home}:{SHELL}\n")
        if acc["read_only"]:
            readonly.append(acc["login"])

    passwd = os.path.join(FTP_DIR, "ftpd.passwd")
    before = _read_logins(passwd)
    _write(passwd, "".join(lines))
    _write(os.path.join(FTP_DIR, "ftpd.group"), f"{RO_GROUP}:x:{ro_gid}:{','.join(readonly)}\n")
    active = {line.split(":", 1)[0] for line in lines}
    # Removed or locked accounts lose their open sessions; read-only changes apply at the next login.
    _kick(before - active)
    return {"accounts": len(lines), "read_only": len(readonly), "skipped": skipped}


def _ports():
    data = dict(DEFAULT_PORTS)
    try:
        with open(paths.INSTALLER_ENV) as fh:
            for line in fh:
                key, sep, value = line.strip().partition("=")
                if sep and key in data and value:
                    data[key] = value.strip('"')
    except OSError:
        pass
    return data


def _fingerprints():
    out = []
    for kind in ("ed25519", "ecdsa", "rsa"):
        pub = f"/etc/ssh/ssh_host_{kind}_key.pub"
        if not os.path.exists(pub):
            continue
        try:
            proc = run(["ssh-keygen", "-l", "-E", "sha256", "-f", pub], timeout=10, check=False)
        except ActionError:
            continue
        parts = proc.stdout.split()
        if proc.returncode == 0 and len(parts) >= 2:
            out.append({"type": kind.upper(), "sha256": parts[1]})
    return out


@action("ftp.status")
def ftp_status(params):
    """Whether ProFTPD runs, its ports and the SFTP host key fingerprints (for the connection details)."""
    try:
        grp.getgrnam(RO_GROUP)
        installed = True
    except KeyError:
        installed = False
    active = False
    if installed:
        try:
            active = run(["systemctl", "is-active", "--quiet", "proftpd"], timeout=10, check=False).returncode == 0
        except ActionError:
            active = False
    p = _ports()
    low, _, high = p["WHP_FTP_PASV"].partition("-")
    return {
        "installed": installed,
        "active": active,
        "ftp_port": int(p["WHP_FTP_PORT"]),
        "sftp_port": int(p["WHP_SFTP_PORT"]),
        "passive_ports": [int(low), int(high or low)],
        "tls_required": True,
        "accounts": len(_read_logins(os.path.join(FTP_DIR, "ftpd.passwd"))),
        "fingerprints": _fingerprints() if installed else [],
    }
