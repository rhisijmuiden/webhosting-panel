"""File manager worker. Runs as the website's own Linux user, never as root.

whp-agent starts this script for every file manager request with
subprocess(user=<site user>), so the kernel enforces ownership, permissions
and the disk quota. On top of that every path is resolved inside the site
root (the "jail"): "..", absolute paths and symlinks that point outside the
site root are refused.

Protocol: one JSON request on stdin, one JSON response on stdout.
File contents never travel through JSON: the agent passes an open file
descriptor (number in $WHP_DATA_FD) that is either the uploaded data to read or the spool file
to write the download / file contents to.

Stdlib only, no imports from whp_agent (it runs with python3 -I).
"""

import json
import os
import posixpath
import shutil
import stat
import sys
import tarfile
import tempfile
import time
import zipfile

DATA_FD = int(os.environ.get("WHP_DATA_FD", "-1"))
MAX_EDIT_BYTES = 5 * 1024 * 1024
MAX_EXTRACT_BYTES = 20 * 1024 ** 3
MAX_EXTRACT_ENTRIES = 200_000
MAX_LIST = 5000
CHUNK = 1024 * 1024
ARCHIVES = (".zip", ".tar", ".tar.gz", ".tgz", ".tar.bz2", ".tbz2", ".tar.xz", ".txz")


class Fail(Exception):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code
        self.message = message


class Jail:
    def __init__(self, root, protected):
        self.root = os.path.realpath(root)
        # Top level entries the panel needs (logs, tmp, docroot): no delete, rename or move.
        self.protected = {p.strip("/") for p in protected if p}

    def _lexical(self, rel):
        if not isinstance(rel, str) or "\x00" in rel or len(rel) > 4096:
            raise Fail("invalid_params", "Invalid path")
        rel = posixpath.normpath("/" + rel.replace("\\", "/")).lstrip("/")
        if rel in ("", "."):
            return ""
        if rel == ".." or rel.startswith("../"):
            raise Fail("forbidden", "Path leaves your webspace")
        return rel

    def inside(self, real):
        return real == self.root or real.startswith(self.root + os.sep)

    def path(self, rel, follow=True, must_exist=True):
        """Absolute path of rel inside the jail. follow=False keeps a final symlink
        itself (to delete or rename the link rather than its target)."""
        rel = self._lexical(rel)
        full = os.path.join(self.root, rel) if rel else self.root
        parent = os.path.realpath(os.path.dirname(full)) if rel else self.root
        if not self.inside(parent):
            raise Fail("forbidden", "Path leaves your webspace")
        target = os.path.join(parent, os.path.basename(full)) if rel else self.root
        if follow:
            target = os.path.realpath(target)
            if not self.inside(target):
                raise Fail("forbidden", "This link points outside your webspace")
        if must_exist and not os.path.lexists(target):
            raise Fail("not_found", f"Not found: /{rel}")
        return target

    def rel(self, absolute):
        r = os.path.relpath(absolute, self.root)
        return "" if r == "." else r

    def check_mutable(self, absolute):
        r = self.rel(absolute)
        if r == "" or r in self.protected:
            raise Fail("forbidden", f"/{r} is managed by the panel and cannot be removed or renamed")


def valid_name(name):
    if not isinstance(name, str) or not name or len(name) > 255 or name in (".", "..") \
            or "/" in name or "\\" in name or "\x00" in name:
        raise Fail("invalid_params", "Invalid file name")
    return name


def entry(path, name=None):
    st = os.lstat(path)
    kind = "dir" if stat.S_ISDIR(st.st_mode) else "link" if stat.S_ISLNK(st.st_mode) \
        else "file" if stat.S_ISREG(st.st_mode) else "other"
    item = {
        "name": name or os.path.basename(path),
        "type": kind,
        "size": st.st_size if kind == "file" else None,
        "mtime": int(st.st_mtime),
        "mode": format(stat.S_IMODE(st.st_mode), "04o"),
    }
    if kind == "link":
        try:
            item["target"] = os.readlink(path)
            item["target_type"] = "dir" if os.path.isdir(path) else "file"
        except OSError:
            item["target"] = None
    return item


def is_binary(head):
    if b"\x00" in head:
        return True
    try:
        head.decode("utf-8")
    except UnicodeDecodeError as exc:
        # A multi-byte character cut off at the end of the sample is still text.
        return exc.start < len(head) - 4
    return False


# ----------------------------------------------------------------- operations

def op_list(jail, req):
    d = jail.path(req.get("path", ""))
    if not os.path.isdir(d):
        raise Fail("invalid_params", "Not a folder")
    items = []
    total = 0
    with os.scandir(d) as it:
        for e in it:
            total += 1
            if len(items) < MAX_LIST:
                try:
                    items.append(entry(e.path, e.name))
                except OSError:
                    continue
    items.sort(key=lambda i: (i["type"] != "dir" and i.get("target_type") != "dir", i["name"].lower()))
    st = os.statvfs(d)
    return {"path": jail.rel(d), "entries": items, "total": total, "truncated": total > len(items),
            "free_bytes": st.f_bavail * st.f_frsize}


def op_read(jail, req):
    p = jail.path(req.get("path"))
    st = os.stat(p)
    if not stat.S_ISREG(st.st_mode):
        raise Fail("invalid_params", "Not a file")
    if st.st_size > MAX_EDIT_BYTES:
        raise Fail("too_large", "This file is too large for the editor; download it instead")
    with open(p, "rb") as fh:
        data = fh.read(MAX_EDIT_BYTES + 1)
    if is_binary(data[:8192]):
        raise Fail("binary", "This is not a UTF-8 text file; download it instead")
    _write_fd(data)
    return {"path": jail.rel(p), "size": len(data), "mtime": str(st.st_mtime_ns), "mode": format(stat.S_IMODE(st.st_mode), "04o")}


def _write_fd(data):
    view = memoryview(data)
    while view:
        n = os.write(DATA_FD, view[:CHUNK])
        view = view[n:]


def _copy_from_fd(dest_fh):
    size = 0
    while True:
        buf = os.read(DATA_FD, CHUNK)
        if not buf:
            return size
        dest_fh.write(buf)
        size += len(buf)


def _atomic_write(target, mode, writer):
    """Write a new version next to the target and swap it in, so a full disk or
    a broken upload never leaves a half written file behind."""
    d = os.path.dirname(target)
    fd, tmp = tempfile.mkstemp(dir=d, prefix=".whp-", suffix=".part")
    try:
        with os.fdopen(fd, "wb") as fh:
            size = writer(fh)
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, target)
        return size
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def op_write(jail, req):
    p = jail.path(req.get("path"), must_exist=False)
    mode = 0o644
    if os.path.lexists(p):
        st = os.stat(p)
        if not stat.S_ISREG(st.st_mode):
            raise Fail("invalid_params", "Not a file")
        expected = req.get("expected_mtime")
        if expected not in (None, "") and str(expected) != str(st.st_mtime_ns):
            raise Fail("conflict", "The file was changed by someone else since you opened it")
        mode = stat.S_IMODE(st.st_mode)
    elif not req.get("create"):
        raise Fail("not_found", "The file no longer exists")
    size = _atomic_write(p, mode, _copy_from_fd)
    st = os.stat(p)
    return {"path": jail.rel(p), "size": size, "mtime": str(st.st_mtime_ns)}


def op_upload(jail, req):
    d = jail.path(req.get("dir", ""))
    if not os.path.isdir(d):
        raise Fail("invalid_params", "Not a folder")
    # Folder uploads send "sub/dir/file.txt": create the folders below d.
    parts = [valid_name(x) for x in str(req.get("name", "")).split("/") if x]
    if not parts:
        raise Fail("invalid_params", "Invalid file name")
    for part in parts[:-1]:
        d = os.path.join(d, part)
        if not os.path.isdir(d):
            os.mkdir(d, 0o755)
        d = jail.path(jail.rel(d))
    target = jail.path(jail.rel(os.path.join(d, parts[-1])), must_exist=False)
    if os.path.lexists(target):
        if not req.get("overwrite"):
            raise Fail("exists", f"{parts[-1]} already exists")
        if os.path.isdir(target) or os.path.islink(target):
            raise Fail("exists", f"{parts[-1]} is a folder or link")
    mode = stat.S_IMODE(os.stat(target).st_mode) if os.path.exists(target) else 0o644
    size = _atomic_write(target, mode, _copy_from_fd)
    return {"path": jail.rel(target), "size": size}


def op_download(jail, req):
    p = jail.path(req.get("path"))
    out = os.fdopen(DATA_FD, "wb", closefd=False)
    if os.path.isdir(p):
        with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
            _zip_add(jail, zf, p, os.path.basename(p) or "webspace")
        out.flush()
        return {"name": (os.path.basename(p) or "webspace") + ".zip", "type": "zip"}
    if not os.path.isfile(p):
        raise Fail("invalid_params", "Not a file")
    with open(p, "rb") as fh:
        shutil.copyfileobj(fh, out, CHUNK)
    out.flush()
    return {"name": os.path.basename(p), "type": "file", "size": os.path.getsize(p)}


def op_mkdir(jail, req):
    d = jail.path(req.get("dir", ""))
    target = os.path.join(d, valid_name(req.get("name")))
    if os.path.lexists(target):
        raise Fail("exists", "That name is already in use")
    if req.get("type") == "file":
        with open(target, "x"):
            pass
        os.chmod(target, 0o644)
    else:
        os.mkdir(target, 0o755)
    return {"path": jail.rel(target)}


def op_rename(jail, req):
    src = jail.path(req.get("path"), follow=False)
    jail.check_mutable(src)
    dst = os.path.join(os.path.dirname(src), valid_name(req.get("name")))
    if os.path.lexists(dst):
        raise Fail("exists", "That name is already in use")
    os.rename(src, dst)
    return {"path": jail.rel(dst)}


def _targets(jail, req):
    paths = req.get("paths")
    if not isinstance(paths, list) or not paths or len(paths) > 1000:
        raise Fail("invalid_params", "Choose one or more items")
    return [jail.path(p, follow=False) for p in paths]


def op_move(jail, req, copy=False):
    dest = jail.path(req.get("to", ""))
    if not os.path.isdir(dest):
        raise Fail("invalid_params", "The destination is not a folder")
    done = []
    for src in _targets(jail, req):
        if not copy:
            jail.check_mutable(src)
        target = os.path.join(dest, os.path.basename(src))
        if os.path.realpath(dest) == os.path.realpath(src) or os.path.realpath(dest).startswith(os.path.realpath(src) + os.sep):
            raise Fail("invalid_params", "A folder cannot be moved or copied into itself")
        if os.path.lexists(target):
            if not copy or os.path.dirname(src) != dest:
                raise Fail("exists", f"{os.path.basename(src)} already exists in the destination")
            target = _free_name(dest, os.path.basename(src))
        if copy:
            if os.path.isdir(src) and not os.path.islink(src):
                shutil.copytree(src, target, symlinks=True)
            else:
                shutil.copy2(src, target, follow_symlinks=False)
        else:
            os.rename(src, target)
        done.append(jail.rel(target))
    return {"paths": done}


def _free_name(d, name):
    stem, ext = (name, "")
    if "." in name.lstrip("."):
        stem, ext = name.rsplit(".", 1)
        ext = "." + ext
    for i in range(1, 1000):
        candidate = os.path.join(d, f"{stem} ({i}){ext}" if i > 1 else f"{stem} copy{ext}")
        if not os.path.lexists(candidate):
            return candidate
    raise Fail("exists", "No free name found")


def op_delete(jail, req):
    count = 0
    for p in _targets(jail, req):
        jail.check_mutable(p)
        if os.path.isdir(p) and not os.path.islink(p):
            shutil.rmtree(p)
        else:
            os.unlink(p)
        count += 1
    return {"deleted": count}


def op_chmod(jail, req):
    mode = req.get("mode")
    if not isinstance(mode, str) or not mode.isdigit() or len(mode) not in (3, 4) or any(c > "7" for c in mode):
        raise Fail("invalid_params", "Use an octal mode such as 644 or 755")
    value = int(mode, 8)
    if value & 0o7000:
        raise Fail("forbidden", "setuid, setgid and sticky bits are not allowed")
    if value & 0o002:
        raise Fail("forbidden", "World writable files are not allowed; use 755 or 644")
    changed = 0
    for p in _targets(jail, req):
        if os.path.islink(p):
            continue
        os.chmod(p, value)
        changed += 1
    return {"changed": changed, "mode": format(value, "04o")}


def _partial(path):
    name = os.path.basename(path)
    return name.startswith(".whp-") and name.endswith(".part")


def _zip_add(jail, zf, path, arc):
    if os.path.islink(path) or _partial(path):
        return
    if os.path.isdir(path):
        zf.write(path, arc + "/")
        with os.scandir(path) as it:
            for e in sorted(it, key=lambda x: x.name):
                _zip_add(jail, zf, e.path, f"{arc}/{e.name}")
    elif os.path.isfile(path):
        zf.write(path, arc)


def op_compress(jail, req):
    sources = _targets(jail, req)
    d = jail.path(req.get("dir", ""))
    fmt = req.get("format", "zip")
    if fmt not in ("zip", "tar.gz"):
        raise Fail("invalid_params", "Format must be zip or tar.gz")
    name = valid_name(req.get("name") or (os.path.basename(sources[0]) if len(sources) == 1 else "archive"))
    if not name.endswith("." + fmt):
        name += "." + fmt
    target = os.path.join(d, name)
    if os.path.lexists(target):
        target = _free_name(d, name)

    def writer(fh):
        if fmt == "zip":
            with zipfile.ZipFile(fh, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
                for src in sources:
                    progress(f"Adding {jail.rel(src)}")
                    _zip_add(jail, zf, src, os.path.basename(src))
        else:
            with tarfile.open(fileobj=fh, mode="w:gz", compresslevel=6) as tf:
                for src in sources:
                    progress(f"Adding {jail.rel(src)}")
                    tf.add(src, arcname=os.path.basename(src),
                           filter=lambda ti: None if ti.issym() or ti.islnk() or not (ti.isfile() or ti.isdir())
                           or _partial(ti.name) else ti)
        return fh.tell()

    size = _atomic_write(target, 0o644, writer)
    return {"path": jail.rel(target), "size": size}


def _safe_member(name):
    name = name.replace("\\", "/")
    norm = posixpath.normpath("/" + name).lstrip("/")
    if not norm or norm == "." or name.startswith("/") or norm.startswith("../") or norm == ".." \
            or any(part == ".." for part in name.split("/")) or "\x00" in name:
        return None
    return norm


def op_extract(jail, req):
    archive = jail.path(req.get("path"))
    lower = archive.lower()
    if not lower.endswith(ARCHIVES):
        raise Fail("invalid_params", "Supported archives: .zip, .tar, .tar.gz, .tgz, .tar.bz2, .tar.xz")
    dest = jail.path(req.get("to", "") if req.get("to") is not None else jail.rel(os.path.dirname(archive)))
    if not os.path.isdir(dest):
        raise Fail("invalid_params", "The destination is not a folder")
    overwrite = bool(req.get("overwrite"))
    written = 0
    files = 0

    def place(member_name, is_dir):
        norm = _safe_member(member_name)
        if norm is None:
            progress(f"Skipped unsafe path {member_name!r}")
            return None
        target = os.path.join(dest, norm)
        parent = os.path.dirname(target)
        os.makedirs(parent, mode=0o755, exist_ok=True)
        if not jail.inside(os.path.realpath(parent)):
            progress(f"Skipped {member_name!r}: it would leave your webspace")
            return None
        if is_dir:
            if not os.path.isdir(target):
                if os.path.lexists(target):
                    raise Fail("exists", f"{norm} already exists and is not a folder")
                os.mkdir(target, 0o755)
            return None
        if os.path.lexists(target):
            if not overwrite:
                raise Fail("exists", f"{norm} already exists. Tick 'overwrite' to replace existing files.")
            if os.path.islink(target):
                os.unlink(target)
            elif os.path.isdir(target):
                raise Fail("exists", f"{norm} is a folder in the destination")
        return target

    def copy(src_fh, target, mode):
        nonlocal written, files
        with open(target, "wb") as out:
            while True:
                buf = src_fh.read(CHUNK)
                if not buf:
                    break
                written += len(buf)
                if written > MAX_EXTRACT_BYTES:
                    raise Fail("too_large", "The archive unpacks to more than 20 GB")
                out.write(buf)
        os.chmod(target, ((mode & 0o755) | 0o600) if mode else 0o644)
        files += 1
        if files % 500 == 0:
            progress(f"{files} files unpacked")

    if lower.endswith(".zip"):
        with zipfile.ZipFile(archive) as zf:
            infos = zf.infolist()
            if len(infos) > MAX_EXTRACT_ENTRIES:
                raise Fail("too_large", "The archive holds too many files")
            if sum(i.file_size for i in infos) > MAX_EXTRACT_BYTES:
                raise Fail("too_large", "The archive unpacks to more than 20 GB")
            for info in infos:
                unix_mode = info.external_attr >> 16
                if stat.S_ISLNK(unix_mode):
                    progress(f"Skipped link {info.filename}")
                    continue
                target = place(info.filename, info.is_dir())
                if target:
                    with zf.open(info) as src:
                        copy(src, target, stat.S_IMODE(unix_mode))
    else:
        with tarfile.open(archive, "r:*") as tf:
            count = 0
            for ti in tf:
                count += 1
                if count > MAX_EXTRACT_ENTRIES:
                    raise Fail("too_large", "The archive holds too many files")
                if not (ti.isfile() or ti.isdir()):
                    progress(f"Skipped {ti.name} (links and devices are not unpacked)")
                    continue
                target = place(ti.name, ti.isdir())
                if target:
                    src = tf.extractfile(ti)
                    if src:
                        copy(src, target, ti.mode)
    return {"path": jail.rel(dest), "files": files, "bytes": written}


def op_search(jail, req):
    q = req.get("query")
    if not isinstance(q, str) or not 2 <= len(q) <= 100:
        raise Fail("invalid_params", "Search for at least 2 characters")
    q = q.lower()
    start = jail.path(req.get("path", ""))
    found = []
    deadline = time.monotonic() + 20
    for d, dirs, names in os.walk(start):
        dirs[:] = [x for x in dirs if x not in ("node_modules", ".git")]
        for n in dirs + names:
            if q in n.lower():
                found.append(entry(os.path.join(d, n)) | {"path": jail.rel(os.path.join(d, n))})
                if len(found) >= 500:
                    return {"results": found, "truncated": True}
        if time.monotonic() > deadline:
            return {"results": found, "truncated": True}
    return {"results": found, "truncated": False}


OPS = {
    "list": op_list, "read": op_read, "write": op_write, "upload": op_upload, "download": op_download,
    "mkdir": op_mkdir, "rename": op_rename, "move": op_move, "copy": lambda j, r: op_move(j, r, copy=True),
    "delete": op_delete, "chmod": op_chmod, "compress": op_compress, "extract": op_extract, "search": op_search,
}


def progress(line):
    # Progress lines go to stderr; the agent forwards them to the job log.
    try:
        sys.stderr.write(str(line)[:500] + "\n")
        sys.stderr.flush()
    except OSError:
        pass


def main():
    try:
        req = json.loads(sys.stdin.read() or "{}")
        op = OPS.get(req.get("op"))
        if not op:
            raise Fail("invalid_params", "Unknown operation")
        jail = Jail(req["root"], req.get("protected") or [])
        os.chdir(jail.root)
        result = {"ok": True, "data": op(jail, req)}
    except Fail as exc:
        result = {"ok": False, "error": {"code": exc.code, "message": exc.message}}
    except FileNotFoundError as exc:
        result = {"ok": False, "error": {"code": "not_found", "message": f"Not found: {os.path.basename(exc.filename or '')}"}}
    except PermissionError:
        result = {"ok": False, "error": {"code": "forbidden", "message": "Permission denied"}}
    except OSError as exc:
        code = "quota" if exc.errno in (28, 122) else "command_failed"
        msg = "Your disk space is full" if code == "quota" else (exc.strerror or str(exc))
        result = {"ok": False, "error": {"code": code, "message": msg}}
    except (zipfile.BadZipFile, tarfile.TarError, EOFError) as exc:
        result = {"ok": False, "error": {"code": "invalid_params", "message": f"The archive is damaged: {exc}"}}
    sys.stdout.write(json.dumps(result))


if __name__ == "__main__":
    main()
