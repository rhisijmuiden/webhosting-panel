"""Make a copied WordPress site work on this server.

After the files and databases are copied, each WordPress install of the site:
  * gets DB_HOST localhost (MariaDB runs here) and, when the database user got
    a new password because the old hash could not be copied, that password;
  * has the old server's document root (for example /var/www/vhosts/x/httpdocs)
    replaced by the new one in wp-config.php, .htaccess, .user.ini, php.ini and
    the cache/firewall config files plugins write next to it;
  * has the same path replaced in the database with WP-CLI search-replace
    (handles serialized values), run as the site user.

Files are edited as text by root, so every write refuses symlinks and files
the site user does not own. Nothing here is fatal for the migration: problems
are returned and shown on the migration page.
"""

from __future__ import annotations

import os
import re
import stat

from .. import wordpress
from ..registry import ActionError

# DB_HOST values that mean "the database on the old server itself".
_LOCAL_HOSTS = {"localhost", "127.0.0.1", "::1", "localhost:3306", "127.0.0.1:3306"}
_DEFINE = r"""(define\(\s*['"]{key}['"]\s*,\s*)(['"])((?:\\.|(?!\2).)*)\2(\s*\))"""
# Files next to WordPress that commonly hold absolute paths.
_ROOT_FILES = (".htaccess", ".user.ini", "php.ini", "wordfence-waf.php", "wp-config.php")
_CONTENT_FILES = ("advanced-cache.php", "wp-cache-config.php", "object-cache.php")
MAX_FILE = 2 * 1024 * 1024


def php_string(value: str) -> str:
    """A PHP single quoted string literal."""
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def config_value(text: str, key: str) -> str | None:
    m = re.search(_DEFINE.format(key=re.escape(key)), text, re.S)
    if not m:
        return None
    raw = m.group(3)
    return re.sub(r"\\(.)", r"\1", raw)


def set_config(text: str, key: str, value: str) -> str:
    pattern = re.compile(_DEFINE.format(key=re.escape(key)), re.S)
    return pattern.sub(lambda m: m.group(1) + php_string(value) + m.group(4), text, count=1)


def fix_config(text: str, old_root: str, new_root: str, passwords: dict[str, str], source_hosts: set[str]) -> tuple[str, list[str]]:
    """The new wp-config.php text and what changed (pure, unit tested)."""
    changed = []
    host = config_value(text, "DB_HOST")
    if host is not None and host != "localhost":
        bare = host.split(":")[0].strip("[]").lower()
        if host.lower() in _LOCAL_HOSTS or bare in _LOCAL_HOSTS or bare in source_hosts:
            text = set_config(text, "DB_HOST", "localhost")
            changed.append("DB_HOST")
    user = config_value(text, "DB_USER")
    if user and passwords.get(user):
        if config_value(text, "DB_PASSWORD") != passwords[user]:
            text = set_config(text, "DB_PASSWORD", passwords[user])
            changed.append("DB_PASSWORD")
    if old_root and old_root != new_root:
        replaced = replace_path(text, old_root, new_root)
        if replaced != text:
            text = replaced
            changed.append("paths")
    return text, changed


def replace_path(text: str, old: str, new: str) -> str:
    """Replace the old document root only where it is a whole path (not /httpdocs2)."""
    return re.sub(re.escape(old.rstrip("/")) + r"(?=[/'\"\s;:,)]|$)", new.rstrip("/"), text, flags=re.M)


def _read(path: str, uid: int) -> str | None:
    """Text of a regular file owned by the site user, without following symlinks."""
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except (FileNotFoundError, NotADirectoryError):
        return None
    except OSError:
        return None  # ELOOP: a symlink
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid != uid or st.st_size > MAX_FILE:
            return None
        with os.fdopen(fd, "rb", closefd=False) as fh:
            data = fh.read(MAX_FILE + 1)
    finally:
        os.close(fd)
    try:
        return data.decode("utf-8")
    except UnicodeDecodeError:
        return data.decode("latin-1")


def _write(path: str, text: str, uid: int, gid: int) -> None:
    """Replace the file atomically, keeping its mode; the new file belongs to the site user."""
    st = os.lstat(path)
    if not stat.S_ISREG(st.st_mode) or st.st_uid != uid:
        raise ActionError("forbidden", f"{path} is not a regular file of the site")
    tmp = os.path.join(os.path.dirname(path), f".whp-migrate.{os.getpid()}.tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", errors="surrogateescape", closefd=False) as fh:
            fh.write(text)
        os.fchown(fd, uid, gid)
        os.fchmod(fd, stat.S_IMODE(st.st_mode))
    finally:
        os.close(fd)
    os.replace(tmp, path)


def _candidates(install_dir: str, docroot: str) -> list[str]:
    files = [os.path.join(install_dir, f) for f in _ROOT_FILES]
    # wp-config.php may sit one level above the install (WordPress allows that).
    parent = os.path.dirname(install_dir)
    if parent == docroot or parent.startswith(docroot + os.sep):
        files.append(os.path.join(parent, "wp-config.php"))
    content = os.path.join(install_dir, "wp-content")
    files += [os.path.join(content, f) for f in _CONTENT_FILES]
    try:
        files += sorted(os.path.join(content, "wflogs", e) for e in os.listdir(os.path.join(content, "wflogs")) if e.endswith(".php"))
    except OSError:
        pass
    seen, out = set(), []
    for f in files:
        if f not in seen:
            seen.add(f)
            out.append(f)
    return out


def fix_site(domain: str, old_root: str, passwords: dict[str, str], source_hosts: set[str], log=lambda line: None) -> list[dict]:
    """Fix every WordPress install of a copied site. Returns one entry per install."""
    import pwd

    site = wordpress.site_info(domain)
    try:
        pw = pwd.getpwnam(site["user"])
    except KeyError:
        raise ActionError("not_found", f"System user {site['user']} does not exist")
    uid, gid = pw.pw_uid, pw.pw_gid
    docroot = os.path.realpath(site["docroot"])
    out = []
    for inst in wordpress.detect(domain):
        rel = inst["path"]
        name = f"wordpress {rel or '/'}"
        entry = {"path": rel, "config": [], "files": [], "db_replacements": None, "ok": True}
        new_root = os.path.join(docroot, rel) if rel else docroot
        old = os.path.join(old_root, rel) if (old_root and rel) else old_root
        log(f"{name}: updating the configuration")
        try:
            for path in _candidates(inst["dir"], docroot):
                text = _read(path, uid)
                if text is None:
                    continue
                if os.path.basename(path) == "wp-config.php":
                    new, changed = fix_config(text, old_root, docroot, passwords, source_hosts)
                    entry["config"] += [c for c in changed if c not in entry["config"]]
                else:
                    new = replace_path(text, old_root, docroot) if old_root and old_root != docroot else text
                if new != text:
                    _write(path, new, uid, gid)
                    entry["files"].append(os.path.relpath(path, docroot))
            if old and old != new_root:
                proc = wordpress.wp(domain, ["search-replace", old.rstrip("/"), new_root.rstrip("/"), "--all-tables-with-prefix",
                                             "--skip-columns=guid", "--precise", "--format=count"], path=rel, timeout=1800, check=False)
                if proc.returncode == 0:
                    m = re.search(r"(\d+)\s*$", (proc.stdout or "").strip())
                    entry["db_replacements"] = int(m.group(1)) if m else 0
                else:
                    entry["ok"] = False
                    entry["error"] = wordpress.explain(proc)
        except ActionError as exc:
            entry["ok"] = False
            entry["error"] = exc.message
        if entry["ok"]:
            log(f"{name}: done (config: {', '.join(entry['config']) or 'unchanged'}; files: {len(entry['files'])};"
                f" database paths: {entry['db_replacements'] or 0})")
        else:
            log(f"{name}: failed: {entry['error']}")
        out.append(entry)
    return out
