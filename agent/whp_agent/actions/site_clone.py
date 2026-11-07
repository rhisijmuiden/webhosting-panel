"""Clone a website into another website of the same customer (usually a test subdomain).

    site.clone_plan {source, target?}          what a clone would copy (no changes)
    site.clone      {source, target, replace?, noindex?}
                                               job: files + databases + configuration

What a clone does:

* copies every file of the source document root into the target document
  root (rsync as root, every copied file is owned by the target's own user;
  caches are left out). A target that already has a website is only emptied
  with replace=true;
* copies each database a WordPress or Joomla install of the source uses into
  a new database of the target, and points the copied configuration at it;
* WordPress: replaces the old addresses (https://www.example.com) and the old
  document root in the database with WP-CLI search-replace (serialized data
  safe), new salts, and by default asks search engines not to index the copy;
* Joomla: new database settings, paths, live_site, cookie domain and secret.

How it stays safe:

* The panel decides who may clone what (both websites of one customer).
* A database is dumped with the credentials of the copied site itself, never
  as MariaDB root, so a configuration pointing at someone else's database
  cannot be used to read it. The dump is loaded as the new database's own
  user, with every DEFINER removed, so views, triggers and routines cannot run
  with more rights than that user.
* Configuration files are edited by root only when they are regular files of
  the target user (no symlinks), through the helpers of the migration tool.
* WP-CLI runs as the target user (wordpress.wp()).
* A failed clone drops the databases it created and empties the target again.
"""

from __future__ import annotations

import os
import pwd
import re
import secrets
import subprocess
import threading
import time

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..migration import wordpress_fix as wpfix
from ..registry import ActionError, action
from ..run import run
from . import databases
from .apps import _is_placeholder, put_placeholder, safe_inside

WHP_SITE = "/usr/local/sbin/whp-site"
MARIADB = "mariadb"
MARIADB_DUMP = "mariadb-dump"
DB_LABEL = "clone"
# Never copied: caches that are rebuilt and would point at the old site, and the
# website mail settings of the source (wp.mail_*: a copy must not send as the original).
EXCLUDES = ("wp-content/cache/*", "wp-content/et-cache/*", "wp-content/litespeed/*", "wp-content/upgrade/*",
            "wp-content/mu-plugins/whp-mail.php", "wp-content/mu-plugins/whp-mail/",
            "administrator/cache/*", "cache/*", ".well-known/acme-challenge/")
_LOCAL_DB_HOSTS = {"", "localhost", "127.0.0.1", "::1", "localhost:3306", "127.0.0.1:3306"}
_JOOMLA_VAR = r"""(public\s+\${key}\s*=\s*)'((?:\\.|[^'\\])*)'(\s*;)"""
_DEFINER = re.compile(r"\s*DEFINER\s*=\s*(?:`[^`]*`|'[^']*'|[^\s@]+)@(?:`[^`]*`|'[^']*'|[^\s*]+)")
_busy: set[str] = set()
_busy_lock = threading.Lock()


# --------------------------------------------------------------------------- pure helpers (unit tested)

def strip_definers(line: str) -> str:
    """Remove DEFINER=user@host from the DDL lines of a dump (data lines are left alone)."""
    if line.startswith("INSERT INTO"):
        return line
    return _DEFINER.sub("", line) if "DEFINER" in line else line


def mycnf(user: str, password: str, database: str = "") -> str:
    """A MariaDB option file for one user (the password never goes on a command line)."""
    for value in (user, password, database):
        if "\n" in value or "\r" in value or "\0" in value:
            raise ActionError("invalid_params", "Database credentials contain a line break")

    def q(value):
        return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'

    text = f"[client]\nuser={q(user)}\npassword={q(password)}\nprotocol=socket\n"
    return text + (f"database={q(database)}\n" if database else "")


def is_local_db(host: str | None) -> bool:
    return (host or "").strip().lower() in _LOCAL_DB_HOSTS


def joomla_value(text: str, key: str) -> str | None:
    m = re.search(_JOOMLA_VAR.format(key=re.escape(key)), text)
    return re.sub(r"\\(.)", r"\1", m.group(2)) if m else None


def joomla_set(text: str, key: str, value: str) -> str:
    escaped = value.replace("\\", "\\\\").replace("'", "\\'")
    return re.sub(_JOOMLA_VAR.format(key=re.escape(key)), lambda m: m.group(1) + "'" + escaped + "'" + m.group(3), text, count=1)


def site_url(site: dict) -> str:
    scheme = "https" if site.get("ssl") in ("active", "imported") else "http"
    return f"{scheme}://{site['domain']}"


def url_pairs(source_hosts: list[str], new_base: str) -> list[tuple[str, str]]:
    """(old, new) address pairs for search-replace: both schemes, plain and JSON-escaped (\\/),
    longest host first so www.example.com is replaced before example.com."""
    pairs = []
    escaped_new = new_base.replace("/", "\\/")
    for host in sorted(dict.fromkeys(h.lower() for h in source_hosts if h), key=len, reverse=True):
        if f"//{host}" == new_base.split(":", 1)[1]:
            continue
        for scheme in ("https", "http"):
            pairs.append((f"{scheme}://{host}", new_base))
            pairs.append((f"{scheme}:\\/\\/{host}", escaped_new))
    return pairs


def replace_hosts(text: str, pairs: list[tuple[str, str]]) -> str:
    """Replace whole addresses only: https://example.com must not touch https://example.com.au."""
    for old, new in pairs:
        text = re.sub(re.escape(old) + r"(?![A-Za-z0-9-]|\.[A-Za-z0-9])", lambda _m, n=new: n, text)
    return text


def fix_wp_config(text: str, db: dict, old_root: str, new_root: str, pairs, new_domain: str) -> str:
    for key, value in (("DB_NAME", db["name"]), ("DB_USER", db["user"]), ("DB_PASSWORD", db["password"]), ("DB_HOST", "localhost")):
        text = wpfix.set_config(text, key, value)
    if wpfix.config_value(text, "COOKIE_DOMAIN"):
        text = wpfix.set_config(text, "COOKIE_DOMAIN", new_domain)
    text = wpfix.replace_path(text, old_root, new_root) if old_root != new_root else text
    return replace_hosts(text, pairs)


def fix_joomla_config(text: str, db: dict, old_root: str, new_root: str, new_base: str, new_domain: str) -> str:
    for key, value in (("host", "localhost"), ("user", db["user"]), ("password", db["password"]), ("db", db["name"]),
                       ("secret", secrets.token_urlsafe(16)[:16])):
        text = joomla_set(text, key, value)
    if joomla_value(text, "live_site"):
        text = joomla_set(text, "live_site", new_base)
    if joomla_value(text, "cookie_domain"):
        text = joomla_set(text, "cookie_domain", new_domain)
    return wpfix.replace_path(text, old_root, new_root) if old_root != new_root else text


# --------------------------------------------------------------------------- detection

def _is_joomla(d: str) -> bool:
    cfg = os.path.join(d, "configuration.php")
    if not os.path.isfile(cfg) or os.path.islink(cfg):
        return False
    try:
        with open(cfg, errors="replace") as fh:
            return "class JConfig" in fh.read(4000)
    except OSError:
        return False


def detect_joomla(docroot: str, depth: int = 2) -> list[dict]:
    found = []

    def walk(d, rel, level):
        if _is_joomla(d):
            found.append({"path": rel, "dir": d})
            return
        if level >= depth:
            return
        try:
            entries = sorted(os.scandir(d), key=lambda e: e.name)
        except OSError:
            return
        for e in entries:
            if not e.name.startswith(".") and e.is_dir(follow_symlinks=False) and e.name not in ("administrator", "cache", "tmp", "images", "media"):
                walk(e.path, f"{rel}/{e.name}" if rel else e.name, level + 1)

    walk(docroot, "", 0)
    return found


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


def _uid(site):
    try:
        pw = pwd.getpwnam(site["user"])
    except KeyError:
        raise ActionError("not_found", f"System user {site['user']} does not exist")
    return pw.pw_uid, pw.pw_gid


def _target_content(site: dict) -> list[str]:
    """Names in the target document root that are more than an empty new website."""
    out = []
    for name in sorted(os.listdir(site["docroot"])):
        full = os.path.join(site["docroot"], name)
        if name == ".well-known" or (name in ("index.html", "index.php") and _is_placeholder(full, site["domain"])):
            continue
        out.append(name)
    return out


def _read_config(path: str, uid: int) -> str | None:
    return wpfix._read(path, uid)


def _installs(source: dict, uid: int) -> list[dict]:
    """WordPress and Joomla installs of the source with the database each one uses."""
    out = []
    wp_dirs = set()
    for inst in wordpress.detect(source["domain"]):
        cfg = os.path.join(inst["dir"], "wp-config.php")
        if not os.path.isfile(cfg):
            cfg = os.path.join(os.path.dirname(inst["dir"]), "wp-config.php")
        text = _read_config(cfg, uid) or ""
        entry = {"kind": "wordpress", "path": inst["path"], "version": inst.get("version"), "config": os.path.relpath(cfg, source["docroot"]),
                 "db_name": wpfix.config_value(text, "DB_NAME"), "db_user": wpfix.config_value(text, "DB_USER"),
                 "db_host": wpfix.config_value(text, "DB_HOST"), "problem": None}
        entry["_password"] = wpfix.config_value(text, "DB_PASSWORD") or ""
        if not text:
            entry["problem"] = "wp-config.php could not be read"
        elif re.search(r"define\(\s*['\"]MULTISITE['\"]\s*,\s*true", text, re.I):
            entry["problem"] = "WordPress multisite cannot be cloned"
        out.append(entry)
        wp_dirs.add(inst["dir"])
    for inst in detect_joomla(source["docroot"]):
        if inst["dir"] in wp_dirs:
            continue
        text = _read_config(os.path.join(inst["dir"], "configuration.php"), uid) or ""
        entry = {"kind": "joomla", "path": inst["path"], "version": None,
                 "config": os.path.relpath(os.path.join(inst["dir"], "configuration.php"), source["docroot"]),
                 "db_name": joomla_value(text, "db"), "db_user": joomla_value(text, "user"),
                 "db_host": joomla_value(text, "host"), "problem": None if text else "configuration.php could not be read"}
        entry["_password"] = joomla_value(text, "password") or ""
        out.append(entry)
    for entry in out:
        if entry["problem"] is None and not (entry["db_name"] and entry["db_user"]):
            entry["problem"] = "no database settings found"
        elif entry["problem"] is None and not is_local_db(entry["db_host"]):
            entry["problem"] = f"the database is on another server ({entry['db_host']})"
    return out


def _public(installs: list[dict]) -> list[dict]:
    return [{k: val for k, val in i.items() if not k.startswith("_")} for i in installs]


def _du_mb(path: str) -> int:
    proc = run(["du", "-sm", "--one-file-system", path], timeout=300, check=False)
    try:
        return int((proc.stdout or "0").split()[0])
    except (ValueError, IndexError):
        return 0


def _pair(params):
    source = v.domain(params, "source")
    target = v.domain(params, "target")
    if source == target:
        raise ActionError("invalid_params", "Source and target are the same website")
    return source, target


@action("site.clone_plan")
def clone_plan(params):
    """What a clone would do: installs and databases found, size, and (with a target) what is there now."""
    source = v.domain(params, "source")
    target = v.domain(params, "target", required=False)
    if target == source:
        raise ActionError("invalid_params", "Source and target are the same website")
    src = _site(source)
    uid, _ = _uid(src)
    dst = _site(target) if target else None
    return {
        "source": source, "target": target,
        "installs": _public(_installs(src, uid)),
        "target_content": _target_content(dst)[:20] if dst else [],
        "size_mb": _du_mb(src["docroot"]),
        "target_url": site_url(dst) if dst else None,
    }


@action("site.clone")
def clone_start(params):
    source, target = _pair(params)
    replace = v.boolean(params, "replace", required=False, default=False)
    noindex = v.boolean(params, "noindex", required=False, default=True)
    with _busy_lock:
        if source in _busy or target in _busy:
            raise ActionError("conflict", "A clone of one of these websites is already running")
        _busy.update((source, target))
    try:
        src, dst = _site(source), _site(target)
        if _target_content(dst) and not replace:
            raise ActionError("conflict", f"{target} already has files. Choose to replace its website or empty it first.")
    except ActionError:
        with _busy_lock:
            _busy.difference_update((source, target))
        raise

    def job(_params, log):
        try:
            return _clone(src, dst, noindex, log)
        finally:
            with _busy_lock:
                _busy.difference_update((source, target))

    return start_job("site.clone", job, {"source": source, "target": target})


# --------------------------------------------------------------------------- the clone itself

def _work_dir() -> str:
    d = os.path.join(paths.STATE_DIR, "clone")
    os.makedirs(d, mode=0o700, exist_ok=True)
    os.chmod(d, 0o700)
    return d


def _option_file(text: str) -> str:
    path = os.path.join(_work_dir(), f"my-{secrets.token_hex(8)}.cnf")
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "w") as fh:
        fh.write(text)
    return path


def copy_database(inst: dict, target_domain: str, log) -> dict:
    """Dump the install's database with its own credentials and load it into a new database of the target."""
    src_cnf = _option_file(mycnf(inst["db_user"], inst["_password"]))
    dump = os.path.join(_work_dir(), f"dump-{secrets.token_hex(8)}.sql")
    new = None
    try:
        log(f"database {inst['db_name']}: exporting")
        proc = run([MARIADB_DUMP, f"--defaults-extra-file={src_cnf}", "--single-transaction", "--quick", "--skip-lock-tables",
                    "--no-tablespaces", "--hex-blob", "--triggers", "--default-character-set=utf8mb4",
                    f"--result-file={dump}.raw", "--", inst["db_name"]], timeout=3600, check=False)
        if proc.returncode != 0:
            raise ActionError("command_failed", f"The database {inst['db_name']} could not be exported: "
                                                f"{(proc.stderr or '').strip().splitlines()[-1:] or ['unknown error']}"[:400])
        fd = os.open(dump, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with open(f"{dump}.raw", encoding="utf-8", errors="surrogateescape") as src, \
                os.fdopen(fd, "w", encoding="utf-8", errors="surrogateescape") as out:
            for line in src:
                out.write(strip_definers(line))
        os.unlink(f"{dump}.raw")
        new = databases.create_for_site(target_domain, DB_LABEL)
        dst_cnf = _option_file(mycnf(new["user"], new["password"], new["name"]))
        try:
            log(f"database {inst['db_name']}: importing into {new['name']}")
            with open(dump, "rb") as fh:
                res = subprocess.run([MARIADB, f"--defaults-extra-file={dst_cnf}", "--default-character-set=utf8mb4", "--binary-mode"],
                                     stdin=fh, capture_output=True, timeout=3600, env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin"})
            if res.returncode != 0:
                err = res.stderr.decode(errors="replace").strip().splitlines()[-1:] or ["unknown error"]
                raise ActionError("command_failed", f"The database copy could not be loaded: {err[0][:300]}")
        finally:
            os.unlink(dst_cnf)
        return new
    except BaseException:
        if new:
            try:
                databases.drop(new["name"], target_domain)
            except ActionError:
                pass
        raise
    finally:
        for f in (src_cnf, dump, f"{dump}.raw"):
            try:
                os.unlink(f)
            except FileNotFoundError:
                pass


def copy_files(src: dict, dst: dict, log) -> None:
    log(f"files: copying {src['docroot']} to {dst['docroot']}")
    argv = ["rsync", "-rlptH", "--no-specials", "--no-devices", "--safe-links", "--delete",
            f"--chown={dst['user']}:{dst['user']}"]
    for pattern in EXCLUDES:
        argv.append(f"--exclude={pattern}")
    argv += ["--", src["docroot"].rstrip("/") + "/", dst["docroot"].rstrip("/") + "/"]
    proc = run(argv, timeout=7200, check=False, umask=0o022)
    if proc.returncode not in (0, 24):  # 24: files vanished while copying (a live site)
        last = (proc.stderr or "").strip().splitlines()[-1:] or [f"exit code {proc.returncode}"]
        hint = " The disk quota is full." if "quota" in (proc.stderr or "").lower() else ""
        raise ActionError("command_failed", f"Copying the files failed: {last[0][:300]}.{hint}")


def empty_docroot(site: dict) -> None:
    from .apps import clear_target

    clear_target(site["domain"], site["root"], site["docroot"], "")


def _write_config(path: str, text: str, uid: int, gid: int):
    wpfix._write(path, text, uid, gid)


def _wp_fix(inst, db, src, dst, uid, gid, noindex, log) -> dict:
    new_base = site_url(dst)
    pairs = url_pairs([src["domain"], *src.get("aliases", [])], new_base)
    cfg = os.path.join(dst["docroot"], inst["config"])
    text = wpfix._read(cfg, uid)
    if text is None:
        raise ActionError("conflict", f"{inst['config']} of the copy is not a regular file of {dst['domain']}")
    _write_config(cfg, fix_wp_config(text, db, src["docroot"], dst["docroot"], pairs, dst["domain"]), uid, gid)
    install_dir = os.path.join(dst["docroot"], inst["path"]) if inst["path"] else dst["docroot"]
    for path in wpfix._candidates(install_dir, os.path.realpath(dst["docroot"])):
        if os.path.basename(path) == "wp-config.php":
            continue
        old = wpfix._read(path, uid)
        if old is not None:
            new = replace_hosts(wpfix.replace_path(old, src["docroot"], dst["docroot"]), pairs)
            if new != old:
                _write_config(path, new, uid, gid)
    rel = inst["path"]
    count = 0
    log(f"wordpress /{rel}: new addresses {new_base}")
    for old, new in [*pairs, (src["docroot"], dst["docroot"])]:
        proc = wordpress.wp(dst["domain"], ["search-replace", old, new, "--all-tables-with-prefix", "--skip-columns=guid",
                                            "--format=count"], path=rel, timeout=1800, check=False)
        if proc.returncode != 0:
            raise wordpress.failure("search-replace", proc)
        m = re.search(r"(\d+)\s*$", (proc.stdout or "").strip())
        count += int(m.group(1)) if m else 0
    wordpress.wp(dst["domain"], ["config", "shuffle-salts"], path=rel, timeout=120, check=False)
    if noindex:
        wordpress.wp(dst["domain"], ["option", "update", "blog_public", "0"], path=rel, timeout=120, check=False)
    wordpress.wp(dst["domain"], ["cache", "flush"], path=rel, timeout=120, check=False)
    return {"url": new_base + (f"/{rel}" if rel else ""), "replacements": count}


def _joomla_fix(inst, db, src, dst, uid, gid, log) -> dict:
    cfg = os.path.join(dst["docroot"], inst["config"])
    text = wpfix._read(cfg, uid)
    if text is None:
        raise ActionError("conflict", f"{inst['config']} of the copy is not a regular file of {dst['domain']}")
    _write_config(cfg, fix_joomla_config(text, db, src["docroot"], dst["docroot"], site_url(dst), dst["domain"]), uid, gid)
    log(f"joomla /{inst['path']}: configuration updated")
    return {"url": site_url(dst) + (f"/{inst['path']}" if inst["path"] else ""), "replacements": None}


def _clone(src: dict, dst: dict, noindex: bool, log) -> dict:
    started = time.time()
    s_uid, _ = _uid(src)
    d_uid, d_gid = _uid(dst)
    installs = _installs(src, s_uid)
    blocked = [i for i in installs if i["problem"]]
    if blocked:
        raise ActionError("conflict", f"/{blocked[0]['path']} ({blocked[0]['kind']}): {blocked[0]['problem']}")
    previous = [n for n in databases.owned_by(dst["domain"]) if databases._load().get(n, {}).get("label") == DB_LABEL]
    created = []
    try:
        empty_docroot(dst)
        copy_files(src, dst, log)
        # Databases shared by several installs are copied once.
        copies = {}
        results = []
        for inst in installs:
            if inst["db_name"] not in copies:
                copies[inst["db_name"]] = copy_database(inst, dst["domain"], log)
                created.append(copies[inst["db_name"]]["name"])
            db = copies[inst["db_name"]]
            if inst["kind"] == "wordpress":
                fixed = _wp_fix(inst, db, src, dst, d_uid, d_gid, noindex, log)
            else:
                fixed = _joomla_fix(inst, db, src, dst, d_uid, d_gid, log)
            results.append({"kind": inst["kind"], "path": inst["path"], "database": db["name"], **fixed})
    except BaseException:
        log("clone failed: removing the copy again")
        for name in created:
            try:
                databases.drop(name, dst["domain"])
            except ActionError:
                pass
        try:
            empty_docroot(dst)
            put_placeholder(dst["user"], dst["docroot"], dst["domain"])
        except Exception:  # the original error matters more than a failed cleanup
            pass
        raise
    # The copy replaced an earlier clone: its databases are no longer used.
    dropped = []
    for name in previous:
        try:
            databases.drop(name, dst["domain"])
            dropped.append(name)
        except ActionError:
            pass
    run([WHP_SITE, "cache", dst["domain"], "purge"], timeout=120, check=False)
    log(f"done in {int(time.time() - started)} s")
    return {"source": src["domain"], "target": dst["domain"], "installs": results, "databases": created,
            "dropped_databases": dropped, "size_mb": _du_mb(dst["docroot"]), "url": site_url(dst)}
