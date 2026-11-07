"""Import a WordPress website from two files the customer uploaded: a zip of the
whole WordPress folder and a zip (or .sql / .sql.gz) of the database export.

    wp_import.inspect {import, domain}             job: check both files, find the old
                                                   paths and addresses (no changes)
    wp_import.run     {import, domain, replace?, new_url?, paths?, urls?, extra?, disable?}
                                                   job: files, database, wp-config.php,
                                                   paths and addresses, check
    wp_import.discard {import}                     remove the uploaded files and the report

The panel stores the uploads as <import>-site.upload and <import>-database.upload
in UPLOAD_DIR (the panel's own folder below the state directory). The report of
inspect is kept by the agent (root only) and is what run works from; the panel
only sends the customer's choices.

How it stays safe:

* Both files are checked before anything is extracted: entry names (no absolute
  paths, no ..), encryption, sizes and compression ratio.
* Files are extracted by the website's own Linux user (site_worker.py, tar
  "data" filter), so nothing can land outside the website or above its quota.
* The database is a new database of the website and the dump is loaded as its
  own MariaDB user, never as root. CREATE DATABASE / USE lines are skipped,
  DEFINERs and statements that need SUPER are removed.
* wp-config.php and the cache files are edited by root only when they are
  regular files of the site user (no symlinks), with the migration helpers.
* WP-CLI runs as the site user (wordpress.wp()).
* When replacing a website, its files are set aside first and put back when
  the import fails; a failed import also drops the new database.
"""

from __future__ import annotations

import gzip
import io
import json
import os
import pwd
import re
import secrets
import shutil
import stat
import subprocess
import threading
import time
import zipfile

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..migration import wordpress_fix as wpfix
from ..registry import ActionError, action
from ..run import SAFE_ENV, run
from . import client_backup as cb
from . import databases
from .apps import _is_placeholder, put_placeholder, safe_inside

UPLOAD_DIR = os.environ.get("WHP_WP_IMPORT_UPLOADS", os.path.join(paths.STATE_DIR, "wp-import"))
STATE = os.environ.get("WHP_WP_IMPORT_STATE", os.path.join(paths.STATE_DIR, "wp-import-reports"))
WHP_SITE = "/usr/local/sbin/whp-site"
MARIADB = "mariadb"
DB_LABEL = "import"
IMPORT_ID = r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"
MAX_ENTRIES = 500_000
MAX_RATIO = 250
MAX_SQL_LINE = 256 * 1024 ** 2
# Never extracted: caches that are rebuilt and point at the old server.
SKIP_PREFIXES = ("wp-content/cache/", "wp-content/et-cache/", "wp-content/litespeed/", "wp-content/upgrade/",
                 "wp-content/wflogs/", "wp-content/w3tc-config/master-admin.php", ".well-known/acme-challenge/")
SKIP_NAMES = {"error_log", ".DS_Store", "Thumbs.db"}
# Drop-ins that connect to services of the old host (Redis, Memcached).
DROPINS = ("object-cache.php",)
# Must-use plugins only a specific hosting company uses.
HOST_MU_PLUGINS = re.compile(r"^(kinsta-mu-plugins(\.php)?|wpengine-common|mu-plugin\.php|wpe-.*|force-strong-passwords|"
                             r"slt-force-strong-passwords\.php|endurance-.*\.php|bluehost-.*|hostinger-.*|wpcomsh.*|pantheon.*|"
                             r"gd-system-plugin.*|sg-security-.*|flywheel-.*|cloudways-.*|wp-stack-cache\.php)$", re.I)
# A path on the old server: the part before wp-content, wp-includes, ...
_OLD_PATH = re.compile(r"(?<![A-Za-z0-9_.:/~\\-])((?:/[A-Za-z0-9_.@+~-]+){1,12}?)/(?=(?:wp-content|wp-includes|wp-admin|wp-config\.php|"
                       r"wordfence-waf\.php|wp-load\.php)(?:[/'\"\\\s;:,)]|$))")
# Folders hosting companies keep websites in: a found path starting with one of these is ticked by default.
_HOSTING_ROOTS = re.compile(r"^/(home\d*|var|srv|data\d*|www|usr/home|nas|mnt|opt|web\d*|hosting|customers?|clients?|storage|sites|"
                            r"domains|public_html|htdocs|chroot|kunden|hsphere|vhosts|webspace|clientes|homepages|hp|users?|u\d+)(/|$)")
_SYSTEM_PATHS = ("/usr/", "/etc/", "/proc/", "/dev/", "/tmp/", "/bin/", "/sbin/", "/lib/")
_CREATE_TABLE = re.compile(rb"^CREATE TABLE (?:IF NOT EXISTS )?`((?:[^`]|``)+)`", re.I)
_OPTION = re.compile(rb"\(\s*'?\d+'?\s*,\s*'(siteurl|home)'\s*,\s*'((?:[^'\\]|\\.){0,2000})'")
_INSERT_INTO = re.compile(rb"^INSERT INTO `((?:[^`]|``)+)`", re.I)
_SKIP_SQL = re.compile(rb"^\s*(CREATE\s+DATABASE\b|USE\s+[`\w])", re.I)
_SUPER_SQL = re.compile(rb"^\s*(SET\s+@@GLOBAL\.|SET\s+@@SESSION\.SQL_LOG_BIN|SET\s+@MYSQLDUMP_TEMP_LOG_BIN)", re.I)
_DEFINER = re.compile(rb"DEFINER\s*=\s*(`[^`]*`|'[^']*'|[A-Za-z0-9_]+)\s*@\s*(`[^`]*`|'[^']*'|[A-Za-z0-9_.%-]+)\s*", re.I)
_PREFIX = re.compile(r"^[A-Za-z0-9_]{1,40}$")
_busy: set[str] = set()
_busy_lock = threading.Lock()


# --------------------------------------------------------------------------- files and reports

def _upload(import_id: str, kind: str) -> str:
    path = os.path.join(UPLOAD_DIR, f"{import_id}-{kind}.upload")
    try:
        st = os.lstat(path)
    except FileNotFoundError:
        raise ActionError("not_found", f"The {'website' if kind == 'site' else 'database'} file was not uploaded")
    if not stat.S_ISREG(st.st_mode):
        raise ActionError("invalid_params", "The uploaded file is not a regular file")
    return path


def _report_path(import_id: str) -> str:
    os.makedirs(STATE, mode=0o700, exist_ok=True)
    os.chmod(STATE, 0o700)
    return os.path.join(STATE, f"{import_id}.json")


def _save_report(import_id: str, report: dict) -> None:
    path = _report_path(import_id)
    tmp = path + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(report, fh)
    os.replace(tmp, path)


def _load_report(import_id: str) -> dict:
    try:
        with open(_report_path(import_id)) as fh:
            return json.load(fh)
    except (FileNotFoundError, ValueError):
        raise ActionError("conflict", "Check the files first")


def _open_zip(path: str) -> zipfile.ZipFile:
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    fh = os.fdopen(fd, "rb")
    try:
        zf = zipfile.ZipFile(fh)
        zf._filePassed = 0  # close() closes our handle too
        return zf
    except zipfile.BadZipFile:
        fh.close()
        raise ActionError("invalid_params", "This is not a valid zip file")


def _kind_of(path: str) -> str:
    with open(path, "rb") as fh:
        head = fh.read(4)
    if head[:4] == b"PK\x03\x04" or head[:4] == b"PK\x05\x06":
        return "zip"
    if head[:2] == b"\x1f\x8b":
        return "gzip"
    return "sql"


def clean_name(name: str) -> str | None:
    """A zip entry name as a relative path ('' for the top folder), or None when it is unsafe."""
    name = name.replace("\\", "/")
    if name.startswith("/") or "\x00" in name or re.match(r"^[A-Za-z]:", name):
        return None
    parts = [p for p in name.rstrip("/").split("/")]
    if any(p in ("", ".", "..") for p in parts) and name.rstrip("/") != "":
        return None
    return "/".join(parts)


def check_zip(zf: zipfile.ZipFile, limit: int) -> list[zipfile.ZipInfo]:
    """Refuse zips that are unsafe to extract. Returns the entries."""
    infos = zf.infolist()
    if len(infos) > MAX_ENTRIES:
        raise ActionError("invalid_params", "The zip file has too many entries")
    total = 0
    for zi in infos:
        if clean_name(zi.filename) is None:
            raise ActionError("invalid_params", f"Unsafe path in the zip file: {zi.filename[:200]}")
        if zi.flag_bits & 0x1:
            raise ActionError("invalid_params", "Password protected zip files are not supported. Make the zip without a password.")
        if zi.file_size > 10 * 1024 ** 2 and zi.file_size > MAX_RATIO * max(zi.compress_size, 1):
            raise ActionError("invalid_params", f"Suspicious compression ratio for {zi.filename[:200]}")
        total += zi.file_size
    if total > limit:
        raise ActionError("invalid_params", f"The zip file unpacks to {total // 1024 ** 2} MB, more than the allowed {limit // 1024 ** 2} MB")
    return infos


def find_root(names: list[str]) -> str | None:
    """The folder in the zip that holds WordPress ('' = the top of the zip): the shallowest one with wp-includes/version.php."""
    best = None
    for n in names:
        if n == "wp-includes/version.php" or n.endswith("/wp-includes/version.php"):
            root = n[: -len("wp-includes/version.php")].rstrip("/")
            if best is None or root.count("/") < best.count("/") or (root.count("/") == best.count("/") and len(root) < len(best)):
                best = root
    return best


def _under(root: str, name: str) -> str | None:
    """name relative to root, or None when it is not below root."""
    if not root:
        return name
    if name == root:
        return ""
    return name[len(root) + 1:] if name.startswith(root + "/") else None


def skipped(rel: str) -> bool:
    if rel.rsplit("/", 1)[-1] in SKIP_NAMES:
        return True
    return any(rel == p.rstrip("/") or rel.startswith(p) for p in SKIP_PREFIXES)


def old_paths(text: str) -> dict[str, int]:
    """Absolute folders of the old server found in text, with how often each occurs."""
    found: dict[str, int] = {}
    for m in _OLD_PATH.finditer(text):
        p = m.group(1)
        if p.startswith(_SYSTEM_PATHS) or p.count("/") < 2:
            continue
        found[p] = found.get(p, 0) + 1
    return found


def _merge(into: dict[str, int], more: dict[str, int]) -> None:
    for k, n in more.items():
        into[k] = into.get(k, 0) + n


def wp_prefixes(tables: list[str]) -> list[str]:
    """Table prefixes that have both an options and a posts table."""
    names = set(tables)
    out = []
    for t in tables:
        if t.endswith("options"):
            prefix = t[: -len("options")]
            if f"{prefix}posts" in names and prefix not in out and _PREFIX.fullmatch(prefix or "x"):
                out.append(prefix)
    return out


def _unescape_sql(raw: bytes) -> str:
    return re.sub(rb"\\(.)", rb"\1", raw).decode("utf-8", "replace")


def scan_sql(stream, size_hint: int = 0) -> dict:
    """Read a dump once: tables, siteurl/home per prefix, old paths, statements that will be skipped."""
    tables, urls, found = [], {}, {}
    create_db = use_db = super_lines = 0
    mysql8 = False
    first = b""
    table = None  # the table of the INSERT whose rows are being read
    for i, line in enumerate(stream):
        if i == 0:
            first = line[:200]
        if len(line) > MAX_SQL_LINE:
            raise ActionError("invalid_params", "The database file has a line longer than 256 MB")
        m = _CREATE_TABLE.match(line)
        if m:
            tables.append(m.group(1).replace(b"``", b"`").decode("utf-8", "replace"))
            mysql8 = mysql8 or b"utf8mb4_0900_" in line
            continue
        if _SKIP_SQL.match(line):
            if line.lstrip()[:3].upper() == b"USE":
                use_db += 1
            else:
                create_db += 1
            continue
        if _SUPER_SQL.match(line):
            super_lines += 1
            continue
        ins = _INSERT_INTO.match(line)
        if ins:
            table = ins.group(1).replace(b"``", b"`").decode("utf-8", "replace")
        elif table is None or not line.lstrip().startswith(b"("):
            table = None
            continue
        # Rows of an INSERT can follow on their own lines (phpMyAdmin, newer mariadb-dump): "(1,'siteurl',...),"
        if table.endswith("options"):
            for o in _OPTION.finditer(line):
                urls.setdefault(table[: -len("options")], {})[o.group(1).decode()] = _unescape_sql(o.group(2))
        if b"/" in line:
            _merge(found, old_paths(line.decode("utf-8", "replace").replace("\\\\/", "/").replace("\\/", "/")))
        if line.rstrip().endswith(b";"):
            table = None
    if not tables and b"<html" in first.lower():
        raise ActionError("invalid_params", "The database file is a web page, not a database export")
    return {"tables": tables, "urls": urls, "paths": found, "create_database": create_db, "use_database": use_db,
            "super_statements": super_lines, "mysql8_collation": mysql8}


class _Sql:
    """The dump inside the database upload: a .sql in a zip, a .sql.gz, or a plain .sql."""

    def __init__(self, path: str):
        self.path = path
        self.kind = _kind_of(path)
        self.entry = None
        self.size = os.path.getsize(path)
        if self.kind == "zip":
            with _open_zip(path) as zf:
                infos = check_zip(zf, cb.max_restore_bytes())
                sql = [zi for zi in infos if not zi.is_dir() and re.search(r"\.sql(\.gz)?$", zi.filename, re.I)
                       and not zi.filename.replace("\\", "/").split("/")[-1].startswith("._")]
                if not sql:
                    raise ActionError("invalid_params", "The database zip file has no .sql file in it")
                sql.sort(key=lambda zi: zi.file_size, reverse=True)
                self.entry = sql[0].filename
                self.others = [zi.filename for zi in sql[1:]]
                self.size = sql[0].file_size
        else:
            self.others = []

    def name(self) -> str:
        return self.entry.replace("\\", "/").split("/")[-1] if self.entry else os.path.basename(self.path)

    def open(self):
        """A binary line stream of the SQL text (caller closes it)."""
        if self.kind == "sql":
            return open(self.path, "rb")
        if self.kind == "gzip":
            return gzip.open(self.path, "rb")
        zf = _open_zip(self.path)
        raw = zf.open(self.entry)
        if self.entry.lower().endswith(".gz"):
            raw = gzip.GzipFile(fileobj=raw)
        return _Closing(raw, zf)


class _Closing(io.RawIOBase):
    def __init__(self, raw, *others):
        self.raw, self.others = raw, others

    def __iter__(self):
        return iter(self.raw)

    def read(self, n=-1):
        return self.raw.read(n)

    def close(self):
        self.raw.close()
        for o in self.others:
            o.close()
        super().close()


# --------------------------------------------------------------------------- site helpers

def _site(domain: str) -> dict:
    site = wordpress.site_info(domain)
    if site["suspended"]:
        raise ActionError("conflict", f"Website {domain} is suspended")
    for key in ("root", "docroot"):
        if os.path.islink(site[key]) or not os.path.isdir(site[key]):
            raise ActionError("conflict", f"The {key} of {domain} is not a plain folder")
    if not safe_inside(site["docroot"], site["root"]):
        raise ActionError("forbidden", f"The document root of {domain} is not inside the website")
    return site


def _uid(site: dict) -> tuple[int, int]:
    try:
        pw = pwd.getpwnam(site["user"])
    except KeyError:
        raise ActionError("not_found", f"System user {site['user']} does not exist")
    return pw.pw_uid, pw.pw_gid


def site_url(site: dict, www: bool = False) -> str:
    scheme = "https" if site.get("ssl") in ("active", "imported") else "http"
    return f"{scheme}://{'www.' if www else ''}{site['domain']}"


def new_urls(site: dict) -> list[str]:
    out = [site_url(site)]
    if f"www.{site['domain']}" in site.get("aliases", []):
        out.append(site_url(site, www=True))
    return out


def _target_content(site: dict) -> list[str]:
    out = []
    for name in sorted(os.listdir(site["docroot"])):
        full = os.path.join(site["docroot"], name)
        if name == ".well-known" or (name in ("index.html", "index.php") and _is_placeholder(full, site["domain"])):
            continue
        out.append(name)
    return out


def url_variants(old: str) -> list[str]:
    """https/http and www/no-www forms of an old address, the given one first."""
    m = re.match(r"^(https?)://([^/:?#]+)(:\d+)?(/[^?#]*)?", old.strip())
    if not m:
        return []
    host, port, path = m.group(2).lower(), m.group(3) or "", (m.group(4) or "").rstrip("/")
    other = host[4:] if host.startswith("www.") else f"www.{host}"
    out = []
    for h in (host, other):
        for scheme in (m.group(1), "https" if m.group(1) == "http" else "http"):
            u = f"{scheme}://{h}{port}{path}"
            if u not in out:
                out.append(u)
    return out


def url_pairs(olds: list[str], new_base: str) -> list[tuple[str, str]]:
    """search-replace pairs: longest address first, plain and JSON-escaped (\\/), nothing that is already right."""
    pairs = []
    for old in sorted(dict.fromkeys(o.rstrip("/") for o in olds if o), key=len, reverse=True):
        if old == new_base:
            continue
        pairs.append((old, new_base))
        pairs.append((old.replace("/", "\\/"), new_base.replace("/", "\\/")))
    return pairs


def path_pairs(olds: list[str], new_root: str) -> list[tuple[str, str]]:
    pairs = []
    for old in sorted(dict.fromkeys(o.rstrip("/") for o in olds if o), key=len, reverse=True):
        if old == new_root:
            continue
        pairs.append((old, new_root))
        pairs.append((old.replace("/", "\\/"), new_root.replace("/", "\\/")))
    return pairs


# --------------------------------------------------------------------------- inspect

def _check(checks: list, key: str, state: str, detail: str = "", **extra) -> None:
    checks.append({"key": key, "state": state, "detail": detail, **extra})


def inspect(import_id: str, domain: str, log=lambda line: None) -> dict:
    site = _site(domain)
    checks: list[dict] = []
    report: dict = {"import": import_id, "domain": domain, "checked_at": int(time.time()), "checks": checks}
    found_paths: dict[str, int] = {}

    # ---- the website zip
    log("step: website zip")
    site_path = _upload(import_id, "site")
    if _kind_of(site_path) != "zip":
        raise ActionError("invalid_params", "The website file is not a zip file")
    with _open_zip(site_path) as zf:
        infos = check_zip(zf, cb.max_restore_bytes())
        names = {}
        for zi in infos:
            n = clean_name(zi.filename)
            if n:
                names[n] = zi
        root = find_root(list(names))
        _check(checks, "zip_valid", "ok", f"{len(infos)} entries")
        if root is None:
            _check(checks, "wordpress_found", "bad", "wp-includes/version.php is missing")
            report["ok"] = False
            report["site"] = {"entries": len(infos)}
            return report
        rel = {r: zi for n, zi in names.items() if (r := _under(root, n)) not in (None, "")}
        version = None
        try:
            m = re.search(rb"^\$wp_version\s*=\s*'([0-9A-Za-z.\-]+)'", zf.read(names[f"{root}/wp-includes/version.php" if root else "wp-includes/version.php"]), re.M)
            version = m.group(1).decode() if m else None
        except (KeyError, zipfile.BadZipFile):
            pass
        missing = [d for d in ("wp-admin", "wp-includes", "wp-content") if not any(r == d or r.startswith(d + "/") for r in rel)]
        _check(checks, "wordpress_found", "bad" if missing else "ok",
               f"missing: {', '.join(missing)}" if missing else (f"WordPress {version}" if version else ""), folder=root, version=version)
        # wp-config.php: in the WordPress folder, one level above it, or only the sample.
        config_name = config_text = None
        parent = root.rsplit("/", 1)[0] if "/" in root else ""
        for candidate in ([f"{root}/wp-config.php" if root else "wp-config.php"] + ([f"{parent}/wp-config.php" if parent else "wp-config.php"] if root else [])):
            if candidate in names and not names[candidate].is_dir() and names[candidate].file_size < 1024 ** 2:
                config_name = candidate
                config_text = zf.read(names[candidate]).decode("utf-8", "replace")
                break
        sample = "wp-config-sample.php" in rel
        if config_text is None:
            _check(checks, "config_found", "warn" if sample else "bad",
                   "wp-config.php is missing: a new one is made from wp-config-sample.php" if sample else "wp-config.php is missing")
        else:
            _check(checks, "config_found", "ok", config_name)
        config = config_text or ""
        multisite = bool(re.search(r"define\(\s*['\"](MULTISITE|WP_ALLOW_MULTISITE)['\"]\s*,\s*true", config, re.I))
        prefix_m = re.search(r"^\s*\$table_prefix\s*=\s*['\"]([A-Za-z0-9_]*)['\"]\s*;", config, re.M)
        old_db = {k.lower(): wpfix.config_value(config, k) for k in ("DB_NAME", "DB_USER", "DB_HOST")}
        old_db["password_set"] = bool(wpfix.config_value(config, "DB_PASSWORD"))
        if config:
            _merge(found_paths, old_paths(config))
        # Cache files and host specific plugins.
        cache = []
        for d in DROPINS + ("advanced-cache.php", "wp-cache-config.php"):
            zi = rel.get(f"wp-content/{d}")
            if zi and not zi.is_dir():
                text = zf.read(zi).decode("utf-8", "replace") if zi.file_size < 2 * 1024 ** 2 else ""
                _merge(found_paths, old_paths(text))
                cache.append({"file": f"wp-content/{d}", "kind": "dropin" if d in DROPINS else "page_cache",
                              "disable": d in DROPINS})
        for d in (".htaccess", ".user.ini", "php.ini", "wordfence-waf.php"):
            zi = rel.get(d)
            if zi and not zi.is_dir() and zi.file_size < 2 * 1024 ** 2:
                _merge(found_paths, old_paths(zf.read(zi).decode("utf-8", "replace")))
        mu = sorted({r.split("/")[2] for r in rel if r.startswith("wp-content/mu-plugins/") and r.count("/") >= 2
                     and HOST_MU_PLUGINS.match(r.split("/")[2])})
        for name in mu:
            cache.append({"file": f"wp-content/mu-plugins/{name}", "kind": "host_plugin", "disable": True})
        defines = sorted(k for k in ("WP_CACHE", "WPCACHEHOME", "WP_REDIS_HOST", "WP_REDIS_SERVERS", "WP_REDIS_PATH", "WP_CACHE_KEY_SALT")
                         if re.search(r"define\(\s*['\"]" + k + r"['\"]", config))
        size = sum(zi.file_size for r, zi in rel.items() if not skipped(r))
        skipped_size = sum(zi.file_size for r, zi in rel.items() if skipped(r))
        outside = sum(1 for n in names if _under(root, n) is None and n != parent + "/wp-config.php" and n != "wp-config.php")
        report["site"] = {
            "entries": len(rel), "size": size, "skipped_size": skipped_size, "folder": root, "outside_entries": outside,
            "version": version, "config": config_name, "from_sample": config_text is None and sample, "multisite": multisite,
            "table_prefix": prefix_m.group(1) if prefix_m else None, "old_db": old_db, "cache": cache, "cache_defines": defines,
            "home_define": wpfix.config_value(config, "WP_HOME"), "siteurl_define": wpfix.config_value(config, "WP_SITEURL"),
        }
    if multisite:
        _check(checks, "not_multisite", "bad", "WordPress multisite cannot be imported")

    # ---- the database
    log("step: database export")
    sql = _Sql(_upload(import_id, "database"))
    log(f"database: reading {sql.name()} ({sql.size // 1024 ** 2} MB)")
    with sql.open() as stream:
        try:
            scan = scan_sql(stream, sql.size)
        except (OSError, EOFError, zipfile.BadZipFile, gzip.BadGzipFile) as exc:
            raise ActionError("invalid_params", f"The database file could not be read: {exc}")
    _merge(found_paths, scan["paths"])
    prefixes = wp_prefixes(scan["tables"])
    config_prefix = report["site"]["table_prefix"]
    prefix = config_prefix if config_prefix in prefixes else (prefixes[0] if prefixes else None)
    if not scan["tables"]:
        _check(checks, "database_found", "bad", "no tables in the database export")
    elif prefix is None:
        _check(checks, "database_found", "bad", "no WordPress tables (options and posts) in the database export")
    else:
        _check(checks, "database_found", "ok", f"{len(scan['tables'])} tables", file=sql.name())
        if config_prefix is not None and config_prefix != prefix:
            _check(checks, "prefix_matches", "warn", f"wp-config.php uses {config_prefix}, the database {prefix}: wp-config.php is changed to {prefix}")
        elif config_prefix is not None:
            _check(checks, "prefix_matches", "ok", prefix)
        if f"{prefix}blogs" in scan["tables"] or f"{prefix}site" in scan["tables"]:
            _check(checks, "not_multisite", "bad", "WordPress multisite cannot be imported")
    if len(sql.others):
        _check(checks, "one_sql_file", "warn", f"{len(sql.others) + 1} .sql files: using the largest, {sql.name()}")
    urls = scan["urls"].get(prefix or "", {}) if prefix is not None else {}
    report["database"] = {
        "file": sql.name(), "size": sql.size, "tables": len(scan["tables"]), "prefix": prefix, "prefixes": prefixes,
        "siteurl": urls.get("siteurl"), "home": urls.get("home"), "create_database": scan["create_database"],
        "use_database": scan["use_database"], "super_statements": scan["super_statements"],
        "mysql8_collation": scan["mysql8_collation"],
    }
    if not (urls.get("siteurl") or urls.get("home")) and prefix is not None:
        _check(checks, "address_found", "warn", "the address of the old website was not found in the database")

    # ---- the target website
    new_root = os.path.realpath(site["docroot"])
    choices = new_urls(site)
    old_home = urls.get("home") or urls.get("siteurl") or report["site"]["home_define"] or ""
    default_new = choices[1] if len(choices) > 1 and re.match(r"^https?://www\.", old_home) else choices[0]
    olds = []
    for u in (urls.get("home"), urls.get("siteurl"), report["site"]["home_define"], report["site"]["siteurl_define"]):
        for variant in url_variants(u or ""):
            if variant not in olds:
                olds.append(variant)
    found = sorted(((p, n) for p, n in found_paths.items() if p.rstrip("/") != new_root), key=lambda x: (-x[1], x[0]))[:8]
    report["paths"] = [{"old": p, "count": n, "new": new_root, "likely": bool(_HOSTING_ROOTS.match(p))} for p, n in found]
    report["urls"] = [{"old": u, "new": default_new} for u in olds if u.rstrip("/") != default_new]
    report["target"] = {
        "docroot": new_root, "url": default_new, "urls": choices, "content": _target_content(site)[:20],
        "database": databases.name_for(domain, DB_LABEL), "previous_databases": _previous(domain),
    }
    if report["target"]["content"]:
        _check(checks, "target_empty", "warn", "the website already has files: they are replaced")
    report["ok"] = not any(c["state"] == "bad" for c in checks)
    _save_report(import_id, report)
    log("done")
    return report


def _previous(domain: str) -> list[str]:
    data = databases._load()
    return [n for n in databases.owned_by(domain) if data.get(n, {}).get("label") == DB_LABEL]


# --------------------------------------------------------------------------- run

def _members(zf: zipfile.ZipFile, root: str, config_name: str | None):
    """(rel, ZipInfo, zf) for everything below the WordPress folder; wp-config.php from above it lands next to WordPress."""
    out = []
    for zi in zf.infolist():
        n = clean_name(zi.filename)
        if not n:
            continue
        rel = _under(root, n)
        if rel is None:
            if config_name and n == config_name:
                out.append(("wp-config.php", zi, zf))
            continue
        if rel == "" or skipped(rel):
            continue
        out.append((rel, zi, zf))
    return out


def _aside(site: dict, stamp: str) -> str | None:
    """Move the current website files out of the document root (put back when the import fails)."""
    names = [n for n in os.listdir(site["docroot"]) if n != ".well-known"]
    if not names:
        return None
    holder = os.path.join(site["root"], f".whp-import-previous-{stamp}")
    os.mkdir(holder, 0o700)
    for n in names:
        os.rename(os.path.join(site["docroot"], n), os.path.join(holder, n))
    return holder


def _back(site: dict, holder: str | None) -> None:
    for n in os.listdir(site["docroot"]):
        if n == ".well-known":
            continue
        full = os.path.join(site["docroot"], n)
        if os.path.isdir(full) and not os.path.islink(full):
            shutil.rmtree(full)
        else:
            os.unlink(full)
    if holder:
        for n in os.listdir(holder):
            os.rename(os.path.join(holder, n), os.path.join(site["docroot"], n))
        os.rmdir(holder)


def filter_sql_line(line: bytes, state: dict) -> bytes | None:
    """One line of the dump as it is loaded: None to skip it (pure, unit tested)."""
    if state.get("skip_until_semicolon"):
        if line.rstrip().endswith(b";"):
            state["skip_until_semicolon"] = False
        return None
    if state.get("in_insert"):
        # A row of a multi-line INSERT: data, passed on unchanged.
        if line.rstrip().endswith(b";"):
            state["in_insert"] = False
        return line
    if line.startswith(b"INSERT INTO"):
        if not line.rstrip().endswith(b";"):
            state["in_insert"] = True
        return line
    if _SKIP_SQL.match(line) or _SUPER_SQL.match(line):
        if not line.rstrip().endswith(b";"):
            state["skip_until_semicolon"] = True
        return None
    if b"DEFINER" in line:
        line = _DEFINER.sub(b"", line)
    if b"utf8mb4_0900_" in line:
        line = re.sub(rb"utf8mb4_0900_(ai_ci|as_ci|as_cs|bin)", b"utf8mb4_unicode_520_ci", line)
    return line


def load_sql(sql: _Sql, db: dict, log) -> None:
    cnf = os.path.join(cb._private_dir("wpimport-"), "client.cnf")
    from .site_clone import mycnf
    cb._write_private(cnf, mycnf(db["user"], db["password"], db["name"]))
    try:
        proc = subprocess.Popen([MARIADB, f"--defaults-extra-file={cnf}", "--default-character-set=utf8mb4", "--binary-mode"],
                                stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, env=SAFE_ENV)
        errbuf = []
        reader = threading.Thread(target=lambda: errbuf.append(proc.stderr.read()), daemon=True)
        reader.start()
        state: dict = {}
        try:
            with sql.open() as stream:
                for line in stream:
                    out = filter_sql_line(line, state)
                    if out is not None:
                        proc.stdin.write(out)
            proc.stdin.close()
        except BrokenPipeError:
            pass
        rc = proc.wait()
        reader.join()
        if rc != 0:
            err = (errbuf[0] if errbuf else b"").decode(errors="replace").strip()
            raise ActionError("command_failed", f"Importing the database failed: {err[-300:] or f'exit code {rc}'}")
    finally:
        shutil.rmtree(os.path.dirname(cnf), ignore_errors=True)


def _create_file(path: str, text: str, uid: int, gid: int, mode: int = 0o640) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        os.write(fd, text.encode("utf-8"))
        os.fchown(fd, uid, gid)
    finally:
        os.close(fd)


def set_prefix(text: str, prefix: str) -> str:
    return re.sub(r"^(\s*\$table_prefix\s*=\s*)['\"][A-Za-z0-9_]*['\"](\s*;)", lambda m: f"{m.group(1)}'{prefix}'{m.group(2)}", text, count=1, flags=re.M)


def fix_config(text: str, db: dict, prefix: str | None, paths_: list[tuple[str, str]], urls_: list[tuple[str, str]]) -> str:
    """wp-config.php with the new database, table prefix, paths and addresses (pure, unit tested)."""
    for key, value in (("DB_NAME", db["name"]), ("DB_USER", db["user"]), ("DB_PASSWORD", db["password"]), ("DB_HOST", "localhost")):
        if wpfix.config_value(text, key) is None:
            text = re.sub(r"(<\?php\s*\n)", lambda m, k=key, val=value: m.group(1) + f"define( '{k}', {wpfix.php_string(val)} );\n", text, count=1)
        else:
            text = wpfix.set_config(text, key, value)
    if prefix is not None:
        if re.search(r"^\s*\$table_prefix\s*=", text, re.M):
            text = set_prefix(text, prefix)
        else:
            text = re.sub(r"(<\?php\s*\n)", lambda m: m.group(1) + f"$table_prefix = '{prefix}';\n", text, count=1)
    for old, new in paths_:
        if "\\/" not in old:
            text = wpfix.replace_path(text, old, new)
    for old, new in urls_:
        if "\\/" not in old:
            text = re.sub(re.escape(old) + r"(?![A-Za-z0-9-]|\.[A-Za-z0-9])", lambda _m, n=new: n, text)
    return text


def _disable(site: dict, rel: str, uid: int) -> bool:
    """Rename a drop-in or must-use plugin of the site to <name>.whp-disabled (regular files/folders of the site only)."""
    full = os.path.join(site["docroot"], rel)
    if not safe_inside(os.path.dirname(full), site["docroot"]):
        return False
    try:
        st = os.lstat(full)
    except FileNotFoundError:
        return False
    if stat.S_ISLNK(st.st_mode) or st.st_uid != uid:
        return False
    target = full + ".whp-disabled"
    if os.path.lexists(target):
        return False
    os.rename(full, target)
    return True


def _run(import_id: str, site: dict, report: dict, options: dict, log) -> dict:
    started = time.time()
    uid, gid = _uid(site)
    docroot = os.path.realpath(site["docroot"])
    stamp = time.strftime("%Y%m%d-%H%M%S")
    previous = _previous(site["domain"])
    holder = None
    db = None
    try:
        # 1. Files
        log("step: files")
        if _target_content(site):
            if not options["replace"]:
                raise ActionError("conflict", f"{site['domain']} already has files. Choose to replace them.")
            holder = _aside(site, stamp)
            log("files: the current files are set aside")
        else:
            _aside_placeholder(site)
        with _open_zip(_upload(import_id, "site")) as zf:
            check_zip(zf, cb.max_restore_bytes())
            members = _members(zf, report["site"]["folder"], report["site"].get("config"))
            log(f"files: unpacking {len(members)} entries into {docroot}")
            cb._unpack(site["user"], docroot, members)
        # 2. Database
        log("step: database")
        try:
            db = databases.create_for_site(site["domain"], DB_LABEL, name=report["target"]["database"])
        except ActionError as exc:
            if exc.code != "conflict":
                raise
            db = databases.create_for_site(site["domain"], DB_LABEL)  # the name was taken since the check
        log(f"database: loading {report['database']['file']} into {db['name']}")
        load_sql(_Sql(_upload(import_id, "database")), db, log)
        # 3. wp-config.php
        log("step: config")
        prefix = report["database"]["prefix"]
        ppairs = path_pairs(options["paths"], docroot)
        upairs = url_pairs(options["urls"], options["new_url"])
        cfg = os.path.join(docroot, "wp-config.php")
        if not os.path.lexists(cfg):
            sample = wpfix._read(os.path.join(docroot, "wp-config-sample.php"), uid)
            if sample is None:
                raise ActionError("conflict", "wp-config.php is missing and there is no wp-config-sample.php")
            _create_file(cfg, sample, uid, gid)
            log("config: wp-config.php made from wp-config-sample.php")
        text = wpfix._read(cfg, uid)
        if text is None:
            raise ActionError("conflict", "wp-config.php is not a regular file of the website")
        wpfix._write(cfg, fix_config(text, db, prefix, ppairs, upairs), uid, gid)
        for path in wpfix._candidates(docroot, docroot):
            if os.path.basename(path) == "wp-config.php":
                continue
            old = wpfix._read(path, uid)
            if old is not None:
                new = old
                for o, n in ppairs:
                    if "\\/" not in o:
                        new = wpfix.replace_path(new, o, n)
                if new != old:
                    wpfix._write(path, new, uid, gid)
                    log(f"config: paths updated in {os.path.relpath(path, docroot)}")
        disabled = [rel for rel in options["disable"] if _disable(site, rel, uid)]
        for rel in disabled:
            log(f"config: {rel} switched off")
        wordpress.wp(site["domain"], ["config", "shuffle-salts"], timeout=120, check=False)
        # 4. Paths and addresses in the database
        log("step: replace")
        count = 0
        for old, new in [*upairs, *ppairs, *options["extra"]]:
            proc = wordpress.wp(site["domain"], ["search-replace", old, new, "--all-tables-with-prefix", "--skip-columns=guid",
                                                 "--format=count"], timeout=3600, check=False)
            if proc.returncode != 0:
                raise wordpress.failure("search-replace", proc)
            m = re.search(r"(\d+)\s*$", (proc.stdout or "").strip())
            count += int(m.group(1)) if m else 0
        log(f"replace: {count} values changed")
        wordpress.wp(site["domain"], ["cache", "flush"], timeout=120, check=False)
        wordpress.wp(site["domain"], ["rewrite", "flush"], timeout=120, check=False)
    except BaseException:
        log("import failed: putting the website back as it was")
        if db:
            try:
                databases.drop(db["name"], site["domain"])
            except ActionError:
                pass
        try:
            _back(site, holder)
            if not holder:
                put_placeholder(site["user"], site["docroot"], site["domain"])
        except Exception:  # the original error matters more
            pass
        raise
    # 5. Check
    log("step: check")
    run([WHP_SITE, "cache", site["domain"], "purge"], timeout=120, check=False)
    from .wordpress import health
    code = health(options["new_url"])
    log(f"check: the website answers HTTP {code}")
    if holder:
        shutil.rmtree(holder, ignore_errors=True)
    dropped = []
    for name in previous:
        try:
            databases.drop(name, site["domain"])
            dropped.append(name)
        except ActionError:
            pass
    log(f"done in {int(time.time() - started)} s")
    return {"domain": site["domain"], "url": options["new_url"], "database": {"name": db["name"], "user": db["user"], "host": "localhost"},
            "replacements": count, "disabled": disabled, "health": code, "dropped_databases": dropped,
            "size_mb": cb_du_mb(docroot)}


def _aside_placeholder(site: dict) -> None:
    for name in ("index.html", "index.php"):
        full = os.path.join(site["docroot"], name)
        if _is_placeholder(full, site["domain"]):
            os.unlink(full)


def cb_du_mb(path: str) -> int:
    proc = run(["du", "-sm", "--one-file-system", path], timeout=300, check=False)
    try:
        return int((proc.stdout or "0").split()[0])
    except (ValueError, IndexError):
        return 0


# --------------------------------------------------------------------------- actions

def _import_id(params) -> str:
    return v.string(params, "import", max_len=36, pattern=IMPORT_ID)


def _lock(key: str):
    with _busy_lock:
        if key in _busy:
            raise ActionError("conflict", "An import of this website is already running")
        _busy.add(key)


def _unlock(key: str):
    with _busy_lock:
        _busy.discard(key)


@action("wp_import.inspect")
def wp_import_inspect(params):
    import_id = _import_id(params)
    domain = v.domain(params, "domain")
    _site(domain)
    _upload(import_id, "site")
    _upload(import_id, "database")
    _lock(domain)

    def job(_params, log):
        try:
            return inspect(import_id, domain, log)
        finally:
            _unlock(domain)

    return start_job("wp_import.inspect", job, {"import": import_id, "domain": domain})


def _strings(params, key, max_items=20, max_len=1000):
    values = params.get(key) or []
    if not isinstance(values, list) or len(values) > max_items or not all(isinstance(x, str) and 0 < len(x) <= max_len for x in values):
        raise ActionError("invalid_params", f"{key} must be a list of strings")
    return values


@action("wp_import.run")
def wp_import_run(params):
    import_id = _import_id(params)
    domain = v.domain(params, "domain")
    report = _load_report(import_id)
    if report.get("domain") != domain:
        raise ActionError("conflict", "The files were checked for another website. Check them again.")
    if not report.get("ok"):
        raise ActionError("conflict", "The check found problems. Fix them and upload the files again.")
    site = _site(domain)
    new_url = v.string(params, "new_url", required=False, default=report["target"]["url"], max_len=300)
    if new_url not in new_urls(site):
        raise ActionError("invalid_params", "Choose one of the addresses of the website")
    known_paths = {p["old"] for p in report.get("paths", [])}
    known_urls = {u["old"] for u in report.get("urls", [])}
    known_files = {c["file"] for c in report["site"].get("cache", [])}
    paths_ = [p for p in _strings(params, "paths") if p in known_paths]
    urls_ = [u for u in _strings(params, "urls", max_items=40) if u in known_urls]
    disable = [f for f in _strings(params, "disable") if f in known_files]
    extra = []
    for pair in params.get("extra") or []:
        if not (isinstance(pair, list) and len(pair) == 2 and all(isinstance(x, str) for x in pair)):
            raise ActionError("invalid_params", "extra must be a list of [old, new] pairs")
        old, new = pair[0].strip(), pair[1].strip()
        if len(old) < 4 or len(old) > 500 or len(new) > 500 or old == new or any(c in old + new for c in "\n\r\0"):
            raise ActionError("invalid_params", "A replacement must be at least 4 characters and one line")
        extra.append((old, new))
    if len(extra) > 5:
        raise ActionError("invalid_params", "At most 5 extra replacements")
    options = {"replace": v.boolean(params, "replace", required=False, default=False), "new_url": new_url,
               "paths": paths_, "urls": urls_, "extra": extra, "disable": disable}
    if _target_content(site) and not options["replace"]:
        raise ActionError("conflict", f"{domain} already has files. Choose to replace them.")
    _lock(domain)

    def job(_params, log):
        try:
            return _run(import_id, site, report, options, log)
        finally:
            _unlock(domain)

    return start_job("wp_import.run", job, {"import": import_id, "domain": domain})


@action("wp_import.discard")
def wp_import_discard(params):
    import_id = _import_id(params)
    removed = 0
    for path in (os.path.join(UPLOAD_DIR, f"{import_id}-site.upload"), os.path.join(UPLOAD_DIR, f"{import_id}-database.upload"),
                 _report_path(import_id)):
        try:
            os.unlink(path)
            removed += 1
        except FileNotFoundError:
            pass
    return {"import": import_id, "removed": removed}
