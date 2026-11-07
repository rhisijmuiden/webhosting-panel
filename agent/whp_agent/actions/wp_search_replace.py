"""WordPress search & replace and the change-domain wizard.

Both run WP-CLI `search-replace` as the site user (wordpress.wp()), which keeps
PHP serialized data intact: it unserializes, replaces and serializes again, so
string lengths in options and post meta stay right.

  wp.sr_addresses  the current home/siteurl and the addresses this website serves
  wp.sr_preview    job: dry run, counts per table/column plus sample changes
  wp.sr_apply      job: snapshot first, replace, flush caches, check the site and
                   put the snapshot back automatically when it broke

Two modes:
  text    a plain string -> string replacement the user typed
  domain  every link to the current address (http and https, with and without
          www, also JSON-escaped https:\\/\\/) becomes the new address. Done
          with a regex that only matches whole host names: old.nl never touches
          old.nl.au or old.nlx.com. The WP_HOME / WP_SITEURL / COOKIE_DOMAIN
          constants in wp-config.php follow.
"""

import os
import re
import secrets
import threading
from urllib.parse import urlsplit

from .. import validate as v
from .. import wordpress
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run
from . import wordpress as wpa
from .sites import _site

TIMEOUT = 3600
SAMPLES = 40
LOG_READ_LIMIT = 512 * 1024
MIN_SEARCH = 3
MAX_TEXT = 1000
# A host must end here: old.nl must not match old.nl.au or old.nlx.com.
HOST_END = r"(?![A-Za-z0-9-]|\.[A-Za-z0-9])"
CONSTANTS = ("WP_HOME", "WP_SITEURL")

_busy = set()
_busy_lock = threading.Lock()


# ---------------------------------------------------------------- pure helpers

def host_base(host):
    """old.nl for www.old.nl: links with and without www both move."""
    host = host.lower()
    return host[4:] if host.startswith("www.") and host.count(".") >= 2 else host


def url_host(url):
    """The host (with port, if any) of an absolute http(s) URL, or None."""
    try:
        parts = urlsplit((url or "").strip())
    except ValueError:
        return None
    if parts.scheme not in ("http", "https") or not parts.netloc or "@" in parts.netloc:
        return None
    return parts.netloc.lower()


def domain_patterns(old_hosts, new_base):
    """(pattern, replacement, label) for the WP-CLI regex mode, one plain and one JSON-escaped per host.

    The replacement is escaped for preg_replace: a backslash is doubled, $ is escaped."""
    out = []
    escaped_new = new_base.replace("/", "\\/")
    for base in sorted(dict.fromkeys(host_base(h) for h in old_hosts if h), key=len, reverse=True):
        host = r"(?:www\.)?" + re.escape(base)
        out.append((r"https?://" + host + HOST_END, _preg_replacement(new_base), f"http(s)://(www.){base}"))
        out.append((r"https?:\\/\\/" + host + HOST_END, _preg_replacement(escaped_new), f"http(s):\\/\\/(www.){base}"))
    return out


def _preg_replacement(text):
    return text.replace("\\", "\\\\").replace("$", "\\$")


def rewrite_url(value, old_hosts, new_base):
    """Apply the domain change to one value in Python (wp-config constants)."""
    for base in sorted(dict.fromkeys(host_base(h) for h in old_hosts if h), key=len, reverse=True):
        value = re.sub(r"https?://(?:www\.)?" + re.escape(base) + HOST_END, lambda _m: new_base, value, flags=re.I)
    return value


def parse_report(stdout):
    """Rows of the search-replace report. WP-CLI prints a tab separated table when it writes to a pipe."""
    rows = []
    total = 0
    for line in (stdout or "").splitlines():
        cells = [c.strip() for c in (line.split("\t") if "\t" in line else line.strip("|").split("|"))]
        if len(cells) >= 3 and cells[2].isdigit() and cells[0] not in ("", "Table"):
            count = int(cells[2])
            total += count
            if count:
                rows.append({"table": cells[0], "column": cells[1], "count": count,
                             "type": cells[3] if len(cells) > 3 else ""})
    m = re.search(r"(?:Made|^Success:)\D*?(\d+)\s+replacement", stdout or "", re.M)
    if m and not rows:
        total = int(m.group(1))
    return {"total": total, "tables": rows}


def parse_log(text, limit=SAMPLES):
    """Sample changes from a WP-CLI --log file:

        wp_options.option_value:1
        < ...old context...
        > ...new context...
    """
    samples = []
    cur = None
    for line in (text or "").splitlines():
        m = re.match(r"^([A-Za-z0-9_$]+)\.([A-Za-z0-9_$]+):(\S{1,64})$", line)
        if m:
            if cur:
                samples.append(cur)
                if len(samples) >= limit:
                    return samples, True
            cur = {"table": m.group(1), "column": m.group(2), "row": m.group(3), "old": "", "new": ""}
        elif cur is not None and line.startswith("< "):
            cur["old"] = (cur["old"] + "\n" if cur["old"] else "") + line[2:][:400]
        elif cur is not None and line.startswith("> "):
            cur["new"] = (cur["new"] + "\n" if cur["new"] else "") + line[2:][:400]
    if cur:
        samples.append(cur)
    return samples[:limit], len(samples) > limit


# ---------------------------------------------------------------- params

def _text(params, key, minimum):
    value = params.get(key)
    if not isinstance(value, str) or len(value) > MAX_TEXT:
        raise ActionError("invalid_params", f"{key} must be a string of at most {MAX_TEXT} characters")
    if "\x00" in value or "\r" in value:
        raise ActionError("invalid_params", f"{key} contains invalid characters")
    if len(value) < minimum:
        raise ActionError("invalid_params", f"{key} must be at least {minimum} characters")
    if value.startswith("-"):
        # WP-CLI would read it as an option.
        raise ActionError("invalid_params", f"{key} must not start with a dash")
    return value


def _common(params):
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    job = {
        "domain": domain, "path": path,
        "mode": v.choice(params, "mode", ["text", "domain"]),
        "tables": v.choice(params, "tables", ["wordpress", "all"], required=False, default="wordpress"),
        "skip_guid": v.boolean(params, "skip_guid", required=False, default=True),
    }
    if job["mode"] == "text":
        job["search"] = _text(params, "search", MIN_SEARCH)
        job["replace"] = _text(params, "replace", 0)
        if job["search"] == job["replace"]:
            raise ActionError("invalid_params", "search and replace are the same")
    else:
        job["host"] = v.domain(params, "host")
        job["scheme"] = v.choice(params, "scheme", ["https", "http"], required=False, default="https")
    return job


def _flags(job):
    flags = ["--all-tables" if job["tables"] == "all" else "--all-tables-with-prefix", "--report-changed-only"]
    if job["skip_guid"]:
        flags.append("--skip-columns=guid")
    return flags


def _argvs(job, plan):
    """The search-replace calls (without --dry-run / --log) for a job and its plan."""
    if job["mode"] == "text":
        return [["search-replace", job["search"], job["replace"], *_flags(job)]]
    return [["search-replace", pattern, repl, "--regex", "--regex-flags=i", "--regex-delimiter=#", *_flags(job)]
            for pattern, repl, _label in domain_patterns(plan["old_hosts"], plan["new_base"])]


# ---------------------------------------------------------------- addresses

def _option(domain, path, name):
    proc = wordpress.wp(domain, ["option", "get", name], path=path, timeout=120, check=False)
    lines = (proc.stdout or "").strip().splitlines()
    return lines[-1].strip() if proc.returncode == 0 and lines else ""


def _constant(domain, path, name):
    proc = wordpress.wp(domain, ["config", "get", name, "--type=constant"], path=path, timeout=60, check=False)
    return (proc.stdout or "").strip() if proc.returncode == 0 else None


def _multisite(domain, path):
    return wordpress.wp(domain, ["core", "is-installed", "--network"], path=path, timeout=60, check=False).returncode == 0


def served_hosts(domain):
    """Every host this website answers on itself (not the redirecting aliases): {host: ssl active}."""
    site = wordpress.site_info(domain)
    ssl = site["ssl"] in ("active", "imported", "custom", "wildcard")
    hosts = {domain: ssl}
    for alias in site["aliases"]:
        hosts.setdefault(alias.lower(), ssl)
    try:
        listed = _site(["alias", "list", domain], timeout=30).get("domain_aliases", [])
    except ActionError:
        listed = []
    for a in listed if isinstance(listed, list) else []:
        if a.get("mode") != "serve":
            continue
        for name in a.get("names", [a.get("domain")]):
            if name:
                hosts.setdefault(str(name).lower(), a.get("ssl") == "active")
    return hosts


def addresses(domain, path):
    wordpress.require_install(domain, path)
    home, siteurl = _option(domain, path, "home"), _option(domain, path, "siteurl")
    return {
        "domain": domain, "path": path, "home": home, "siteurl": siteurl,
        "constants": {c: _constant(domain, path, c) for c in CONSTANTS},
        "multisite": _multisite(domain, path),
        "hosts": [{"host": h, "ssl": s} for h, s in sorted(served_hosts(domain).items(), key=lambda kv: (len(kv[0]), kv[0]))],
    }


def _plan(job):
    """What a domain change does: from which hosts to which address."""
    if job["mode"] != "domain":
        return {}
    info = addresses(job["domain"], job["path"])
    if info["multisite"]:
        raise ActionError("conflict", "This is a WordPress multisite network; changing its domain is not supported here")
    hosts = dict((h["host"], h["ssl"]) for h in info["hosts"])
    if job["host"] not in hosts:
        raise ActionError("forbidden", f"{job['host']} is not served by {job['domain']}: add it as a domain alias first")
    if job["scheme"] == "https" and not hosts[job["host"]]:
        raise ActionError("conflict", f"{job['host']} has no SSL certificate yet; use http or wait for the certificate")
    old_hosts = [h for h in dict.fromkeys(url_host(u) for u in (info["home"], info["siteurl"], *info["constants"].values())) if h]
    if not old_hosts:
        raise ActionError("conflict", "The current address of this WordPress could not be read")
    new_base = f"{job['scheme']}://{job['host']}"
    old_home = info["home"].rstrip("/")
    new_home = rewrite_url(old_home, old_hosts, new_base)
    if new_home == old_home:
        raise ActionError("invalid_params", "WordPress already uses this address")
    return {"old_hosts": old_hosts, "new_base": new_base, "old_home": old_home, "new_home": new_home,
            "constants": info["constants"]}


def _pairs(job, plan):
    if job["mode"] == "text":
        return [{"search": job["search"], "replace": job["replace"]}]
    return [{"search": label, "replace": plan["new_base"]} for _p, _r, label in domain_patterns(plan["old_hosts"], plan["new_base"])][::2]


# ---------------------------------------------------------------- run

def _merge(reports):
    tables = {}
    total = 0
    for r in reports:
        total += r["total"]
        for row in r["tables"]:
            key = (row["table"], row["column"])
            if key in tables:
                tables[key]["count"] += row["count"]
            else:
                tables[key] = dict(row)
    return {"total": total, "tables": sorted(tables.values(), key=lambda t: (-t["count"], t["table"], t["column"]))}


def _search_replace(job, plan, dry_run, log):
    """Run every search-replace of the job; with dry_run also collect sample changes."""
    site = wordpress.site_info(job["domain"])
    reports, samples, truncated = [], [], False
    for argv in _argvs(job, plan):
        extra = ["--dry-run"] if dry_run else []
        log_file = None
        if dry_run and len(samples) < SAMPLES:
            log_file = os.path.join(site["root"], "tmp", f"whp-sr-{secrets.token_hex(8)}.log")
            extra.append(f"--log={log_file}")
        try:
            proc = wordpress.wp(job["domain"], [*argv, *extra], path=job["path"], timeout=TIMEOUT, check=False)
            if proc.returncode != 0:
                raise wordpress.failure("search-replace", proc)
            report = parse_report(proc.stdout)
            reports.append(report)
            log(f"{'Dry run' if dry_run else 'Replaced'}: {report['total']} in {len(report['tables'])} columns")
            if log_file:
                got, more = parse_log(_read_head(log_file), SAMPLES - len(samples))
                samples += got
                truncated = truncated or more
        finally:
            if log_file:
                _remove(log_file)
    out = _merge(reports)
    if dry_run:
        out["samples"] = samples
        out["samples_truncated"] = truncated or len(samples) < sum(t["count"] for t in out["tables"])
    return out


def _read_head(path):
    try:
        if os.path.islink(path):
            return ""
        with open(path, "rb") as fh:
            return fh.read(LOG_READ_LIMIT).decode("utf-8", "replace")
    except OSError:
        return ""


def _remove(path):
    try:
        os.unlink(path)
    except OSError:
        pass


def _claim(job):
    key = (job["domain"], job["path"])
    with _busy_lock:
        if key in _busy:
            raise ActionError("conflict", "A search & replace is already running for this WordPress")
        _busy.add(key)
    return key


def _release(key):
    with _busy_lock:
        _busy.discard(key)


def preview(job, log):
    key = _claim(job)
    try:
        plan = _plan(job)
        log("Dry run: nothing is changed")
        out = _search_replace(job, plan, True, log)
        return {"domain": job["domain"], "path": job["path"], "mode": job["mode"], "pairs": _pairs(job, plan),
                "old_home": plan.get("old_home"), "new_home": plan.get("new_home"), **out}
    finally:
        _release(key)


def _set_constants(job, plan, log):
    """WP_HOME / WP_SITEURL / COOKIE_DOMAIN in wp-config.php follow the new address. Returns the old values."""
    changed = {}
    for name, value in plan["constants"].items():
        if not value:
            continue
        new = rewrite_url(value, plan["old_hosts"], plan["new_base"])
        if new != value:
            wordpress.wp(job["domain"], ["config", "set", name, new, "--type=constant"], path=job["path"], timeout=60)
            changed[name] = value
            log(f"wp-config.php: {name} = {new}")
    cookie = _constant(job["domain"], job["path"], "COOKIE_DOMAIN")
    if cookie and host_base(cookie.lstrip(".")) in {host_base(h.split(":")[0]) for h in plan["old_hosts"]}:
        new = ("." if cookie.startswith(".") else "") + job["host"]
        wordpress.wp(job["domain"], ["config", "set", "COOKIE_DOMAIN", new, "--type=constant"], path=job["path"], timeout=60)
        changed["COOKIE_DOMAIN"] = cookie
        log(f"wp-config.php: COOKIE_DOMAIN = {new}")
    return changed


def _flush(job):
    wordpress.wp(job["domain"], ["cache", "flush"], path=job["path"], timeout=120, check=False)
    wordpress.wp(job["domain"], ["transient", "delete", "--all"], path=job["path"], timeout=300, check=False)
    run([wpa.WHP_SITE, "cache", job["domain"], "purge"], timeout=120, check=False)


def apply(job, log):
    key = _claim(job)
    try:
        plan = _plan(job)
        old_url = plan.get("old_home") or wpa._site_url(job["domain"], job["path"])
        before = wpa.health(old_url)
        log(f"{old_url} answers HTTP {before} before the change")
        snap = wpa.snapshot(job["domain"], job["path"], log) if job["snapshot"] else None
        constants = {}
        try:
            if job["mode"] == "domain":
                constants = _set_constants(job, plan, log)
            out = _search_replace(job, plan, False, log)
        except ActionError:
            _rollback(job, snap, constants, log)
            raise
        _flush(job)
        new_url = plan.get("new_home") or old_url
        after = wpa.health(new_url)
        log(f"{new_url} answers HTTP {after} after the change")
        rolled_back = False
        if snap and wpa._healthy(before) and not wpa._healthy(after):
            _rollback(job, snap, constants, log)
            rolled_back = True
        result = {"domain": job["domain"], "path": job["path"], "mode": job["mode"], "pairs": _pairs(job, plan),
                  "old_home": plan.get("old_home"), "new_home": plan.get("new_home"), "constants": sorted(constants),
                  "snapshot": snap.name if snap else None, "health": {"before": before, "after": after},
                  "rolled_back": rolled_back, **out}
        if rolled_back:
            raise ActionError("command_failed", f"The site broke after the change (HTTP {after}) and was put back", result)
        return result
    finally:
        _release(key)


def _rollback(job, snap, constants, log):
    log("Putting the snapshot back" if snap else "Putting wp-config.php back")
    for name, value in constants.items():
        wordpress.wp(job["domain"], ["config", "set", name, value, "--type=constant"], path=job["path"], timeout=60, check=False)
    if snap:
        wordpress.wp(job["domain"], ["db", "import", str(snap / "database.sql"), "--quiet"], path=job["path"], timeout=TIMEOUT)
    _flush(job)
    log("Rollback finished")


# ---------------------------------------------------------------- actions

@action("wp.sr_addresses")
def wp_sr_addresses(params):
    """Current WordPress address, wp-config constants and the hosts this website serves (for the domain wizard)."""
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    return addresses(domain, path)


@action("wp.sr_preview")
def wp_sr_preview(params):
    """Job: dry run of a search & replace or domain change. Nothing is written."""
    job = _common(params)
    wordpress.require_install(job["domain"], job["path"])
    return start_job("wp.sr_preview", lambda p, log: preview(p, log), job)


@action("wp.sr_apply")
def wp_sr_apply(params):
    """Job: snapshot, search & replace (or domain change), flush caches, health check, roll back when broken."""
    job = _common(params)
    job["snapshot"] = v.boolean(params, "snapshot", required=False, default=True)
    wordpress.require_install(job["domain"], job["path"])
    return start_job("wp.sr_apply", lambda p, log: apply(p, log), job)
