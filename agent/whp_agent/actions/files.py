"""Client file manager: browse, edit, upload, download, zip and unzip inside a
website's own root (/var/www/vhosts/<domain>).

Nothing here touches customer files as root. Every operation runs
fileworker.py as the site's Linux user (subprocess user=/group=, no extra
groups, nice + idle I/O class), so ownership, permissions and the disk quota
are enforced by the kernel, and the worker also jails every path to the
site root.

File contents travel through spool files in /var/lib/webhosting-panel/files-spool
(owner whp, 0700): the panel writes uploads there, the agent opens them as
root (no symlinks, regular files owned by whp only) and hands the open file
descriptor to the worker. Downloads and editor reads work the other way round.
"""

import json
import os
import pwd
import re
import stat
import subprocess
import threading

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action

WORKER = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "fileworker.py")
SPOOL_DIR = os.environ.get("WHP_FILES_SPOOL", os.path.join(paths.STATE_DIR, "files-spool"))
PANEL_USER = os.environ.get("WHP_PANEL_USER", "whp")
PYTHON = "/usr/bin/python3"
_SPOOL_NAME = re.compile(r"^[a-z]{2}-[a-f0-9]{32}$")
_SITE_USER = re.compile(r"^s_[a-z0-9_]{1,30}$")
# Error codes of the worker that are not agent error codes: keep the reason in details.
_CODE_MAP = {"exists": "conflict", "binary": "invalid_params", "too_large": "invalid_params", "quota": "command_failed"}
# Folders in the site root that the panel needs; the worker refuses to delete or rename them.
_MANAGED = ["logs", "tmp", "sessions"]


def ensure_spool():
    """Create the spool (owner whp, 0700) when an update brought the file manager
    to a server that was installed before it existed."""
    if os.path.isdir(SPOOL_DIR):
        return
    os.makedirs(SPOOL_DIR, mode=0o700, exist_ok=True)
    try:
        pw = pwd.getpwnam(PANEL_USER)
        os.chown(SPOOL_DIR, pw.pw_uid, pw.pw_gid)
    except (KeyError, PermissionError):
        pass


def _site(params):
    ensure_spool()
    domain = v.domain(params, "domain")
    site = wordpress.site_info(domain)
    if not _SITE_USER.fullmatch(site["user"] or ""):
        raise ActionError("internal", f"Website {domain} has no valid system user")
    try:
        pw = pwd.getpwnam(site["user"])
    except KeyError:
        raise ActionError("internal", f"System user {site['user']} does not exist")
    if pw.pw_uid < 100:
        raise ActionError("forbidden", "Refusing to run as a system account")
    rel_docroot = os.path.relpath(site["docroot"], site["root"]).split(os.sep)[0]
    site["uid"], site["gid"] = pw.pw_uid, pw.pw_gid
    site["protected"] = _MANAGED + ([rel_docroot] if rel_docroot not in ("", ".") else [])
    return site


def _path(params, key="path", required=True):
    value = v._get(params, key, required, "")
    if not isinstance(value, str) or len(value) > 4096 or "\x00" in value:
        raise ActionError("invalid_params", f"{key} must be a path")
    return value


def _paths(params):
    value = params.get("paths")
    if not isinstance(value, list) or not 1 <= len(value) <= 1000:
        raise ActionError("invalid_params", "paths must be a list of 1 to 1000 paths")
    return [_path({"p": p}, "p") for p in value]


def _spool_name(params, key="spool"):
    return v.string(params, key, pattern=_SPOOL_NAME.pattern)


def _panel_uid():
    try:
        return pwd.getpwnam(PANEL_USER).pw_uid
    except KeyError:
        raise ActionError("internal", f"Panel user {PANEL_USER} does not exist")


def open_spool_in(name):
    """Open an upload the panel left in the spool, without following links."""
    fd = os.open(os.path.join(SPOOL_DIR, name), os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    st = os.fstat(fd)
    if not stat.S_ISREG(st.st_mode) or st.st_uid != _panel_uid() or st.st_nlink != 1:
        os.close(fd)
        raise ActionError("forbidden", "Spool file is not a plain file of the panel")
    return fd


def open_spool_out(name):
    """Create the spool file the worker writes a download or file contents to."""
    os.makedirs(SPOOL_DIR, mode=0o700, exist_ok=True)
    fd = os.open(os.path.join(SPOOL_DIR, name),
                 os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    try:
        os.fchown(fd, _panel_uid(), -1)
    except PermissionError:  # tests run without root
        pass
    return fd


def _identity(site):
    """Drop to the site user: no root, no whp group, nothing but the site's own files."""
    return {"user": site["uid"], "group": site["gid"], "extra_groups": []}


def worker_argv():
    return ["/usr/bin/nice", "-n", "10", "/usr/bin/ionice", "-c", "3", PYTHON, "-I", "-S", WORKER]


def call_worker(site, op, request, data_fd=None, timeout=120, log=None):
    """Run one operation in fileworker.py as the site user and return its data."""
    req = dict(request, op=op, root=site["root"], protected=site["protected"])
    try:
        proc = subprocess.Popen(
            worker_argv(),
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            umask=0o022, **_identity(site),
            cwd="/", env={"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "HOME": site["root"],
                          "WHP_DATA_FD": str(data_fd if data_fd is not None else -1)},
            pass_fds=(data_fd,) if data_fd is not None else (), close_fds=True, text=True,
        )
    except FileNotFoundError as exc:
        raise ActionError("command_failed", f"Command not found: {exc.filename}")
    try:
        if log:
            # Jobs: forward progress lines (stderr) to the job log while it runs.
            def pump():
                for line in proc.stderr:
                    log(line.rstrip("\n"))
            t = threading.Thread(target=pump, daemon=True)
            t.start()
            proc.stdin.write(json.dumps(req))
            proc.stdin.close()
            out = proc.stdout.read()
            proc.wait(timeout=timeout)
            t.join(5)
            err = ""
        else:
            out, err = proc.communicate(json.dumps(req), timeout=timeout)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait()
        raise ActionError("timeout", f"The file operation did not finish within {timeout} seconds")
    finally:
        for pipe in (proc.stdin, proc.stdout, proc.stderr):
            try:
                pipe.close()
            except (OSError, ValueError):
                pass
    try:
        result = json.loads(out)
    except ValueError:
        raise ActionError("internal", "File worker gave no answer", {"stderr": (err or "")[-1000:]})
    if not result.get("ok"):
        e = result.get("error") or {}
        code = e.get("code", "internal")
        raise ActionError(_CODE_MAP.get(code, code), e.get("message", "File operation failed"), {"reason": code})
    return result["data"]


def _with_spool_out(site, op, request, spool, timeout=300):
    fd = open_spool_out(spool)
    try:
        return call_worker(site, op, request, data_fd=fd, timeout=timeout)
    except Exception:
        try:
            os.unlink(os.path.join(SPOOL_DIR, spool))
        except OSError:
            pass
        raise
    finally:
        os.close(fd)


def _with_spool_in(site, op, request, spool, timeout=300):
    fd = open_spool_in(spool)
    try:
        return call_worker(site, op, request, data_fd=fd, timeout=timeout)
    finally:
        os.close(fd)
        try:
            os.unlink(os.path.join(SPOOL_DIR, spool))
        except OSError:
            pass


@action("files.list")
def files_list(params):
    site = _site(params)
    data = call_worker(site, "list", {"path": _path(params, required=False)})
    data["protected"] = site["protected"]
    data["docroot"] = os.path.relpath(site["docroot"], site["root"])
    return data


@action("files.search")
def files_search(params):
    site = _site(params)
    return call_worker(site, "search", {"path": _path(params, required=False),
                                        "query": v.string(params, "query", max_len=100)}, timeout=60)


@action("files.read")
def files_read(params):
    site = _site(params)
    return _with_spool_out(site, "read", {"path": _path(params)}, _spool_name(params), timeout=60)


@action("files.write")
def files_write(params):
    site = _site(params)
    expected = params.get("expected_mtime")
    # Nanoseconds as a string: JavaScript numbers cannot hold them exactly.
    if isinstance(expected, int) and not isinstance(expected, bool):
        expected = str(expected)
    if expected is not None and (not isinstance(expected, str) or not expected.isdigit() or len(expected) > 24):
        raise ActionError("invalid_params", "expected_mtime must be a string of digits")
    return _with_spool_in(site, "write", {"path": _path(params), "expected_mtime": expected,
                                          "create": v.boolean(params, "create", required=False, default=False)},
                          _spool_name(params), timeout=60)


@action("files.upload")
def files_upload(params):
    site = _site(params)
    name = v.string(params, "name", max_len=1024)
    return _with_spool_in(site, "upload", {"dir": _path(params, "dir", required=False), "name": name,
                                           "overwrite": v.boolean(params, "overwrite", required=False, default=False)},
                          _spool_name(params), timeout=600)


@action("files.download")
def files_download(params):
    site = _site(params)
    return _with_spool_out(site, "download", {"path": _path(params)}, _spool_name(params), timeout=1800)


@action("files.mkdir")
def files_mkdir(params):
    site = _site(params)
    return call_worker(site, "mkdir", {"dir": _path(params, "dir", required=False),
                                       "name": v.string(params, "name"),
                                       "type": v.choice(params, "type", ["dir", "file"], required=False, default="dir")})


@action("files.rename")
def files_rename(params):
    site = _site(params)
    return call_worker(site, "rename", {"path": _path(params), "name": v.string(params, "name")})


@action("files.move")
def files_move(params):
    site = _site(params)
    return call_worker(site, "move", {"paths": _paths(params), "to": _path(params, "to", required=False)}, timeout=600)


@action("files.copy")
def files_copy(params):
    site = _site(params)
    return call_worker(site, "copy", {"paths": _paths(params), "to": _path(params, "to", required=False)}, timeout=900)


@action("files.delete")
def files_delete(params):
    site = _site(params)
    return call_worker(site, "delete", {"paths": _paths(params)}, timeout=900)


@action("files.chmod")
def files_chmod(params):
    site = _site(params)
    return call_worker(site, "chmod", {"paths": _paths(params), "mode": v.string(params, "mode", max_len=4)})


@action("files.compress")
def files_compress(params):
    site = _site(params)
    req = {"paths": _paths(params), "dir": _path(params, "dir", required=False),
           "name": v.string(params, "name", required=False, max_len=255),
           "format": v.choice(params, "format", ["zip", "tar.gz"], required=False, default="zip")}
    return start_job("files.compress", lambda _p, log: call_worker(site, "compress", req, timeout=3600, log=log), params)


@action("files.extract")
def files_extract(params):
    site = _site(params)
    req = {"path": _path(params), "to": params.get("to") if params.get("to") is None else _path(params, "to"),
           "overwrite": v.boolean(params, "overwrite", required=False, default=False)}
    return start_job("files.extract", lambda _p, log: call_worker(site, "extract", req, timeout=3600, log=log), params)
