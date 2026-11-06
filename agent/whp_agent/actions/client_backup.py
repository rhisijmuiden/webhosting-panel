"""Customer backups: a customer backs up a website to their own FTP, FTPS or
SFTP/SCP location, and restores from it or from an uploaded zip file.

These are separate from the server backups (whp-backup, restic): the result
is one plain .zip per backup that the customer can download, keep and upload
again, also to another server.

    <domain>-20261106-023000-full.zip
    <domain>-20261107-023000-incr-20261106-023000.zip   (changes since that full backup)

Zip layout: files/... (the website folder), mail/... (the domain's mailboxes),
databases/<name>.sql (one dump per database) and whp-backup.json (manifest).

An incremental backup holds the files that changed since the last full
backup (to the same location) plus all databases, and the list of files that
exist at that moment. Restoring it needs only that full backup and the
incremental one, never a chain.

Security:
- Website files are read and written as the website's own Linux user (mail
  as vmail) through site_worker.py, never as root.
- A database dump is imported as the database's own MariaDB user, so a
  crafted .sql file cannot touch other databases or grant itself rights.
- Remote hosts are resolved first; private, loopback and link-local
  addresses are refused unless CLIENT_BACKUP_ALLOW_PRIVATE=yes.
- Uploaded zips are checked before anything is extracted: names, sizes,
  compression ratio and the manifest.
- Passwords and keys arrive per call and live only in a private temp folder.
"""

import fcntl
import hashlib
import ipaddress
import json
import os
import re
import secrets
import shutil
import socket
import stat
import subprocess
import tarfile
import tempfile
import threading
import time
import uuid
import zipfile
from pathlib import Path

from .. import paths
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import SAFE_ENV, run
from . import databases as dbs

WORKER = str(Path(__file__).resolve().parent.parent / "site_worker.py")
PYTHON = "/usr/bin/python3"
RCLONE = os.environ.get("WHP_RCLONE", "rclone")
STATE = Path(os.environ.get("WHP_CLIENT_BACKUP_STATE", os.path.join(paths.STATE_DIR, "client-backups")))
UPLOAD_DIR = os.environ.get("WHP_UPLOAD_DIR", os.path.join(paths.STATE_DIR, "uploads"))
VHOSTS = os.environ.get("WHP_VHOSTS", "/var/www/vhosts")
VMAIL = os.environ.get("WHP_VMAIL", "/var/vmail")
VMAIL_USER = "vmail"
SECRETS_TMP = "/run/whp-agent"
CONFIG_FILE = os.path.join(paths.CONFIG_DIR, "client-backup.conf")

TYPES = ["sftp", "scp", "ftp", "ftps", "onedrive"]
DRIVE_TYPES = ["personal", "business", "documentLibrary"]
PARTS = ["files", "databases", "mail"]
MANIFEST = "whp-backup.json"
FORMAT = "whp-site-backup"
STAMP = re.compile(r"^\d{8}-\d{6}$")
UPLOAD_ID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
DB_NAME = re.compile(r"^[A-Za-z0-9_]{1,64}$")
DB_USER = re.compile(r"^[A-Za-z0-9_.-]{1,80}$")
# ZIP limits for anything we did not write ourselves.
MAX_ENTRIES = 500_000
MAX_RATIO = 250  # uncompressed / compressed per entry, above 10 MB

_mysql = ["mariadb", "--protocol=socket", "-uroot", "--batch", "--skip-column-names"]
_locks = {}
_locks_guard = threading.Lock()


def _config():
    cfg = {"CLIENT_BACKUP_ALLOW_PRIVATE": "no", "CLIENT_BACKUP_MAX_RESTORE_GB": "200"}
    try:
        with open(CONFIG_FILE) as fh:
            for line in fh:
                key, sep, value = line.strip().partition("=")
                if sep and key in cfg:
                    cfg[key] = value.strip().strip('"')
    except FileNotFoundError:
        pass
    return cfg


def allow_private():
    return os.environ.get("WHP_CLIENT_BACKUP_ALLOW_PRIVATE") == "yes" or _config()["CLIENT_BACKUP_ALLOW_PRIVATE"] == "yes"


def max_restore_bytes():
    try:
        return int(_config()["CLIENT_BACKUP_MAX_RESTORE_GB"]) * 1024 ** 3
    except ValueError:
        return 200 * 1024 ** 3


# ------------------------------------------------------------------ helpers

def site_info(domain):
    """User and folder of a website, from the state whp-site keeps."""
    from .. import wordpress
    site = wordpress.site_info(domain)
    if not site.get("user"):
        raise ActionError("not_found", f"Website {domain} has no system user")
    return {"domain": domain, "user": site["user"], "root": site.get("root") or os.path.join(VHOSTS, domain)}


def worker_cmd(user, args):
    """The command that runs site_worker.py as user (tests replace this)."""
    return ["runuser", "-u", user, "--", PYTHON, "-I", WORKER, *args]


class _Lock:
    """One customer backup or restore per website at a time."""

    def __init__(self, domain):
        self.domain = domain

    def __enter__(self):
        d = STATE / "locks"
        d.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.fh = open(d / f"{self.domain}.lock", "w")
        try:
            fcntl.flock(self.fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            self.fh.close()
            raise ActionError("conflict", f"A backup or restore of {self.domain} is already running")
        return self

    def __exit__(self, *exc):
        fcntl.flock(self.fh, fcntl.LOCK_UN)
        self.fh.close()


def _private_dir(prefix):
    base = SECRETS_TMP if os.path.isdir(SECRETS_TMP) else None
    d = tempfile.mkdtemp(prefix=prefix, dir=base)
    os.chmod(d, 0o700)
    return d


def _write_private(path, content):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as fh:
        fh.write(content)


def _stamp(t=None):
    return time.strftime("%Y%m%d-%H%M%S", time.gmtime(t if t is not None else time.time()))


def _iso(stamp):
    return f"{stamp[0:4]}-{stamp[4:6]}-{stamp[6:8]}T{stamp[9:11]}:{stamp[11:13]}:{stamp[13:15]}Z"


def parse_name(domain, name):
    """{file, kind, created, base} for a backup file name of this domain, else None."""
    m = re.fullmatch(re.escape(domain) + r"-(\d{8}-\d{6})-(full|incr-(\d{8}-\d{6}))\.zip", name or "")
    if not m:
        return None
    kind = "full" if m.group(2) == "full" else "incremental"
    base = f"{domain}-{m.group(3)}-full.zip" if kind == "incremental" else None
    return {"file": name, "kind": kind, "stamp": m.group(1), "created": _iso(m.group(1)), "base": base}


# ------------------------------------------------------------------ targets

def _clean_path(value):
    value = (value or "").strip().strip("/")
    if value == "":
        return ""
    if len(value) > 300 or not re.fullmatch(r"[A-Za-z0-9._ ~@+/-]+", value) or ".." in value.split("/"):
        raise ActionError("invalid_params", "target.path has an invalid format")
    return value


def parse_target(params, key="target"):
    t = params.get(key)
    if not isinstance(t, dict):
        raise ActionError("invalid_params", f"{key} must be an object")
    kind = v.choice(t, "type", TYPES)
    if kind == "onedrive":
        return _parse_onedrive(t, key)
    target = {
        "type": kind,
        "host": v.string(t, "host", max_len=253, pattern=r"[A-Za-z0-9.-]+|\[?[0-9A-Fa-f:]+\]?").strip("[]"),
        "port": v.integer(t, "port", required=False, minimum=1, maximum=65535),
        "user": v.string(t, "user", max_len=128, pattern=r"[A-Za-z0-9._@+-]+"),
        "password": t.get("password") or None,
        "ssh_key": t.get("ssh_key") or None,
        "host_key": t.get("host_key") or None,
        "tls": v.choice(t, "tls", ["explicit", "implicit"], required=False, default="explicit"),
        "path": _clean_path(t.get("path")),
        "allow_plain_ftp": v.boolean(t, "allow_plain_ftp", required=False, default=False),
    }
    for k in ("password", "ssh_key", "host_key"):
        value = target[k]
        if value is not None and (not isinstance(value, str) or len(value) > 16384 or "\x00" in value):
            raise ActionError("invalid_params", f"{key}.{k} is invalid")
    if target["password"] and ("\n" in target["password"] or len(target["password"]) > 1000):
        raise ActionError("invalid_params", f"{key}.password is invalid")
    if kind in ("ftp", "ftps") and not target["password"]:
        raise ActionError("invalid_params", "FTP needs a password")
    if kind == "ftp" and not target["allow_plain_ftp"]:
        raise ActionError("invalid_params", "Plain FTP sends the password unencrypted; use FTPS or allow plain FTP explicitly")
    if kind in ("sftp", "scp") and not (target["password"] or target["ssh_key"]):
        raise ActionError("invalid_params", "SFTP needs a password or an SSH key")
    if target["port"] is None:
        target["port"] = 22 if kind in ("sftp", "scp") else (990 if kind == "ftps" and target["tls"] == "implicit" else 21)
    return target


def _parse_onedrive(t, key):
    """A OneDrive location: an OAuth token from the panel's Microsoft app (rclone format)."""
    token = t.get("token")
    if isinstance(token, str):
        try:
            token = json.loads(token)
        except ValueError:
            token = None
    if not isinstance(token, dict) or not isinstance(token.get("refresh_token"), str) or not token.get("access_token"):
        raise ActionError("invalid_params", f"{key}.token must be a OneDrive token with a refresh token")
    target = {
        "type": "onedrive",
        "id": v.integer(t, "id", required=False, minimum=1, maximum=2 ** 31),
        "client_id": v.string(t, "client_id", required=False, max_len=100, pattern=r"[A-Za-z0-9-]+"),
        "client_secret": t.get("client_secret") or None,
        "token": {k: token[k] for k in ("access_token", "token_type", "refresh_token", "expiry") if k in token},
        "drive_id": v.string(t, "drive_id", max_len=200, pattern=r"[A-Za-z0-9!._-]+"),
        "drive_type": v.choice(t, "drive_type", DRIVE_TYPES),
        "path": _clean_path(t.get("path")),
    }
    secret = target["client_secret"]
    if secret is not None and (not isinstance(secret, str) or len(secret) > 500 or re.search(r"[\x00-\x1f]", secret)):
        raise ActionError("invalid_params", f"{key}.client_secret is invalid")
    if len(json.dumps(target["token"])) > 16384:
        raise ActionError("invalid_params", f"{key}.token is too large")
    return target


def _token_file(target):
    return STATE / "onedrive" / f"t{target['id']}.json"


def _token_for(target):
    """rclone renews the token while it works; use the renewed one we kept, unless the panel has a new connection."""
    if not target.get("id"):
        return target["token"]
    try:
        with open(_token_file(target)) as fh:
            kept = json.load(fh)
    except (FileNotFoundError, ValueError):
        return target["token"]
    if kept.get("for") == _fingerprint(target["token"]) and isinstance(kept.get("token"), dict):
        return kept["token"]
    return target["token"]


def _fingerprint(token):
    return hashlib.sha256(token["refresh_token"].encode()).hexdigest()


def _keep_token(target, token):
    if not target.get("id"):
        return
    d = STATE / "onedrive"
    d.mkdir(parents=True, exist_ok=True, mode=0o700)
    tmp = d / f".t{target['id']}.tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as fh:
        json.dump({"for": _fingerprint(target["token"]), "token": token}, fh)
    os.replace(tmp, _token_file(target))


def resolve_host(host):
    """The address to connect to. Refuses private and local addresses (unless allowed)."""
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
                raise ActionError("forbidden", f"{host} points to a private or local address ({ip}); that is not allowed as a backup location")
    return str(addrs[0])


class Remote:
    """An rclone remote for one call: config in a private temp folder."""

    def __init__(self, target, domain):
        self.t = target
        self.domain = domain

    def __enter__(self):
        self.dir = _private_dir("cbackup-")
        try:
            self._configure()
        except Exception:
            shutil.rmtree(self.dir, ignore_errors=True)
            raise
        return self

    def __exit__(self, *exc):
        try:
            if self.t["type"] == "onedrive":
                self._collect_token()
        finally:
            shutil.rmtree(self.dir, ignore_errors=True)

    def _collect_token(self):
        """rclone writes a renewed token back into its config; keep it for the next run."""
        self.renewed_token = None
        try:
            with open(self.conf) as fh:
                for line in fh:
                    if line.startswith("token = "):
                        token = json.loads(line[len("token = "):])
                        break
                else:
                    return
        except (OSError, ValueError):
            return
        if isinstance(token, dict) and token.get("refresh_token") and token != self.written_token:
            _keep_token(self.t, token)
            self.renewed_token = token

    def _obscure(self, secret):
        return run([RCLONE, "obscure", "-"], input_text=secret, timeout=30).stdout.strip()

    def _configure(self):
        t = self.t
        self.conf = os.path.join(self.dir, "rclone.conf")
        if t["type"] == "onedrive":
            self.written_token = _token_for(t)
            lines = ["[remote]", "type = onedrive", f"token = {json.dumps(self.written_token, separators=(',', ':'))}",
                     f"drive_id = {t['drive_id']}", f"drive_type = {t['drive_type']}"]
            if t.get("client_id"):
                lines.append(f"client_id = {t['client_id']}")
            if t.get("client_secret"):
                lines.append(f"client_secret = {t['client_secret']}")
            _write_private(self.conf, "\n".join(lines) + "\n")
            return
        address = resolve_host(t["host"])
        lines = ["[remote]"]
        if t["type"] in ("sftp", "scp"):
            # Connect to the address we checked (no second DNS lookup). The host key is pinned.
            if not t.get("host_key"):
                raise ActionError("invalid_params", "The SSH host key of the backup location is not known yet; test the location first")
            known = os.path.join(self.dir, "known_hosts")
            _write_private(known, _known_hosts_for(t["host_key"], address, t["port"]))
            lines += ["type = sftp", f"host = {address}", f"port = {t['port']}", f"user = {t['user']}",
                      f"known_hosts_file = {known}", "shell_type = none", "disable_hashcheck = true"]
            if t.get("ssh_key"):
                key = os.path.join(self.dir, "id_key")
                _write_private(key, t["ssh_key"].strip() + "\n")
                lines.append(f"key_file = {key}")
            if t.get("password"):
                lines.append(f"pass = {self._obscure(t['password'])}")
        else:
            # FTPS checks the certificate against the host name, so keep the name there.
            host = t["host"] if t["type"] == "ftps" else address
            lines += ["type = ftp", f"host = {host}", f"port = {t['port']}", f"user = {t['user']}",
                      f"pass = {self._obscure(t['password'])}"]
            if t["type"] == "ftps":
                lines.append("explicit_tls = true" if t["tls"] == "explicit" else "tls = true")
        _write_private(self.conf, "\n".join(lines) + "\n")

    def path(self, name=""):
        parts = [p for p in (self.t["path"], self.domain, name) if p]
        return "remote:" + "/".join(parts)

    def argv(self, *args):
        return [RCLONE, "--config", self.conf, "--contimeout", "20s", "--timeout", "5m",
                "--low-level-retries", "3", "--retries", "1", "--use-mmap", *args]

    def env(self):
        return {**SAFE_ENV, "HOME": self.dir, "RCLONE_CACHE_DIR": os.path.join(self.dir, "cache")}

    def rclone(self, *args, timeout=300, check=True, input_text=None):
        proc = run(self.argv(*args), timeout=timeout, check=False, env={"HOME": self.dir}, input_text=input_text)
        if check and proc.returncode != 0:
            raise ActionError("command_failed", _rclone_error(proc.stderr), {"stderr": proc.stderr[-1500:]})
        return proc

    def list(self):
        proc = self.rclone("lsjson", "--files-only", self.path(), timeout=120, check=False)
        if proc.returncode != 0:
            if "directory not found" in proc.stderr.lower():
                return []
            raise ActionError("command_failed", _rclone_error(proc.stderr), {"stderr": proc.stderr[-1500:]})
        try:
            rows = json.loads(proc.stdout or "[]")
        except ValueError:
            raise ActionError("command_failed", "rclone returned no file list")
        out = []
        for row in rows:
            meta = parse_name(self.domain, row.get("Name"))
            if meta:
                meta["size"] = int(row.get("Size") or 0)
                out.append(meta)
        return sorted(out, key=lambda m: m["stamp"], reverse=True)

    def upload(self, name):
        """Start `rclone rcat` for name; write the file to .stdin and call finish()."""
        self.rclone("mkdir", self.path(), timeout=120)
        return subprocess.Popen(self.argv("rcat", self.path(name)), stdin=subprocess.PIPE,
                                stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, env=self.env())

    def download(self, name, dest):
        self.rclone("copyto", self.path(name), dest, timeout=24 * 3600)

    def delete(self, name):
        return self.rclone("deletefile", self.path(name), timeout=120, check=False).returncode == 0


def _rclone_error(stderr):
    for line in reversed((stderr or "").strip().splitlines()):
        if "ERROR" in line or "Failed" in line or "error" in line:
            return line.split(":", 3)[-1].strip()[:300] or "The backup location returned an error"
    return "The backup location returned an error"


def _known_hosts_for(host_key, address, port):
    """Pinned key lines, rewritten for the address we connect to."""
    out = []
    for line in host_key.splitlines():
        parts = line.strip().split()
        if len(parts) >= 3 and not line.startswith("#"):
            key_type, key = parts[-2], parts[-1]
            if re.fullmatch(r"(ssh|ecdsa|sk)-[A-Za-z0-9@.-]+", key_type) and re.fullmatch(r"[A-Za-z0-9+/=]+", key):
                host = address if port == 22 else f"[{address}]:{port}"
                out.append(f"{host} {key_type} {key}")
    if not out:
        raise ActionError("invalid_params", "The pinned SSH host key is not valid")
    return "\n".join(out) + "\n"


def keyscan(host, port):
    address = resolve_host(host)
    proc = run(["ssh-keyscan", "-p", str(port), "-T", "10", "-t", "ed25519,ecdsa,rsa", address], timeout=30, check=False)
    lines = [ln for ln in proc.stdout.splitlines() if ln and not ln.startswith("#")]
    if not lines:
        raise ActionError("command_failed", f"No SSH host key received from {host}:{port}")
    # Store with the host name; the address is filled in on every connection.
    return "\n".join(f"{host} {' '.join(ln.split()[1:3])}" for ln in lines)


def host_key_fingerprints(host_key):
    out = []
    for line in host_key.splitlines():
        parts = line.split()
        if len(parts) >= 3:
            import base64
            import hashlib
            try:
                raw = base64.b64decode(parts[2])
            except ValueError:
                continue
            fp = base64.b64encode(hashlib.sha256(raw).digest()).decode().rstrip("=")
            out.append(f"{parts[1]} SHA256:{fp}")
    return out


# ------------------------------------------------------------------ state

def _state_file(domain, key):
    return STATE / domain / f"{key}.json"


def _load_state(domain, key):
    try:
        with open(_state_file(domain, key)) as fh:
            return json.load(fh)
    except (FileNotFoundError, ValueError):
        return None


def _save_state(domain, key, data):
    d = STATE / domain
    d.mkdir(parents=True, exist_ok=True, mode=0o700)
    tmp = d / f".{key}.tmp"
    with open(tmp, "w") as fh:
        json.dump(data, fh)
    os.replace(tmp, _state_file(domain, key))


# ------------------------------------------------------------------ sources

def _sources(domain, parts):
    """[(prefix, user, root)] of what a backup of domain contains."""
    out = []
    if "files" in parts:
        site = site_info(domain)
        out.append(("files", site["user"], site["root"]))
    if "mail" in parts:
        root = os.path.join(VMAIL, domain)
        if os.path.isdir(root) and not os.path.islink(root):
            out.append(("mail", VMAIL_USER, root))
    return out


def _scan(user, root, excludes=None):
    args = ["scan", root] + _exclude_args(excludes)
    proc = run(worker_cmd(user, args), timeout=3600, check=False)
    if proc.returncode != 0:
        raise ActionError("command_failed", f"Could not read {root}", {"stderr": proc.stderr[-1500:]})
    entries = {}
    for line in proc.stdout.splitlines():
        try:
            e = json.loads(line)
        except ValueError:
            continue
        entries[e["p"]] = [e["t"], e["s"], e["m"]]
    return entries


def _exclude_args(excludes):
    if excludes is None:
        return []
    args = ["--exclude", ""]  # an empty value replaces the defaults
    for e in excludes:
        args += ["--exclude", e]
    return args


class _Counter:
    """Write-only stream that counts bytes (ZipFile wraps it, as it cannot seek)."""

    def __init__(self, fh):
        self.fh, self.n = fh, 0

    def write(self, data):
        self.fh.write(data)
        self.n += len(data)
        return len(data)

    def flush(self):
        self.fh.flush()


def _zip_time(mtime):
    t = time.localtime(max(mtime, 315532800))  # zip cannot store dates before 1980
    return t[:6]


def _add_tar_stream(zf, prefix, tar_stream):
    """Copy a tar stream (from site_worker pack) into zip entries under prefix/."""
    n = 0
    with tarfile.open(fileobj=tar_stream, mode="r|") as tar:
        for m in tar:
            name = f"{prefix}/{m.name}"
            if m.isdir():
                zi = zipfile.ZipInfo(name + "/", _zip_time(m.mtime))
                zi.external_attr = ((stat.S_IFDIR | (m.mode & 0o7777)) << 16) | 0x10
                zf.writestr(zi, b"")
            elif m.issym():
                zi = zipfile.ZipInfo(name, _zip_time(m.mtime))
                zi.external_attr = (stat.S_IFLNK | 0o777) << 16
                zi.create_system = 3
                zf.writestr(zi, m.linkname.encode("utf-8", "surrogateescape"))
            elif m.isreg():
                zi = zipfile.ZipInfo(name, _zip_time(m.mtime))
                zi.external_attr = (stat.S_IFREG | (m.mode & 0o7777)) << 16
                zi.create_system = 3
                zi.compress_type = zipfile.ZIP_DEFLATED
                zi.file_size = m.size
                src = tar.extractfile(m)
                with zf.open(zi, "w", force_zip64=m.size > 2 ** 31) as dst:
                    shutil.copyfileobj(src, dst, 1024 * 1024)
            else:
                continue
            n += 1
    return n


def _dump_db(zf, name):
    zi = zipfile.ZipInfo(f"databases/{name}.sql", time.localtime()[:6])
    zi.compress_type = zipfile.ZIP_DEFLATED
    zi.external_attr = (stat.S_IFREG | 0o600) << 16
    proc = subprocess.Popen(
        ["mariadb-dump", "--protocol=socket", "-uroot", "--single-transaction", "--quick", "--routines",
         "--triggers", "--events", "--hex-blob", "--default-character-set=utf8mb4", "--", name],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=SAFE_ENV)
    with zf.open(zi, "w", force_zip64=True) as dst:
        shutil.copyfileobj(proc.stdout, dst, 1024 * 1024)
    err = proc.stderr.read().decode(errors="replace")
    if proc.wait() != 0:
        raise ActionError("command_failed", f"Could not export database {name}", {"stderr": err[-1500:]})


def make_backup(domain, target, target_key, kind, parts, databases, keep_full, log, prune=True):
    """Write one backup zip of domain to target. Returns what was written."""
    sources = _sources(domain, parts)
    db_names = [d["name"] for d in databases] if "databases" in parts else []
    now = _stamp()
    state = _load_state(domain, target_key)
    with Remote(target, domain) as remote:
        existing = {m["file"] for m in remote.list()}
        base = None
        if kind == "incremental":
            if state and state.get("file") in existing:
                base = state
            else:
                log("No full backup on this location yet: making a full backup instead")
                kind = "full"
        listings = {}
        for prefix, user, root in sources:
            log(f"Reading {prefix}")
            listings[prefix] = _scan(user, root)
        name = f"{domain}-{now}-full.zip" if kind == "full" else f"{domain}-{now}-incr-{base['stamp']}.zip"
        manifest = {"format": FORMAT, "version": 1, "domain": domain, "kind": kind, "created": _iso(now),
                    "base": base["file"] if base else None, "parts": [], "databases": db_names, "sources": {}}
        log(f"Writing {name}")
        proc = remote.upload(name)
        counter = _Counter(proc.stdin)
        total_files = 0
        try:
            with zipfile.ZipFile(counter, "w", zipfile.ZIP_DEFLATED, compresslevel=6, allowZip64=True) as zf:
                for prefix, user, root in sources:
                    current = listings[prefix]
                    if base:
                        old = base.get("sources", {}).get(prefix, {})
                        wanted = [p for p, e in current.items() if old.get(p) != e]
                        deleted = sorted(p for p in old if p not in current)
                    else:
                        wanted, deleted = list(current), []
                    pack = subprocess.Popen(worker_cmd(user, ["pack", root]), stdin=subprocess.PIPE,
                                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=SAFE_ENV)
                    feeder = threading.Thread(target=_feed, args=(pack.stdin, wanted), daemon=True)
                    feeder.start()
                    count = _add_tar_stream(zf, prefix, pack.stdout)
                    feeder.join()
                    err = pack.stderr.read().decode(errors="replace")
                    pack.stdout.close()
                    pack.stderr.close()
                    if pack.wait() != 0:
                        raise ActionError("command_failed", f"Could not pack {prefix}", {"stderr": err[-1500:]})
                    total_files += count
                    src = {"entries": count}
                    if base:
                        src["present"] = sorted(current)
                        src["deleted"] = deleted
                    manifest["sources"][prefix] = src
                    manifest["parts"].append(prefix)
                    log(f"{prefix}: {count} entries")
                for db in db_names:
                    log(f"Exporting database {db}")
                    _dump_db(zf, db)
                if db_names:
                    manifest["parts"].append("databases")
                zf.writestr(MANIFEST, json.dumps(manifest, indent=1))
            proc.stdin.close()
            err = proc.stderr.read().decode(errors="replace")
            if proc.wait() != 0:
                raise ActionError("command_failed", _rclone_error(err), {"stderr": err[-1500:]})
        except BaseException:
            try:
                proc.kill()
                proc.wait(timeout=30)
            except Exception:
                pass
            remote.delete(name)  # no half written backups on the location
            raise
        if kind == "full":
            _save_state(domain, target_key, {"file": name, "stamp": now, "sources": listings})
        removed = prune_remote(remote, keep_full, log) if prune else []
    log(f"Done: {name} ({counter.n} bytes)")
    return {"file": name, "kind": kind, "base": manifest["base"], "size": counter.n, "entries": total_files,
            "databases": db_names, "parts": manifest["parts"], "removed": removed, "created": _iso(now)}


def _feed(stream, rels):
    try:
        for rel in rels:
            stream.write(rel.encode("utf-8", "surrogateescape") + b"\x00")
    except BrokenPipeError:
        pass
    finally:
        try:
            stream.close()
        except BrokenPipeError:
            pass


def prune_remote(remote, keep_full, log):
    """Keep the newest keep_full full backups and the incrementals that build on them."""
    if not keep_full:
        return []
    backups = remote.list()
    fulls = [b["file"] for b in backups if b["kind"] == "full"]
    keep = set(fulls[:keep_full])
    removed = []
    for b in backups:
        drop = (b["kind"] == "full" and b["file"] not in keep) or (b["kind"] == "incremental" and b["base"] not in keep)
        if drop and remote.delete(b["file"]):
            removed.append(b["file"])
            log(f"Removed old backup {b['file']}")
    return removed


# ------------------------------------------------------------------ reading archives

def open_archive(path, limit=None):
    """Open and check a backup zip. Returns (ZipFile, manifest)."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    st = os.fstat(fd)
    if not stat.S_ISREG(st.st_mode):
        os.close(fd)
        raise ActionError("invalid_params", "The backup is not a regular file")
    fh = os.fdopen(fd, "rb")
    try:
        zf = zipfile.ZipFile(fh)
        zf._filePassed = 0  # close() closes our file handle too
    except zipfile.BadZipFile:
        fh.close()
        raise ActionError("invalid_params", "This is not a valid zip file")
    try:
        manifest = _check_archive(zf, limit if limit is not None else max_restore_bytes())
    except Exception:
        zf.close()
        raise
    return zf, manifest


def _check_archive(zf, limit):
    infos = zf.infolist()
    if len(infos) > MAX_ENTRIES:
        raise ActionError("invalid_params", "The zip file has too many entries")
    total = 0
    for zi in infos:
        name = zi.filename
        if name == MANIFEST:
            continue
        top, _, rel = name.partition("/")
        rel = rel.rstrip("/")
        if top not in ("files", "mail", "databases") or not rel:
            if name.rstrip("/") in ("files", "mail", "databases"):
                continue
            raise ActionError("invalid_params", f"Unexpected entry in the backup: {name[:200]}")
        if name.startswith("/") or "\\" in name or "\x00" in name or any(p in ("", ".", "..") for p in rel.split("/")):
            raise ActionError("invalid_params", f"Unsafe path in the backup: {name[:200]}")
        if top == "databases" and not re.fullmatch(r"[A-Za-z0-9_]{1,64}\.sql", rel):
            raise ActionError("invalid_params", f"Unexpected database file: {name[:200]}")
        if zi.flag_bits & 0x1:
            raise ActionError("invalid_params", "Password protected zip files are not supported")
        if zi.file_size > 10 * 1024 ** 2 and zi.file_size > MAX_RATIO * max(zi.compress_size, 1):
            raise ActionError("invalid_params", f"Suspicious compression ratio for {name[:200]}")
        total += zi.file_size
    if total > limit:
        raise ActionError("invalid_params", f"The backup unpacks to {total // 1024 ** 2} MB, more than the allowed {limit // 1024 ** 2} MB")
    try:
        raw = zf.read(MANIFEST)
    except KeyError:
        raise ActionError("invalid_params", "This zip file is not a backup made by the panel (whp-backup.json is missing). "
                                            "Use the file manager to unpack a plain zip file.")
    if len(raw) > 64 * 1024 ** 2:
        raise ActionError("invalid_params", "The backup manifest is too large")
    try:
        manifest = json.loads(raw)
    except ValueError:
        raise ActionError("invalid_params", "The backup manifest is not valid JSON")
    if not isinstance(manifest, dict) or manifest.get("format") != FORMAT or manifest.get("kind") not in ("full", "incremental"):
        raise ActionError("invalid_params", "The backup manifest is not from the panel")
    manifest["size"] = total
    manifest["contains"] = sorted({zi.filename.split("/", 1)[0] for zi in infos if zi.filename != MANIFEST})
    manifest["database_files"] = sorted(zi.filename[10:-4] for zi in infos
                                        if zi.filename.startswith("databases/") and zi.filename.endswith(".sql"))
    return manifest


def summary(manifest):
    return {k: manifest.get(k) for k in ("domain", "kind", "created", "base", "size", "contains", "database_files")}


# ------------------------------------------------------------------ restoring

def _entries(zf, prefix):
    for zi in zf.infolist():
        if zi.filename.startswith(prefix + "/") and zi.filename.rstrip("/") != prefix:
            yield zi.filename[len(prefix) + 1:].rstrip("/"), zi


def _tar_member(rel, zi, zf):
    mode = (zi.external_attr >> 16) & 0o177777
    ti = tarfile.TarInfo(rel)
    ti.mtime = time.mktime(zi.date_time + (0, 0, -1))
    if zi.is_dir() or stat.S_ISDIR(mode):
        ti.type = tarfile.DIRTYPE
        ti.mode = (mode & 0o777) or 0o755
        return ti, None
    if stat.S_ISLNK(mode):
        ti.type = tarfile.SYMTYPE
        ti.linkname = zf.read(zi).decode("utf-8", "surrogateescape")[:4096]
        ti.mode = 0o777
        return ti, None
    ti.type = tarfile.REGTYPE
    ti.mode = (mode & 0o777) or 0o644
    ti.size = zi.file_size
    return ti, zf.open(zi)


def _unpack(user, root, members):
    """Stream (rel, zi, zf) members as tar into site_worker unpack, as user."""
    proc = subprocess.Popen(worker_cmd(user, ["unpack", root]), stdin=subprocess.PIPE,
                            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, env=SAFE_ENV)
    errbuf = []
    reader = threading.Thread(target=lambda: errbuf.append(proc.stderr.read()), daemon=True)
    reader.start()
    n = 0
    try:
        with tarfile.open(fileobj=proc.stdin, mode="w|", format=tarfile.PAX_FORMAT) as tar:
            for rel, zi, zf in members:
                ti, src = _tar_member(rel, zi, zf)
                tar.addfile(ti, src)
                if src:
                    src.close()
                n += 1
        proc.stdin.close()
    except BrokenPipeError:
        pass
    rc = proc.wait()
    reader.join()
    err = (errbuf[0] if errbuf else b"").decode(errors="replace")
    if rc != 0:
        raise ActionError("command_failed", f"Could not restore files into {root}", {"stderr": err[-1500:]})
    return n


def _prune(user, root, keep):
    proc = subprocess.run(worker_cmd(user, ["prune", root]), input=b"".join(k.encode("utf-8", "surrogateescape") + b"\x00" for k in keep),
                          capture_output=True, env=SAFE_ENV, timeout=3600)
    if proc.returncode != 0:
        raise ActionError("command_failed", f"Could not clean up {root}", {"stderr": proc.stderr.decode(errors="replace")[-1500:]})
    try:
        return json.loads(proc.stderr.decode().strip().splitlines()[-1]).get("removed", 0)
    except (ValueError, IndexError, AttributeError):
        return 0


def _restore_files(prefix, user, root, archives, log):
    """archives: [(zf, manifest)] = [full] or [full, incremental]."""
    last_zf, last = archives[-1]
    if last["kind"] == "incremental":
        base_zf, _ = archives[0]
        src = last.get("sources", {}).get(prefix, {})
        present = set(src.get("present") or [])
        newer = {rel for rel, _ in _entries(last_zf, prefix)}
        members = [(rel, zi, base_zf) for rel, zi in _entries(base_zf, prefix) if rel in present and rel not in newer]
        members += [(rel, zi, last_zf) for rel, zi in _entries(last_zf, prefix)]
        keep = present
    else:
        members = [(rel, zi, last_zf) for rel, zi in _entries(last_zf, prefix)]
        keep = {rel for rel, _, _ in members}
    if prefix == "mail":
        # Mail is added, never mirrored: mail that arrived after the backup stays.
        # Skip messages that are already there (Maildir names only change after the ':').
        have = {_mail_key(p) for p, e in _scan(user, root).items() if e[0] == "f"}
        members = [m for m in members if m[1].is_dir() or _mail_key(m[0]) not in have]
        n = _unpack(user, root, members)
        log(f"mail: {n} entries put back")
        return {"entries": n, "removed": 0}
    n = _unpack(user, root, members)
    removed = _prune(user, root, keep)
    log(f"{prefix}: {n} entries restored, {removed} newer entries removed")
    return {"entries": n, "removed": removed}


def _mail_key(rel):
    folder, _, name = rel.rpartition("/")
    if folder.endswith("/cur") or folder.endswith("/new") or folder in ("cur", "new"):
        folder = folder.rsplit("/", 1)[0] if "/" in folder else ""
        return folder + "|" + name.split(":", 1)[0]
    return rel


_DEFINER = re.compile(rb"DEFINER\s*=\s*(`[^`]*`|'[^']*'|[A-Za-z0-9_]+)\s*@\s*(`[^`]*`|'[^']*'|[A-Za-z0-9_.%-]+)\s*", re.I)
_CREATED = re.compile(rb"^CREATE\s+(?:TABLE|(?:ALGORITHM=\w+\s+)?(?:/\*!\d+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW)\s+(?:IF NOT EXISTS\s+)?`((?:[^`]|``)+)`", re.I)
_VIEW_IN_COMMENT = re.compile(rb"VIEW\s+`((?:[^`]|``)+)`", re.I)


def strip_definer(line):
    return _DEFINER.sub(b"", line)


def _q(name):
    return "`" + name.replace("`", "``") + "`"


def _user_statement(user):
    out = run(_mysql, input_text=f"SHOW CREATE USER '{user}'@'localhost';\n", timeout=30, check=False)
    if out.returncode != 0 or not out.stdout.strip():
        raise ActionError("not_found", f"MariaDB user {user} does not exist")
    return out.stdout.strip().split("\n")[0]


def _restore_database(zf, entry, db, owner, log):
    """Import a dump as the database's own user; its password is put back afterwards."""
    if not DB_NAME.fullmatch(db) or not DB_USER.fullmatch(owner):
        raise ActionError("invalid_params", "Invalid database or user name")
    original = _user_statement(owner)
    if not original.upper().startswith("CREATE USER"):
        raise ActionError("command_failed", f"Unexpected answer for user {owner}")
    tmp_pw = secrets.token_urlsafe(24).replace("-", "a").replace("_", "b")
    d = _private_dir("dbrestore-")
    try:
        run(_mysql, input_text=f"CREATE DATABASE IF NOT EXISTS {_q(db)} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
                               f"GRANT ALL PRIVILEGES ON {_q(db)}.* TO '{owner}'@'localhost';\n"
                               f"ALTER USER '{owner}'@'localhost' IDENTIFIED BY '{tmp_pw}';\n", timeout=60)
        cnf = os.path.join(d, "client.cnf")
        _write_private(cnf, f"[client]\nuser={owner}\npassword={tmp_pw}\n")
        proc = subprocess.Popen(["mariadb", f"--defaults-extra-file={cnf}", "--protocol=socket",
                                 "--default-character-set=utf8mb4", "--binary-mode", db],
                                stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, env=SAFE_ENV)
        created = set()
        errbuf = []
        reader = threading.Thread(target=lambda: errbuf.append(proc.stderr.read()), daemon=True)
        reader.start()
        try:
            with zf.open(entry) as src:
                for line in src:
                    if b"DEFINER" in line:
                        line = strip_definer(line)
                    m = _CREATED.match(line) or (_VIEW_IN_COMMENT.search(line) if line.startswith(b"/*!50001 VIEW") else None)
                    if m:
                        created.add(m.group(1).replace(b"``", b"`").decode("utf-8", "replace"))
                    proc.stdin.write(line)
            proc.stdin.close()
        except BrokenPipeError:
            pass
        rc = proc.wait()
        reader.join()
        if rc != 0:
            err = (errbuf[0] if errbuf else b"").decode(errors="replace")
            raise ActionError("command_failed", f"Import of database {db} failed: {err.strip()[-300:]}", {"stderr": err[-1500:]})
        # Tables that did not exist at backup time are removed, so the database matches it.
        tables = run(_mysql, input_text=f"SELECT table_name FROM information_schema.tables WHERE table_schema = '{db}';\n",
                     timeout=60).stdout.split("\n")
        extra = [t for t in tables if t and t not in created]
        if extra:
            run(_mysql, input_text="SET FOREIGN_KEY_CHECKS=0;\n" + "".join(f"DROP TABLE IF EXISTS {_q(db)}.{_q(t)};\n" for t in extra), timeout=300)
        log(f"database {db}: imported" + (f", {len(extra)} newer tables removed" if extra else ""))
        return {"database": db, "removed_tables": len(extra)}
    finally:
        # Give the site its own password back, whatever happened.
        run(_mysql, input_text="ALTER" + original[len("CREATE"):] + ";\n", timeout=60, check=False)
        shutil.rmtree(d, ignore_errors=True)


def _db_plan(manifest, databases):
    """Which dump goes into which database of the site: same name, or the only one onto the only one."""
    owned = {d["name"]: d.get("user") or d["name"] for d in databases}
    files = manifest.get("database_files") or []
    plan, skipped = [], []
    for f in files:
        if f in owned:
            plan.append((f, f, owned[f]))
    if not plan and len(files) == 1 and len(owned) == 1:
        target = next(iter(owned))
        plan.append((files[0], target, owned[target]))
    for f in files:
        if f not in {p[0] for p in plan}:
            skipped.append(f)
    return plan, skipped


def restore(domain, archives, parts, databases, log):
    """Put archives ([full] or [full, incremental]) back on domain."""
    last = archives[-1][1]
    if last.get("domain") != domain:
        log(f"Note: this backup was made of {last.get('domain')}, it is restored onto {domain}")
    result = {"domain": domain, "backup": summary(last), "parts": {}, "skipped_databases": []}
    for prefix, user, root in _sources(domain, [p for p in parts if p in ("files", "mail")]):
        if prefix not in last.get("contains", []) and prefix not in last.get("parts", []):
            continue
        log(f"Restoring {prefix}")
        result["parts"][prefix] = _restore_files(prefix, user, root, archives, log)
    if "databases" in parts:
        plan, skipped = _db_plan(last, databases)
        result["skipped_databases"] = skipped
        for name in skipped:
            log(f"Skipped database {name}: it does not belong to {domain}")
        zf = archives[-1][0]
        result["parts"]["databases"] = [_restore_database(zf, f"databases/{src}.sql", target, owner, log)
                                        for src, target, owner in plan]
    return result


def _staging():
    d = STATE / "staging" / uuid.uuid4().hex
    d.mkdir(parents=True, mode=0o700)
    return d


def _download(remote, name, staging, log):
    sizes = {b["file"]: b["size"] for b in remote.list()}
    if name not in sizes:
        raise ActionError("not_found", f"Backup {name} is not on the backup location")
    free = shutil.disk_usage(staging).free
    if sizes[name] * 1.1 + 1024 ** 3 > free:
        raise ActionError("conflict", "Not enough free disk space on the server to download this backup")
    log(f"Downloading {name}")
    dest = staging / name
    remote.download(name, str(dest))
    return dest


# ------------------------------------------------------------------ params

def _domain(params):
    return v.domain(params, "domain")


def _target_key(params):
    return "t" + str(v.integer(params, "target_id", minimum=1, maximum=2 ** 31))


def _parts(params):
    parts = v.list_of(params, "parts", lambda p: v.choice({"p": p}, "p", PARTS), required=False, max_items=3) or PARTS
    return list(dict.fromkeys(parts))


def _databases(params):
    out = []
    for i, d in enumerate(v.list_of(params, "databases", lambda x: x, required=False, max_items=100) or []):
        if not isinstance(d, dict):
            raise ActionError("invalid_params", f"databases[{i}] must be an object")
        name = v.string(d, "name", max_len=64, pattern=DB_NAME.pattern[1:-1])
        user = v.string(d, "user", required=False, max_len=80, pattern=DB_USER.pattern[1:-1])
        out.append({"name": name, "user": user or name})
    # Databases the agent created for this site are always included.
    known = {d["name"] for d in out}
    for name in dbs.owned_by(params["domain"]) if "domain" in params else []:
        if name not in known:
            out.append({"name": name, "user": dbs._load().get(name, {}).get("user", name)})
    return out


def _file(params, domain):
    name = v.string(params, "file", max_len=400)
    if not parse_name(domain, name):
        raise ActionError("invalid_params", "file is not a backup of this website")
    return name


def _upload_path(params, key="upload"):
    upload = v.string(params, key, max_len=36, pattern=UPLOAD_ID.pattern[1:-1])
    path = os.path.join(UPLOAD_DIR, f"{upload}.zip")
    if not os.path.lexists(path):
        raise ActionError("not_found", "The uploaded file was not found")
    return path


# ------------------------------------------------------------------ actions

@action("client_backup.target_test")
def client_backup_target_test(params):
    """Check that the location can be reached and written. Returns the SSH host key to pin."""
    target = parse_target(params)
    domain = _domain(params)
    started = time.time()
    host_key = target.get("host_key")
    if target["type"] in ("sftp", "scp") and not host_key:
        host_key = keyscan(target["host"], target["port"])
        target["host_key"] = host_key
    with Remote(target, domain) as remote:
        remote.rclone("mkdir", remote.path(), timeout=120)
        probe = f".whp-write-test-{secrets.token_hex(4)}"
        remote.rclone("rcat", remote.path(probe), input_text="ok\n", timeout=120)
        remote.delete(probe)
        backups = remote.list()
    return {"reachable": True, "writable": True, "duration_ms": int((time.time() - started) * 1000),
            "token": getattr(remote, "renewed_token", None), "host_key": host_key, "fingerprints": host_key_fingerprints(host_key) if host_key else [],
            "backups": len(backups)}


@action("client_backup.list")
def client_backup_list(params):
    domain = _domain(params)
    with Remote(parse_target(params), domain) as remote:
        return {"backups": remote.list()}


@action("client_backup.delete")
def client_backup_delete(params):
    domain = _domain(params)
    name = _file(params, domain)
    with Remote(parse_target(params), domain) as remote:
        if not remote.delete(name):
            raise ActionError("command_failed", f"Could not delete {name}")
    return {"deleted": name}


@action("client_backup.run")
def client_backup_run(params):
    domain = _domain(params)
    target = parse_target(params)
    key = _target_key(params)
    kind = v.choice(params, "kind", ["full", "incremental"], required=False, default="incremental")
    parts = _parts(params)
    databases = _databases(params)
    keep_full = v.integer(params, "keep_full", required=False, default=4, minimum=0, maximum=100)

    def work(_params, log):
        with _Lock(domain):
            return make_backup(domain, target, key, kind, parts, databases, keep_full, log)

    return start_job("client_backup.run", work, {"domain": domain})


def _safety(params, domain, parts, databases, log):
    """Optionally make a full backup to the customer's location before a restore."""
    if not isinstance(params.get("safety_target"), dict):
        return None
    target = parse_target(params, "safety_target")
    key = "t" + str(v.integer(params, "safety_target_id", minimum=1, maximum=2 ** 31))
    log("Making a backup of the current state first")
    return make_backup(domain, target, key, "full", parts, databases, 0, log, prune=False)


@action("client_backup.restore")
def client_backup_restore(params):
    """Restore a backup from the customer's location."""
    domain = _domain(params)
    target = parse_target(params)
    name = _file(params, domain)
    parts = _parts(params)
    databases = _databases(params)
    meta = parse_name(domain, name)

    def work(_params, log):
        with _Lock(domain):
            safety = _safety(params, domain, parts, databases, log)
            staging = _staging()
            opened = []
            try:
                with Remote(target, domain) as remote:
                    files = [meta["base"], name] if meta["kind"] == "incremental" else [name]
                    local = [_download(remote, f, staging, log) for f in files]
                for p in local:
                    opened.append(open_archive(str(p)))
                if meta["kind"] == "incremental" and opened[-1][1].get("base") != meta["base"]:
                    raise ActionError("invalid_params", "The incremental backup does not belong to its full backup")
                result = restore(domain, opened, parts, databases, log)
                result["safety_backup"] = safety["file"] if safety else None
                return result
            finally:
                for zf, _ in opened:
                    zf.close()
                shutil.rmtree(staging, ignore_errors=True)

    return start_job("client_backup.restore", work, {"domain": domain})


@action("client_backup.inspect_upload")
def client_backup_inspect_upload(params):
    zf, manifest = open_archive(_upload_path(params))
    zf.close()
    return summary(manifest)


@action("client_backup.restore_upload")
def client_backup_restore_upload(params):
    """Restore a backup zip the customer uploaded through the panel."""
    domain = _domain(params)
    path = _upload_path(params)
    parts = _parts(params)
    databases = _databases(params)

    def work(_params, log):
        with _Lock(domain):
            try:
                safety = _safety(params, domain, parts, databases, log)
                zf, manifest = open_archive(path)
                try:
                    if manifest["kind"] == "incremental":
                        raise ActionError("invalid_params", "This is an incremental backup. Upload the full backup "
                                                            f"{manifest.get('base')} or restore from your backup location.")
                    result = restore(domain, [(zf, manifest)], parts, databases, log)
                finally:
                    zf.close()
                result["safety_backup"] = safety["file"] if safety else None
                return result
            finally:
                try:
                    os.unlink(path)
                except FileNotFoundError:
                    pass

    return start_job("client_backup.restore_upload", work, {"domain": domain})


@action("client_backup.discard_upload")
def client_backup_discard_upload(params):
    try:
        os.unlink(_upload_path(params))
    except (FileNotFoundError, ActionError):
        pass
    return {"discarded": True}
