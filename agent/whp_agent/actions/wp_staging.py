"""WordPress staging: work on a copy, then push it to the live website in one go.

A staging copy is made with the clone tool (site.clone, usually into
staging.<domain>). This module adds what a staging workflow needs on top:

    wp.staging_prepare {staging, path?}          mark a copy as staging: outgoing mail
                                                 held back, hidden from search engines,
                                                 "STAGING" in the admin bar (mu-plugin)
    wp.staging_plan    {staging, live, path?}    what a push would do (no changes)
    wp.staging_push    {staging, live, path?, files?, database?, keep_tables?, keep_uploads?}
                                                 job: backup of live, then files + database
    wp.staging_backups {live}                    the backups made before each push
    wp.staging_restore {live, backup}            job: put a backup back on the live website

What a push does, in this order:

1. A backup of the live install: every file (rsync, hard links to the
   previous backup so unchanged files take no space) and a dump of its
   database, made with the live site's own database user.
2. Files: the staging install is synced onto the live install. The live
   wp-config.php stays (it holds the live database settings), caches and the
   staging mu-plugin are left out, and by default uploads that only exist on
   live (media added while you worked on staging) are kept.
3. Database: the staging tables are dumped with the staging site's own
   credentials and loaded into the live database as the live database user,
   except the tables you keep (for a shop: orders and customers that came in
   on live meanwhile). Then WP-CLI search-replace turns every staging address
   and folder back into the live ones, and the live "search engine
   visibility" setting is put back.
4. The live front page is requested. When it worked before the push and
   gives a server error after it, the backup is put back automatically, as
   it is when any step of the push fails.

Safety: the panel decides who may push what (both websites of one customer).
No database is ever read or written as MariaDB root, every DEFINER is removed
from the dump, WP-CLI runs as the site's own user, and files written into the
live website belong to the live site's user.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import threading
import time

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..migration import wordpress_fix as wpfix
from ..registry import ActionError, action
from ..run import run
from . import site_clone as clone

KEEP_BACKUPS = 3
MU_PLUGIN = "wp-content/mu-plugins/whp-staging.php"
# Never pushed: the live configuration, caches, the staging marker and certificate challenges.
PUSH_EXCLUDES = ("/wp-config.php", "/" + MU_PLUGIN, "/.well-known/", *(f"/{p}" for p in clone.EXCLUDES if p.startswith("wp-content/")))
UPLOADS = "wp-content/uploads"
_PREFIX = re.compile(r"""^\s*\$table_prefix\s*=\s*(['"])([A-Za-z0-9_]{1,32})\1\s*;""", re.M)
_TABLE = re.compile(r"^[A-Za-z0-9_$]{1,64}$")
_STAMP = r"\d{8}-\d{6}"
# Tables a shop or community writes to all day: offered as "keep the live data".
_LIVE_DATA = re.compile(r"^(wc_orders|wc_orders_meta|wc_order_.*|wc_customer_lookup|woocommerce_order_.*|woocommerce_sessions|"
                        r"wc_download_log|woocommerce_downloadable_product_permissions|comments|commentmeta|users|usermeta|"
                        r"gf_entry.*|wpforms_entr.*|e_submissions.*|actionscheduler_.*)$")
_busy: set[str] = set()
_busy_lock = threading.Lock()

MU_PLUGIN_CODE = r"""<?php
/**
 * Plugin Name: Staging copy (hosting panel)
 * Description: This website is a staging copy. Outgoing email is held back and search engines are asked not to index it. Added by the hosting panel; it is never pushed to the live website.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!defined('WHP_STAGING')) {
    define('WHP_STAGING', true);
}
// Customers, orders and contact forms of a copy must not mail real people.
add_filter('pre_wp_mail', function ($return) {
    return (defined('WHP_STAGING_SEND_MAIL') && WHP_STAGING_SEND_MAIL) ? $return : false;
}, 1);
add_filter('pre_option_blog_public', function () {
    return '0';
});
add_action('admin_bar_menu', function ($bar) {
    $bar->add_node(['id' => 'whp-staging', 'title' => 'STAGING', 'parent' => 'top-secondary',
        'meta' => ['title' => 'Staging copy: changes here are not live until you push them from the hosting panel.']]);
}, 1);
add_action('wp_head', function () {
    echo "<style>#wp-admin-bar-whp-staging>.ab-item{background:#c9776a!important;color:#fff!important;font-weight:600}</style>\n";
});
add_action('admin_head', function () {
    echo "<style>#wp-admin-bar-whp-staging>.ab-item{background:#c9776a!important;color:#fff!important;font-weight:600}</style>\n";
});
"""


# --------------------------------------------------------------------------- pure helpers (unit tested)

def table_prefix(config_text: str) -> str | None:
    m = _PREFIX.search(config_text or "")
    return m.group(2) if m else None


def suggested_keep(tables: list[str], prefix: str) -> list[str]:
    """Tables of the live website that hold data visitors create (orders, customers, comments, form entries)."""
    out = []
    for t in tables:
        if t.startswith(prefix) and _LIVE_DATA.match(t[len(prefix):]):
            out.append(t)
    return out


def home_base(url: str) -> str | None:
    """https://www.example.com/blog -> https://www.example.com"""
    m = re.match(r"^(https?)://([A-Za-z0-9.-]+(?::\d+)?)(?:/|$)", (url or "").strip())
    return f"{m.group(1)}://{m.group(2).lower()}" if m else None


def push_pairs(staging_hosts: list[str], live_base: str) -> list[tuple[str, str]]:
    """Address pairs that turn the staging copy back into the live website."""
    return clone.url_pairs(staging_hosts, live_base)


def rsync_push_argv(src_dir: str, dst_dir: str, user: str, keep_uploads: bool) -> list[str]:
    argv = ["rsync", "-rlptH", "--no-specials", "--no-devices", "--safe-links", "--delete", f"--chown={user}:{user}"]
    for pattern in PUSH_EXCLUDES:
        argv.append(f"--exclude={pattern}")
    if keep_uploads:
        # Synced in a second pass without --delete: media added on live meanwhile stays.
        argv.append(f"--exclude=/{UPLOADS}/")
    return argv + ["--", src_dir.rstrip("/") + "/", dst_dir.rstrip("/") + "/"]


# --------------------------------------------------------------------------- sites and installs

def _state() -> str:
    d = os.path.join(paths.STATE_DIR, "staging")
    os.makedirs(d, mode=0o700, exist_ok=True)
    os.chmod(d, 0o700)
    return d


def _backups_dir(live: str) -> str:
    base = os.path.join(_state(), "backups")
    os.makedirs(base, mode=0o700, exist_ok=True)
    d = os.path.join(base, live)
    os.makedirs(d, mode=0o700, exist_ok=True)
    return d


def _install(site: dict, path: str) -> dict:
    """The WordPress install at path of a site with its database settings; 'problem' says why it cannot be used."""
    uid, gid = clone._uid(site)
    d = wordpress.install_dir(site, path)
    out = {"path": path, "dir": d, "uid": uid, "gid": gid, "problem": None, "config": None,
           "db_name": None, "db_user": None, "db_host": None, "prefix": None, "_password": ""}
    if not wordpress._is_install(d):
        out["problem"] = f"no WordPress found in {site['domain']}/{path}"
        return out
    cfg = os.path.join(d, "wp-config.php")
    if not os.path.isfile(cfg):
        cfg = os.path.join(os.path.dirname(d), "wp-config.php")
    text = wpfix._read(cfg, uid) or ""
    out["config"] = cfg
    if not text:
        out["problem"] = "wp-config.php could not be read"
        return out
    out.update(db_name=wpfix.config_value(text, "DB_NAME"), db_user=wpfix.config_value(text, "DB_USER"),
               db_host=wpfix.config_value(text, "DB_HOST"), prefix=table_prefix(text),
               _password=wpfix.config_value(text, "DB_PASSWORD") or "")
    if re.search(r"define\(\s*['\"]MULTISITE['\"]\s*,\s*true", text, re.I):
        out["problem"] = "WordPress multisite is not supported"
    elif not (out["db_name"] and out["db_user"]):
        out["problem"] = "no database settings found in wp-config.php"
    elif not clone.is_local_db(out["db_host"]):
        out["problem"] = f"the database is on another server ({out['db_host']})"
    elif not out["prefix"]:
        out["problem"] = "the table prefix in wp-config.php could not be read"
    return out


def _mysql(inst: dict, sql: str, timeout=120) -> list[list[str]]:
    """Run a query as the install's own database user; rows as lists of strings."""
    cnf = clone._option_file(clone.mycnf(inst["db_user"], inst["_password"], inst["db_name"]))
    try:
        proc = run([clone.MARIADB, f"--defaults-extra-file={cnf}", "-N", "-B", "-e", sql], timeout=timeout, check=False)
    finally:
        os.unlink(cnf)
    if proc.returncode != 0:
        err = (proc.stderr or "").strip().splitlines()[-1:] or ["unknown error"]
        raise ActionError("command_failed", f"Database {inst['db_name']}: {err[0][:300]}")
    return [line.split("\t") for line in (proc.stdout or "").splitlines() if line]


def _tables(inst: dict) -> list[dict]:
    rows = _mysql(inst, "SELECT table_name, ROUND(COALESCE(data_length + index_length, 0) / 1048576, 1) "
                        "FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' "
                        "ORDER BY table_name")
    out = []
    for row in rows:
        if row and _TABLE.match(row[0]):
            try:
                size = float(row[1]) if len(row) > 1 else 0.0
            except ValueError:
                size = 0.0
            out.append({"name": row[0], "size_mb": size})
    return out


def _option(domain: str, path: str, name: str) -> str | None:
    proc = wordpress.wp(domain, ["option", "get", name], path=path, timeout=120, check=False)
    return (proc.stdout or "").strip() if proc.returncode == 0 else None


def _pair(params):
    staging = v.domain(params, "staging")
    live = v.domain(params, "live")
    if staging == live:
        raise ActionError("invalid_params", "The staging copy and the live website are the same website")
    return staging, live, wordpress.clean_path(params.get("path"))


def _plan(staging: str, live: str, path: str) -> dict:
    s_site, l_site = clone._site(staging), clone._site(live)
    s, l = _install(s_site, path), _install(l_site, path)
    problems = [f"{side}: {i['problem']}" for side, i in (("staging", s), ("live", l)) if i["problem"]]
    if not problems and s["prefix"] != l["prefix"]:
        problems.append(f"the table prefixes differ (staging {s['prefix']}, live {l['prefix']})")
    if not problems and s["db_name"] == l["db_name"]:
        problems.append("the staging copy uses the database of the live website")
    tables, live_tables = [], []
    if not problems:
        tables = [t for t in _tables(s) if t["name"].startswith(s["prefix"])]
        live_tables = [t["name"] for t in _tables(l)]
    return {"site": (s_site, l_site), "inst": (s, l), "problems": problems, "tables": tables, "live_tables": live_tables}


@action("wp.staging_plan")
def staging_plan(params):
    """What a push would do: tables of both sides, tables worth keeping, size, problems."""
    staging, live, path = _pair(params)
    p = _plan(staging, live, path)
    s_site, l_site = p["site"]
    s, l = p["inst"]
    live_set = set(p["live_tables"])
    keep = suggested_keep(p["live_tables"], l["prefix"] or "") if not p["problems"] else []
    return {
        "staging": staging, "live": live, "path": path, "problems": p["problems"],
        "staging_url": clone.site_url(s_site) + (f"/{path}" if path else ""),
        "live_url": clone.site_url(l_site) + (f"/{path}" if path else ""),
        "staging_db": s["db_name"], "live_db": l["db_name"], "prefix": s["prefix"],
        "tables": [{**t, "on_live": t["name"] in live_set} for t in p["tables"]],
        "live_only_tables": sorted(live_set - {t["name"] for t in p["tables"]}),
        "suggested_keep": keep,
        "woocommerce": any(t.endswith(("wc_orders", "woocommerce_order_items")) for t in live_set),
        "size_mb": clone._du_mb(s["dir"]) if not p["problems"] else 0,
        "backups": len(_list_backups(live)),
    }


# --------------------------------------------------------------------------- staging marker

@action("wp.staging_prepare")
def staging_prepare(params):
    """Add the staging mu-plugin to a copy (mail held back, noindex, admin bar label)."""
    domain = v.domain(params, "staging")
    path = wordpress.clean_path(params.get("path"))
    site = clone._site(domain)
    inst = _install(site, path)
    if inst["problem"] and not inst["problem"].startswith(("WordPress multisite", "the table prefix")):
        raise ActionError("not_found", inst["problem"])
    mu = os.path.join(inst["dir"], "wp-content", "mu-plugins")
    if not clone.safe_inside(os.path.join(inst["dir"], "wp-content"), site["docroot"]):
        raise ActionError("forbidden", "wp-content is not a plain folder of the website")
    wordpress.as_user(site, ["/usr/bin/mkdir", "-p", "--", mu], cwd=inst["dir"], timeout=60)
    target = os.path.join(inst["dir"], MU_PLUGIN)
    if not os.path.lexists(target):
        wordpress.as_user(site, ["/usr/bin/touch", "--", target], cwd=inst["dir"], timeout=60)
    wpfix._write(target, MU_PLUGIN_CODE, inst["uid"], inst["gid"])
    wordpress.wp(domain, ["option", "update", "blog_public", "0"], path=path, timeout=120, check=False)
    return {"staging": domain, "path": path, "mu_plugin": MU_PLUGIN}


# --------------------------------------------------------------------------- backups of the live website

def _list_backups(live: str) -> list[dict]:
    base = os.path.join(paths.STATE_DIR, "staging", "backups", live)
    out = []
    try:
        names = sorted((n for n in os.listdir(base) if re.fullmatch(_STAMP, n)), reverse=True)
    except OSError:
        return []
    for name in names:
        try:
            with open(os.path.join(base, name, "manifest.json")) as fh:
                m = json.load(fh)
        except (OSError, ValueError):
            continue
        out.append({k: m.get(k) for k in ("id", "live", "path", "created", "reason", "database", "tables", "size_mb", "complete")})
    return out


def _dump(inst: dict, out_path: str, tables: list[str] | None, log) -> None:
    """mariadb-dump as the install's own user, DEFINERs removed. tables=None: the whole database."""
    cnf = clone._option_file(clone.mycnf(inst["db_user"], inst["_password"]))
    raw = out_path + ".raw"
    try:
        argv = [clone.MARIADB_DUMP, f"--defaults-extra-file={cnf}", "--single-transaction", "--quick", "--skip-lock-tables",
                "--no-tablespaces", "--hex-blob", "--triggers", "--add-drop-table", "--default-character-set=utf8mb4",
                f"--result-file={raw}", "--", inst["db_name"]]
        if tables is not None:
            argv += tables
        proc = run(argv, timeout=3600, check=False)
        if proc.returncode != 0:
            err = (proc.stderr or "").strip().splitlines()[-1:] or ["unknown error"]
            raise ActionError("command_failed", f"The database {inst['db_name']} could not be exported: {err[0][:300]}")
        fd = os.open(out_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with open(raw, encoding="utf-8", errors="surrogateescape") as src, \
                os.fdopen(fd, "w", encoding="utf-8", errors="surrogateescape") as dst:
            for line in src:
                dst.write(clone.strip_definers(line))
    finally:
        for f in (cnf, raw):
            try:
                os.unlink(f)
            except FileNotFoundError:
                pass


def _load_dump(inst: dict, dump: str) -> None:
    """Load a dump into the install's database as its own user."""
    cnf = clone._option_file(clone.mycnf(inst["db_user"], inst["_password"], inst["db_name"]))
    try:
        with open(dump, "rb") as fh:
            res = subprocess.run([clone.MARIADB, f"--defaults-extra-file={cnf}", "--default-character-set=utf8mb4", "--binary-mode"],
                                 stdin=fh, capture_output=True, timeout=3600, env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin"})
    finally:
        os.unlink(cnf)
    if res.returncode != 0:
        err = res.stderr.decode(errors="replace").strip().splitlines()[-1:] or ["unknown error"]
        raise ActionError("command_failed", f"The database {inst['db_name']} could not be loaded: {err[0][:300]}")


def make_backup(live_site: dict, inst: dict, reason: str, log) -> dict:
    base = _backups_dir(live_site["domain"])
    stamp = time.strftime("%Y%m%d-%H%M%S")
    while os.path.exists(os.path.join(base, stamp)):
        time.sleep(1)
        stamp = time.strftime("%Y%m%d-%H%M%S")
    d = os.path.join(base, stamp)
    os.mkdir(d, 0o700)
    log(f"backup of {live_site['domain']}: files")
    previous = [b for b in _list_backups(live_site["domain"]) if b.get("complete")]
    argv = ["rsync", "-aH", "--no-specials", "--no-devices", "--safe-links"]
    if previous:
        argv.append(f"--link-dest={os.path.join(base, previous[0]['id'], 'files')}")
    for pattern in clone.EXCLUDES:
        argv.append(f"--exclude=/{pattern}" if not pattern.startswith(".well-known") else "--exclude=/.well-known/")
    argv += ["--", inst["dir"].rstrip("/") + "/", os.path.join(d, "files") + "/"]
    proc = run(argv, timeout=7200, check=False)
    if proc.returncode not in (0, 24):
        shutil.rmtree(d, ignore_errors=True)
        last = (proc.stderr or "").strip().splitlines()[-1:] or [f"exit code {proc.returncode}"]
        raise ActionError("command_failed", f"The backup of the live website failed: {last[0][:300]}")
    log(f"backup of {live_site['domain']}: database {inst['db_name']}")
    try:
        tables = [t["name"] for t in _tables(inst)]
        _dump(inst, os.path.join(d, "database.sql"), None, log)
    except BaseException:
        shutil.rmtree(d, ignore_errors=True)
        raise
    manifest = {"id": stamp, "live": live_site["domain"], "path": inst["path"], "created": int(time.time()), "reason": reason,
                "database": inst["db_name"], "prefix": inst["prefix"], "tables": tables, "size_mb": clone._du_mb(d), "complete": True}
    with open(os.path.join(d, "manifest.json"), "w") as fh:
        json.dump(manifest, fh)
    _prune(live_site["domain"])
    return manifest


def _prune(live: str) -> None:
    base = _backups_dir(live)
    names = sorted((n for n in os.listdir(base) if re.fullmatch(_STAMP, n)), reverse=True)
    for name in names[KEEP_BACKUPS:]:
        full = os.path.join(base, name)
        if not os.path.islink(full):
            shutil.rmtree(full, ignore_errors=True)


def restore_backup(live_site: dict, inst: dict, manifest: dict, log) -> None:
    d = os.path.join(_backups_dir(live_site["domain"]), manifest["id"])
    log(f"restore {manifest['id']}: files")
    argv = ["rsync", "-rlptH", "--no-specials", "--no-devices", "--safe-links", "--delete",
            f"--chown={live_site['user']}:{live_site['user']}", "--exclude=/.well-known/",
            "--", os.path.join(d, "files") + "/", inst["dir"].rstrip("/") + "/"]
    proc = run(argv, timeout=7200, check=False, umask=0o022)
    if proc.returncode not in (0, 24):
        last = (proc.stderr or "").strip().splitlines()[-1:] or [f"exit code {proc.returncode}"]
        raise ActionError("command_failed", f"Putting the files back failed: {last[0][:300]}")
    # The restored wp-config.php may name another database than the one in use now.
    inst = _install(live_site, inst["path"])
    if inst["db_name"] != manifest["database"]:
        raise ActionError("conflict", f"The live website now uses database {inst['db_name']}, the backup is of {manifest['database']}")
    log(f"restore {manifest['id']}: database {inst['db_name']}")
    _load_dump(inst, os.path.join(d, "database.sql"))
    # Tables the push added (a plugin that only existed on staging) go again.
    known = set(manifest.get("tables") or [])
    extra = [t["name"] for t in _tables(inst) if t["name"] not in known and t["name"].startswith(manifest.get("prefix") or "\0")]
    if extra:
        _mysql(inst, "SET FOREIGN_KEY_CHECKS=0; " + " ".join(f"DROP TABLE IF EXISTS `{t}`;" for t in extra))
    run([clone.WHP_SITE, "cache", live_site["domain"], "purge"], timeout=120, check=False)


def _backup_manifest(live: str, backup: str) -> dict:
    for b in _list_backups(live):
        if b["id"] == backup:
            path = os.path.join(_backups_dir(live), backup, "manifest.json")
            with open(path) as fh:
                return json.load(fh)
    raise ActionError("not_found", f"No backup {backup} of {live}")


@action("wp.staging_backups")
def staging_backups(params):
    live = v.domain(params, "live")
    return {"live": live, "backups": _list_backups(live), "keep": KEEP_BACKUPS}


def _claim(*domains):
    with _busy_lock:
        if any(d in _busy for d in domains):
            raise ActionError("conflict", "A push or restore of this website is already running")
        _busy.update(domains)


def _release(*domains):
    with _busy_lock:
        _busy.difference_update(domains)


@action("wp.staging_restore")
def staging_restore(params):
    live = v.domain(params, "live")
    backup = v.string(params, "backup", pattern=_STAMP, max_len=15)
    manifest = _backup_manifest(live, backup)
    site = clone._site(live)
    _claim(live)

    def job(_params, log):
        try:
            inst = _install(site, manifest.get("path") or "")
            if inst["problem"] and not inst["problem"].startswith("no WordPress"):
                raise ActionError("conflict", inst["problem"])
            if inst["problem"]:
                # The push broke the install so badly WordPress is not recognised: use the backup's settings.
                raise ActionError("conflict", f"{inst['problem']}; restore the files from a full backup first")
            restore_backup(site, inst, manifest, log)
            log("done")
            return {"live": live, "backup": backup}
        finally:
            _release(live)

    return start_job("wp.staging_restore", job, {"live": live, "backup": backup})


# --------------------------------------------------------------------------- push

def _table_name(value):
    if not isinstance(value, str) or not _TABLE.match(value):
        raise ActionError("invalid_params", "keep_tables has an invalid table name")
    return value


@action("wp.staging_push")
def staging_push(params):
    staging, live, path = _pair(params)
    files = v.boolean(params, "files", required=False, default=True)
    database = v.boolean(params, "database", required=False, default=True)
    keep_uploads = v.boolean(params, "keep_uploads", required=False, default=True)
    keep = v.list_of(params, "keep_tables", _table_name, required=False, max_items=500)
    if not (files or database):
        raise ActionError("invalid_params", "Choose files, database or both")
    _claim(staging, live)
    try:
        plan = _plan(staging, live, path)
        if plan["problems"]:
            raise ActionError("conflict", plan["problems"][0])
    except BaseException:
        _release(staging, live)
        raise

    def job(_params, log):
        try:
            return _push(plan, path, files, database, keep_uploads, set(keep), log)
        finally:
            _release(staging, live)

    return start_job("wp.staging_push", job, {"staging": staging, "live": live, "path": path})


def _push(plan: dict, path: str, files: bool, database: bool, keep_uploads: bool, keep: set[str], log) -> dict:
    from .wordpress import _healthy, health

    started = time.time()
    s_site, l_site = plan["site"]
    s, l = plan["inst"]
    live_url = _option(l_site["domain"], path, "home") or (clone.site_url(l_site) + (f"/{path}" if path else ""))
    staging_url = _option(s_site["domain"], path, "home") or (clone.site_url(s_site) + (f"/{path}" if path else ""))
    live_base = home_base(live_url) or clone.site_url(l_site)
    blog_public = _option(l_site["domain"], path, "blog_public")
    before = health(live_url)
    log(f"live website answers HTTP {before} before the push")

    backup = make_backup(l_site, l, f"before push from {s_site['domain']}", log)
    result = {"staging": s_site["domain"], "live": l_site["domain"], "path": path, "backup": backup["id"],
              "files": files, "database": database, "kept_tables": sorted(keep), "replacements": 0}
    try:
        if files:
            log(f"files: {s['dir']} -> {l['dir']}")
            proc = run(rsync_push_argv(s["dir"], l["dir"], l_site["user"], keep_uploads), timeout=7200, check=False, umask=0o022)
            _rsync_ok(proc)
            uploads = os.path.join(s["dir"], UPLOADS)
            if keep_uploads and os.path.isdir(uploads) and not os.path.islink(uploads):
                log("files: uploads (media that only exists on live is kept)")
                proc = run(["rsync", "-rlptH", "--no-specials", "--no-devices", "--safe-links",
                            f"--chown={l_site['user']}:{l_site['user']}", "--", uploads + "/",
                            os.path.join(l["dir"], UPLOADS) + "/"], timeout=7200, check=False, umask=0o022)
                _rsync_ok(proc)
            marker = os.path.join(l["dir"], MU_PLUGIN)
            if os.path.isfile(marker) and not os.path.islink(marker):
                os.unlink(marker)
            _fix_files(l, s_site, l_site, live_base)
        if database:
            names = [t["name"] for t in plan["tables"] if t["name"] not in keep]
            if not names:
                raise ActionError("conflict", "No tables left to push")
            dump = os.path.join(clone._work_dir(), f"push-{os.urandom(6).hex()}.sql")
            try:
                log(f"database: {len(names)} tables from {s['db_name']} into {l['db_name']}"
                    + (f", keeping {len(keep)} live tables" if keep else ""))
                _dump(s, dump, names, log)
                _load_dump(l, dump)
            finally:
                try:
                    os.unlink(dump)
                except FileNotFoundError:
                    pass
            staging_hosts = [s_site["domain"], *s_site.get("aliases", [])]
            if home_base(staging_url):
                staging_hosts.append(home_base(staging_url).split("://", 1)[1])
            pairs = push_pairs(staging_hosts, live_base)
            log(f"database: addresses back to {live_base}")
            for old, new in [*pairs, (s_site["docroot"], l_site["docroot"])]:
                proc = wordpress.wp(l_site["domain"], ["search-replace", old, new, "--all-tables-with-prefix", "--skip-columns=guid",
                                                       "--format=count"], path=path, timeout=1800, check=False)
                if proc.returncode != 0:
                    raise wordpress.failure("search-replace", proc)
                m = re.search(r"(\d+)\s*$", (proc.stdout or "").strip())
                result["replacements"] += int(m.group(1)) if m else 0
            if blog_public is not None and re.fullmatch(r"[01]", blog_public):
                wordpress.wp(l_site["domain"], ["option", "update", "blog_public", blog_public], path=path, timeout=120, check=False)
        wordpress.wp(l_site["domain"], ["cache", "flush"], path=path, timeout=120, check=False)
        wordpress.wp(l_site["domain"], ["rewrite", "flush"], path=path, timeout=120, check=False)
        run([clone.WHP_SITE, "cache", l_site["domain"], "purge"], timeout=120, check=False)
        after = health(live_url)
        log(f"live website answers HTTP {after} after the push")
        result["health"] = after
        if _healthy(before) and not _healthy(after):
            raise ActionError("command_failed", f"The live website answered HTTP {after} after the push")
    except BaseException as exc:
        log(f"push failed ({exc}): putting the live website back from backup {backup['id']}")
        try:
            restore_backup(l_site, l, backup, log)
        except BaseException as again:  # report both: the push error and why the automatic restore failed
            raise ActionError("command_failed", f"{_message(exc)} The automatic restore failed as well: {_message(again)}. "
                                                f"Restore backup {backup['id']} by hand.", {"backup": backup["id"]})
        log("the live website is back as it was before the push")
        raise ActionError("command_failed", f"{_message(exc)} The live website was put back as it was before the push.",
                          {"backup": backup["id"], "rolled_back": True})
    log(f"done in {int(time.time() - started)} s")
    return result


def _message(exc: BaseException) -> str:
    text = exc.message if isinstance(exc, ActionError) else str(exc)
    return text.rstrip(".") + "."


def _rsync_ok(proc) -> None:
    if proc.returncode not in (0, 24):
        last = (proc.stderr or "").strip().splitlines()[-1:] or [f"exit code {proc.returncode}"]
        hint = " The disk quota is full." if "quota" in (proc.stderr or "").lower() else ""
        raise ActionError("command_failed", f"Copying the files failed: {last[0][:300]}.{hint}")


def _fix_files(l: dict, s_site: dict, l_site: dict, live_base: str) -> None:
    """Config files next to WordPress (.htaccess, cache and firewall configs) may name the staging folder or address."""
    pairs = push_pairs([s_site["domain"], *s_site.get("aliases", [])], live_base)
    for path in wpfix._candidates(l["dir"], os.path.realpath(l_site["docroot"])):
        if os.path.basename(path) == "wp-config.php":
            continue
        old = wpfix._read(path, l["uid"])
        if old is None:
            continue
        new = clone.replace_hosts(wpfix.replace_path(old, s_site["docroot"], l_site["docroot"]), pairs)
        if new != old:
            wpfix._write(path, new, l["uid"], l["gid"])
