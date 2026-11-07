"""WordPress integrity restore worker. Runs as the website's own Linux user, never as root.

The agent (actions/wp_integrity.py) starts this script through systemd-run as the
site user, so the kernel enforces ownership, permissions and the disk quota. It
downloads the original WordPress or plugin zip from WordPress.org, puts the
original copy of the requested files back and moves files that do not belong
there into a quarantine folder outside the document root.

Protocol: one JSON request on stdin, one JSON response on stdout:

    {"install_dir": "/var/www/vhosts/x/public", "quarantine": "/var/www/vhosts/x/quarantine/...",
     "items": [{"key": "core", "url": "https://downloads.wordpress.org/...zip", "prefix": "wordpress/",
                "base": "", "restore": ["wp-includes/x.php"], "remove": ["wp-admin/evil.php"]}]}

Stdlib only, no imports from whp_agent (it runs with python3 -I).
"""

import hashlib
import json
import os
import posixpath
import shutil
import sys
import tempfile
import urllib.request
import zipfile

MAX_ZIP_BYTES = 200 * 1024 * 1024
MAX_FILE_BYTES = 50 * 1024 * 1024
ALLOWED_HOSTS = ("https://downloads.wordpress.org/",)


class Fail(Exception):
    pass


def clean_rel(rel):
    """A relative path below the install, without '..', absolute parts or odd characters."""
    if not isinstance(rel, str) or not rel or len(rel) > 1000 or "\0" in rel or "\\" in rel:
        raise Fail(f"invalid path: {rel!r}"[:200])
    norm = posixpath.normpath(rel)
    if norm.startswith("/") or norm == "." or norm.split("/")[0] == ".." or norm != rel.strip("/"):
        raise Fail(f"invalid path: {rel}"[:200])
    return norm


def safe_parent(root, rel, create):
    """The real directory a file goes into; no symlinks along the way, never outside root."""
    parts = rel.split("/")[:-1]
    d = root
    for part in parts:
        d = os.path.join(d, part)
        if os.path.islink(d):
            raise Fail(f"refusing to follow a symlink: {rel}")
        if not os.path.isdir(d):
            if not create:
                return None
            os.mkdir(d, 0o755)
    real = os.path.realpath(d)
    if real != root and not real.startswith(root + os.sep):
        raise Fail(f"path leaves the install: {rel}")
    return d


def download(url, tmp):
    if not url.startswith(ALLOWED_HOSTS):
        raise Fail("only downloads.wordpress.org is allowed")
    target = os.path.join(tmp, "original.zip")
    req = urllib.request.Request(url, headers={"User-Agent": "webhosting-panel-integrity"})
    try:
        with urllib.request.urlopen(req, timeout=120) as resp, open(target, "wb") as fh:
            total = 0
            while True:
                chunk = resp.read(1024 * 1024)
                if not chunk:
                    break
                total += len(chunk)
                if total > MAX_ZIP_BYTES:
                    raise Fail("the original download is too large")
                fh.write(chunk)
    except OSError as exc:
        code = getattr(exc, "code", None)
        if code == 404:
            raise Fail("this version is not available on WordPress.org")
        raise Fail(f"download failed: {exc}")
    return target


def restore_file(zf, prefix, root, base, rel):
    name = prefix + rel
    try:
        info = zf.getinfo(name)
    except KeyError:
        raise Fail(f"not in the original package: {rel}")
    if info.file_size > MAX_FILE_BYTES:
        raise Fail(f"file too large: {rel}")
    full_rel = posixpath.join(base, rel) if base else rel
    parent = safe_parent(root, full_rel, create=True)
    target = os.path.join(parent, os.path.basename(full_rel))
    if os.path.islink(target):
        os.unlink(target)
    fd, tmp = tempfile.mkstemp(dir=parent, prefix=".whp-restore.")
    try:
        with os.fdopen(fd, "wb") as out, zf.open(info) as src:
            shutil.copyfileobj(src, out)
        os.chmod(tmp, 0o644)
        os.replace(tmp, target)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise
    with open(target, "rb") as fh:
        return hashlib.md5(fh.read()).hexdigest()


def quarantine_file(root, base, rel, qdir):
    full_rel = posixpath.join(base, rel) if base else rel
    parent = safe_parent(root, full_rel, create=False)
    if parent is None:
        return False
    src = os.path.join(parent, os.path.basename(full_rel))
    if not os.path.lexists(src) or (os.path.isdir(src) and not os.path.islink(src)):
        return False
    dest = os.path.join(qdir, full_rel)
    os.makedirs(os.path.dirname(dest), mode=0o700, exist_ok=True)
    shutil.move(src, dest)
    if not os.path.islink(dest):
        os.chmod(dest, 0o600)
    return True


def handle(req):
    root = os.path.realpath(req["install_dir"])
    qdir = req.get("quarantine") or ""
    results = []
    for item in req.get("items") or []:
        res = {"key": item.get("key"), "restored": [], "quarantined": [], "errors": []}
        results.append(res)
        base = item.get("base") or ""
        if base:
            base = clean_rel(base)
        restore = [clean_rel(r) for r in item.get("restore") or []]
        remove = [clean_rel(r) for r in item.get("remove") or []]
        if restore:
            tmp = tempfile.mkdtemp(prefix="whp-integrity.", dir=os.environ.get("TMPDIR") or None)
            try:
                with zipfile.ZipFile(download(item["url"], tmp)) as zf:
                    for rel in restore:
                        try:
                            res["restored"].append({"file": rel, "md5": restore_file(zf, item.get("prefix", ""), root, base, rel)})
                        except (Fail, OSError) as exc:
                            res["errors"].append(f"{rel}: {exc}")
            except (Fail, OSError, zipfile.BadZipFile) as exc:
                res["errors"].append(str(exc))
            finally:
                shutil.rmtree(tmp, ignore_errors=True)
        if remove:
            if not qdir:
                res["errors"].append("no quarantine folder")
                continue
            os.makedirs(qdir, mode=0o700, exist_ok=True)
            os.chmod(qdir, 0o700)
            for rel in remove:
                try:
                    if quarantine_file(root, base, rel, qdir):
                        res["quarantined"].append(rel)
                except (Fail, OSError) as exc:
                    res["errors"].append(f"{rel}: {exc}")
    return {"ok": True, "results": results}


def main():
    try:
        req = json.loads(sys.stdin.read())
        out = handle(req)
    except (Fail, ValueError, KeyError, TypeError) as exc:
        out = {"ok": False, "error": str(exc)}
    sys.stdout.write(json.dumps(out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
