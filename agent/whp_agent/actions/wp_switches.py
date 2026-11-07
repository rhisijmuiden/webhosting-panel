"""WordPress quick switches: maintenance mode, debug mode (with a debug.log
viewer), search engine indexing and clearing every cache in one click.

  wp.switches          state of all switches of one install
  wp.maintenance_set   on/off; visitors get a 503 page from a mu-plugin
  wp.debug_set         on/off; WP_DEBUG + WP_DEBUG_LOG outside the docroot
  wp.indexing_set      on/off; the blog_public option ("discourage search engines")
  wp.cache_clear       page cache (Nginx), object cache, cache plugins, transients
  wp.debug_log         newest entries of the debug log (filter by level / text)
  wp.debug_log_clear   empty the debug log, or delete a public wp-content/debug.log

WordPress work runs as the site user through wordpress.wp() / as_user().

Where the debug output goes: the PHP-FPM pool of a site fixes error_log to
logs/php-error.log (php_admin_value, WordPress cannot change it), so errors of
web requests land there. WP-CLI and the WP-cron timer run PHP on the command
line, where WordPress does use WP_DEBUG_LOG: that is set to logs/wp-debug.log.
Both live in /var/www/vhosts/<domain>/logs/, never under the docroot where
anybody could download them. The viewer merges the two ("web" and "cron").
That folder belongs to the site user, so the logs are opened step by step with
O_NOFOLLOW and only a regular file with a single link that belongs to the site
user (or root) is read or emptied.
"""

import json
import os
import pwd
import re
import stat
import time
from datetime import datetime
from pathlib import Path

from .. import validate as v
from .. import wordpress
from ..registry import ActionError, action
from ..run import run

WHP_SITE = "/usr/local/sbin/whp-site"
MU_FILE = "whp-maintenance.php"
MU_SOURCE = Path(__file__).resolve().parent.parent / "data" / MU_FILE
OPTION = "whp_maintenance"
READ_BYTES = 2 * 1024 * 1024   # how much of the end of the log is read
MAX_ENTRY_CHARS = 8000
DEFAULT_ENTRIES = 200
MAX_ENTRIES = 1000
LEVELS = ("error", "warning", "notice", "deprecated", "other")
PHP_LOG = "php-error.log"  # error_log of the site's PHP-FPM pool (installer/templates/php/pool-site.conf)

_ENTRY = re.compile(r"^\[(?P<time>\d\d-[A-Za-z]{3}-\d{4} \d\d:\d\d:\d\d)(?: (?P<tz>[^\]]{1,40}))?\] (?P<message>.*)$")
_KIND = re.compile(r"^PHP (?P<kind>Fatal error|Parse error|Recoverable fatal error|Catchable fatal error|Warning|Notice|Deprecated|Strict Standards|User Error|User Warning|User Notice|User Deprecated)s?:", re.I)
_LEVEL_OF = {
    "fatal error": "error", "parse error": "error", "recoverable fatal error": "error",
    "catchable fatal error": "error", "user error": "error",
    "warning": "warning", "user warning": "warning",
    "notice": "notice", "user notice": "notice",
    "deprecated": "deprecated", "user deprecated": "deprecated", "strict standards": "deprecated",
}

# One WP-CLI call that reads everything the switches need (plugins skipped).
_STATE_PHP = (
    "$o = get_option('whp_maintenance');"
    "echo json_encode(["
    " 'debug' => defined('WP_DEBUG') && WP_DEBUG,"
    " 'debug_log' => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false,"
    " 'debug_display' => defined('WP_DEBUG_DISPLAY') ? (bool) WP_DEBUG_DISPLAY : true,"
    " 'blog_public' => (string) get_option('blog_public', '1'),"
    " 'maintenance' => is_array($o) ? $o : null,"
    "]);"
)

# Cache plugins that keep their own cache: ask each one that is active to empty it.
_PLUGINS_PHP = r"""
$done = [];
$try = function ($name, $fn) use (&$done) { try { $fn(); $done[] = $name; } catch (\Throwable $e) { $done[] = $name.' (failed: '.substr($e->getMessage(), 0, 120).')'; } };
if (function_exists('rocket_clean_domain')) { $try('WP Rocket', function () { rocket_clean_domain(); if (function_exists('rocket_clean_minify')) { rocket_clean_minify(); } }); }
if (function_exists('w3tc_flush_all')) { $try('W3 Total Cache', function () { w3tc_flush_all(); }); }
if (function_exists('wp_cache_clear_cache')) { $try('WP Super Cache', function () { wp_cache_clear_cache(); }); }
if (defined('LSCWP_V')) { $try('LiteSpeed Cache', function () { do_action('litespeed_purge_all'); }); }
if (function_exists('wpfc_clear_all_cache')) { $try('WP Fastest Cache', function () { wpfc_clear_all_cache(true); }); }
if (function_exists('sg_cachepress_purge_cache')) { $try('SiteGround Optimizer', function () { sg_cachepress_purge_cache(); }); }
if (class_exists('Breeze_PurgeCache')) { $try('Breeze', function () { Breeze_PurgeCache::breeze_cache_flush(); }); }
if (class_exists('autoptimizeCache')) { $try('Autoptimize', function () { autoptimizeCache::clearall(); }); }
if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) { $try('Elementor CSS', function () { \Elementor\Plugin::$instance->files_manager->clear_cache(); }); }
if (function_exists('wp_cache_flush')) { wp_cache_flush(); }
echo json_encode(['plugins' => $done]);
"""


def _target(params):
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    site, d = wordpress.require_install(domain, path)
    return domain, path, site, d


def log_name(path):
    """File name of an install's debug log in the site's logs/ folder."""
    return "wp-debug.log" if not path else "wp-debug-" + path.replace("/", "-").replace(".", "_").lower() + ".log"


def log_path(site, path):
    return os.path.join(site["root"], "logs", log_name(path))


def _mu_target(d):
    return os.path.join(d, "wp-content", "mu-plugins", MU_FILE)


def _regular(p):
    try:
        st = os.lstat(p)
    except OSError:
        return None
    return st if stat.S_ISREG(st.st_mode) else None


def _last_json(out):
    for line in reversed((out or "").strip().splitlines()):
        line = line.strip()
        if line[:1] == "{":
            try:
                return json.loads(line)
            except ValueError:
                break
    return None


def _purge_page_cache(domain):
    """Remove the site's pages from the Nginx page cache (also when the cache is off: harmless)."""
    proc = run([WHP_SITE, "cache", domain, "purge", "--json"], timeout=120, check=False)
    return proc.returncode == 0


# ---------------------------------------------------------------- state

def state(domain, path, site=None, d=None):
    if site is None:
        site, d = wordpress.require_install(domain, path)
    proc = wordpress.wp(domain, ["eval", _STATE_PHP], path=path, timeout=60)
    data = _last_json(proc.stdout) or {}
    ours = log_path(site, path)
    debug_log = data.get("debug_log")
    if debug_log is True:
        where = "public"  # WordPress default: wp-content/debug.log, downloadable by anybody
    elif isinstance(debug_log, str) and debug_log:
        where = "panel" if os.path.normpath(debug_log) == ours else "custom"
    else:
        where = None
    mu = _regular(_mu_target(d))
    sizes = [st.st_size for st in (_regular(os.path.join(site["root"], "logs", n)) for _, n in _logs(path)) if st]
    newest = [st.st_mtime for st in (_regular(os.path.join(site["root"], "logs", n)) for _, n in _logs(path)) if st]
    public = _regular(os.path.join(d, "wp-content", "debug.log"))
    maint = data.get("maintenance") if isinstance(data.get("maintenance"), dict) else {}
    return {
        "domain": domain, "path": path,
        "maintenance": mu is not None,
        "maintenance_since": int(mu.st_mtime) if mu else None,
        "maintenance_title": maint.get("title") or "",
        "maintenance_message": maint.get("message") or "",
        "debug": bool(data.get("debug")),
        "debug_log": where,
        "debug_display": bool(data.get("debug_display")) if data.get("debug") else False,
        "indexing": str(data.get("blog_public", "1")) != "0",
        "log": {"file": log_name(path), "size": sum(sizes),
                "modified": int(max(newest)) if newest else None, "exists": bool(sizes)},
        "public_log": {"size": public.st_size, "modified": int(public.st_mtime)} if public else None,
        "checked_at": int(time.time()),
    }


@action("wp.switches")
def wp_switches(params):
    """Maintenance, debug, indexing and the debug log of one install."""
    domain, path, site, d = _target(params)
    return state(domain, path, site, d)


# ---------------------------------------------------------------- maintenance

@action("wp.maintenance_set")
def wp_maintenance_set(params):
    """Visitors get a maintenance page (503 + Retry-After); logged-in editors see the site."""
    domain, path, site, d = _target(params)
    enabled = v.boolean(params, "enabled")
    if enabled:
        title = v.string(params, "title", required=False, default="", max_len=120).strip()
        message = params.get("message") or ""
        if not isinstance(message, str) or len(message) > 1000 or "\x00" in message:
            raise ActionError("invalid_params", "message must be a text of at most 1000 characters")
        message = message.replace("\r\n", "\n").strip()
        lang = v.choice(params, "lang", ["en", "nl"], required=False, default="en")
        option = json.dumps({"title": title, "message": message, "lang": lang, "retry_after": 3600})
        wordpress.wp(domain, ["option", "update", OPTION, option, "--format=json", "--autoload=yes"], path=path, timeout=60)
        # Written by the site user into their own folder; the content comes over stdin.
        wordpress.as_user(site, ["/bin/sh", "-c", f"mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/{MU_FILE}"],
                          cwd=d, input_text=MU_SOURCE.read_text(), timeout=60)
    else:
        wordpress.as_user(site, ["/usr/bin/rm", "-f", "--", f"wp-content/mu-plugins/{MU_FILE}"], cwd=d, timeout=60)
    _purge_page_cache(domain)
    return state(domain, path, site, d)


# ---------------------------------------------------------------- debug

@action("wp.debug_set")
def wp_debug_set(params):
    """WP_DEBUG on with the log in the site's logs/ folder (errors never on screen), or off."""
    domain, path, site, d = _target(params)
    enabled = v.boolean(params, "enabled")
    if enabled:
        target = log_path(site, path)
        logs = os.path.dirname(target)
        if not os.path.isdir(logs) or os.path.islink(logs):
            raise ActionError("conflict", "The logs folder of this website is missing")
        steps = [
            ["WP_DEBUG", "true", "--raw"],
            ["WP_DEBUG_LOG", target],
            ["WP_DEBUG_DISPLAY", "false", "--raw"],
        ]
    else:
        steps = [["WP_DEBUG", "false", "--raw"], ["WP_DEBUG_LOG", "false", "--raw"], ["WP_DEBUG_DISPLAY", "false", "--raw"]]
    for name, value, *extra in steps:
        config_set(domain, path, name, value, *extra)
    return state(domain, path, site, d)


def config_set(domain, path, name, value, *extra):
    """wp config set; a wp-config.php without the usual "stop editing" line gets the constant at the top."""
    argv = ["config", "set", name, value, *extra, "--type=constant"]
    proc = wordpress.wp(domain, argv, path=path, timeout=60, check=False)
    if proc.returncode != 0 and "anchor" in wordpress.explain(proc).lower():
        proc = wordpress.wp(domain, [*argv, "--anchor=<?php", "--placement=after"], path=path, timeout=60, check=False)
    if proc.returncode != 0:
        raise wordpress.failure(f"config set {name}", proc)


# ---------------------------------------------------------------- indexing

@action("wp.indexing_set")
def wp_indexing_set(params):
    """Let search engines index the site (blog_public = 1) or ask them not to (0)."""
    domain, path, site, d = _target(params)
    enabled = v.boolean(params, "enabled")
    wordpress.wp(domain, ["option", "update", "blog_public", "1" if enabled else "0"], path=path, timeout=60)
    _purge_page_cache(domain)
    return state(domain, path, site, d)


# ---------------------------------------------------------------- caches

@action("wp.cache_clear")
def wp_cache_clear(params):
    """Empty every cache of the site: Nginx page cache, object cache, cache plugins, expired transients."""
    domain, path, site, d = _target(params)
    steps = []
    steps.append({"step": "page_cache", "ok": _purge_page_cache(domain)})
    proc = wordpress.wp(domain, ["cache", "flush"], path=path, timeout=120, check=False)
    steps.append({"step": "object_cache", "ok": proc.returncode == 0,
                  "error": None if proc.returncode == 0 else wordpress.explain(proc)})
    # With plugins loaded: a broken plugin may fail here, the other steps still count.
    proc = wordpress.wp(domain, ["eval", _PLUGINS_PHP], path=path, timeout=180, check=False, plugins=True)
    found = (_last_json(proc.stdout) or {}).get("plugins") if proc.returncode == 0 else None
    steps.append({"step": "plugins", "ok": proc.returncode == 0,
                  "plugins": [str(p)[:160] for p in found] if isinstance(found, list) else [],
                  "error": None if proc.returncode == 0 else wordpress.explain(proc)})
    proc = wordpress.wp(domain, ["transient", "delete", "--expired"], path=path, timeout=120, check=False)
    steps.append({"step": "transients", "ok": proc.returncode == 0})
    return {"domain": domain, "path": path, "steps": steps, "ok": all(s["ok"] for s in steps),
            "cleared_at": int(time.time())}


# ---------------------------------------------------------------- debug log

def _logs(path):
    """(source, file name) of the logs the debug viewer shows."""
    return [("web", PHP_LOG), ("cron", log_name(path))]


def _open_log(site, name, write=False):
    """fd + stat of a log in the site's logs/ folder, opened without following any link (None when missing)."""
    vhosts = os.path.dirname(site["root"])
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC
    fd = os.open(vhosts, flags)
    try:
        for part in (os.path.basename(site["root"]), "logs"):
            nfd = os.open(part, flags | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = nfd
        mode = (os.O_WRONLY if write else os.O_RDONLY) | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
        lfd = os.open(name, mode, dir_fd=fd)
    except FileNotFoundError:
        return None, None
    except OSError as e:
        raise ActionError("forbidden", f"The debug log cannot be opened safely ({e.strerror})")
    finally:
        os.close(fd)
    st = os.fstat(lfd)
    uid = _uid(site["user"])
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or (uid is not None and st.st_uid not in (uid, 0)):
        os.close(lfd)
        raise ActionError("forbidden", "The debug log is not a regular file of the website")
    return lfd, st


def _uid(user):
    try:
        return pwd.getpwnam(user).pw_uid
    except (KeyError, TypeError):
        return None


def level_of(message):
    m = _KIND.match(message)
    if m:
        return _LEVEL_OF.get(m.group("kind").lower(), "other")
    low = message[:200].lower()
    if "fatal" in low or low.startswith("wordpress database error"):
        return "error"
    return "other"


def parse(text, cut=False):
    """Entries (oldest first): lines that start with [date] begin one, other lines (stack traces) belong to it.
    cut: the text starts somewhere in the file, so lines before the first [date] are dropped."""
    entries = []
    for line in text.split("\n"):
        line = line.rstrip("\r")
        m = _ENTRY.match(line)
        if cut and not m:
            continue
        cut = False
        if m:
            entries.append({"time": m.group("time"), "tz": m.group("tz") or "", "message": m.group("message")})
        elif entries and line:
            if len(entries[-1]["message"]) < MAX_ENTRY_CHARS:
                entries[-1]["message"] += "\n" + line
        elif line:
            entries.append({"time": "", "tz": "", "message": line})
    for e in entries:
        e["message"] = e["message"][:MAX_ENTRY_CHARS]
        e["level"] = level_of(e["message"])
    return entries


def _tail(site, name):
    """(text of the end of the file, stat, cut) or (None, None, False) when it does not exist."""
    fd, st = _open_log(site, name)
    if fd is None:
        return None, None, False
    try:
        start = max(0, st.st_size - READ_BYTES)
        os.lseek(fd, start, os.SEEK_SET)
        data = b""
        while len(data) < READ_BYTES:
            chunk = os.read(fd, min(65536, READ_BYTES - len(data)))
            if not chunk:
                break
            data += chunk
    finally:
        os.close(fd)
    return data.decode("utf-8", errors="replace"), st, bool(start)


def _when(entry):
    try:
        return datetime.strptime(entry["time"], "%d-%b-%Y %H:%M:%S").timestamp()
    except ValueError:
        return 0.0


@action("wp.debug_log")
def wp_debug_log(params):
    """Newest entries of the PHP error log (web requests) and wp-debug.log (WP-CLI, WP-cron), merged;
    level, source and q (text) filter them."""
    domain, path, site, _ = _target(params)
    limit = v.integer(params, "limit", required=False, default=DEFAULT_ENTRIES, minimum=1, maximum=MAX_ENTRIES)
    level = v.choice(params, "level", ["all", *LEVELS], required=False, default="all")
    source = v.choice(params, "source", ["all", "web", "cron"], required=False, default="all")
    q = v.string(params, "q", required=False, default="", max_len=200).strip().lower()
    out = {"domain": domain, "path": path, "file": log_name(path), "entries": [], "counts": {k: 0 for k in LEVELS},
           "files": [], "exists": False, "size": 0, "modified": None, "truncated": False, "matched": 0}
    entries = []
    for src, name in _logs(path):
        text, st, cut = _tail(site, name)
        if text is None:
            continue
        out["files"].append({"source": src, "file": name, "size": st.st_size, "modified": int(st.st_mtime)})
        out["exists"] = True
        out["size"] += st.st_size
        out["modified"] = max(out["modified"] or 0, int(st.st_mtime))
        out["truncated"] = out["truncated"] or cut
        for e in parse(text, cut=cut):
            e["source"] = src
            entries.append(e)
    entries.sort(key=_when)  # stable: lines without a time keep their place
    for e in entries:
        out["counts"][e["level"]] += 1
    picked = [e for e in reversed(entries)
              if (level == "all" or e["level"] == level) and (source == "all" or e["source"] == source)
              and (not q or q in e["message"].lower())]
    out.update(entries=picked[:limit], matched=len(picked))
    return out


@action("wp.debug_log_clear")
def wp_debug_log_clear(params):
    """Empty the logs the viewer shows (which=panel) or delete a public wp-content/debug.log (which=public)."""
    domain, path, site, d = _target(params)
    which = v.choice(params, "which", ["panel", "public"], required=False, default="panel")
    if which == "public":
        wordpress.as_user(site, ["/usr/bin/rm", "-f", "--", "wp-content/debug.log"], cwd=d, timeout=60)
        return {"domain": domain, "path": path, "which": which, "cleared": True}
    cleared = []
    for _, name in _logs(path):
        fd, _st = _open_log(site, name, write=True)
        if fd is not None:
            try:
                os.ftruncate(fd, 0)
            finally:
                os.close(fd)
            cleared.append(name)
    return {"domain": domain, "path": path, "which": which, "cleared": bool(cleared), "files": cleared}
