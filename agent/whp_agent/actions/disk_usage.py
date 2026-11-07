"""Disk usage explorer: which folders, databases and mailboxes fill a website.

disk.scan runs diskscan.py over the website root (low priority, separate
process) and keeps the result in /var/lib/webhosting-panel/disk-usage/<domain>.json.
disk.usage then answers from that file, one folder at a time, so the panel
can drill down without walking the disk again. disk.scan_all refreshes every
website (the panel runs it at night) and disk.summary gives the panel the
totals for its overview, with the size of every MariaDB database.
"""

import json
import os
import threading
import time

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run
from . import databases

SCANNER = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "diskscan.py")
PYTHON = "/usr/bin/python3"
SCAN_TIMEOUT = 3 * 3600
MAX_PATH = 4096

_lock = threading.Lock()
_running: dict[str, str] = {}


def cache_dir():
    return os.environ.get("WHP_DISK_USAGE_DIR", os.path.join(paths.STATE_DIR, "disk-usage"))


def _cache_file(domain):
    return os.path.join(cache_dir(), f"{domain}.json")


def _load(domain):
    try:
        with open(_cache_file(domain)) as fh:
            data = json.load(fh)
    except (FileNotFoundError, ValueError):
        return None
    return data if isinstance(data, dict) and isinstance(data.get("tree"), dict) else None


def _save(domain, data):
    os.makedirs(cache_dir(), mode=0o700, exist_ok=True)
    tmp = _cache_file(domain) + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(data, fh, separators=(",", ":"))
    os.chmod(tmp, 0o600)
    os.replace(tmp, _cache_file(domain))


def scanner_argv(root):
    return ["/usr/bin/nice", "-n", "15", "/usr/bin/ionice", "-c", "3", PYTHON, "-I", "-S", SCANNER, root]


def scan_site(domain, log=None):
    """Scan one website now and store the result. Returns the short summary."""
    site = wordpress.site_info(domain)
    root = site["root"]
    if not os.path.isdir(root):
        raise ActionError("not_found", f"The folder of {domain} does not exist")
    started = time.time()
    proc = run(scanner_argv(root), timeout=SCAN_TIMEOUT)
    try:
        data = json.loads(proc.stdout)
    except ValueError:
        raise ActionError("command_failed", f"The disk scan of {domain} gave no result")
    data["domain"] = domain
    data["seconds"] = round(time.time() - started, 1)
    _save(domain, data)
    if log:
        log(f"{domain}: {data['tree']['s']} bytes in {data['tree']['f']} files ({data['seconds']} s)")
    return {"domain": domain, "total_bytes": data["tree"]["s"], "scanned_at": data.get("scanned_at"), "partial": data.get("partial", False)}


def _site_domains():
    try:
        names = os.listdir(wordpress.SITES_DB)
    except FileNotFoundError:
        return []
    return sorted(n[:-4] for n in names if n.endswith(".env"))


@action("disk.scan")
def disk_scan(params):
    """Start a scan of one website (or report the scan that is already running)."""
    domain = v.domain(params, "domain")
    wordpress.site_info(domain)  # not_found for an unknown website
    with _lock:
        if domain in _running:
            return {"job": _running[domain], "already_running": True}

        def work(_params, log):
            try:
                return scan_site(domain, log)
            finally:
                with _lock:
                    _running.pop(domain, None)

        job = start_job("disk.scan", work, {"domain": domain})
        _running[domain] = job["job"]
    return job | {"already_running": False}


@action("disk.scan_all")
def disk_scan_all(params):
    """Refresh every website whose last scan is older than max_age_hours (default 20)."""
    max_age = v.integer(params, "max_age_hours", required=False, default=20, minimum=0, maximum=24 * 365)

    def work(_params, log):
        done, skipped, failed = [], 0, []
        for domain in _site_domains():
            cached = _load(domain)
            if cached and time.time() - int(cached.get("scanned_at") or 0) < max_age * 3600:
                skipped += 1
                continue
            with _lock:
                if domain in _running:
                    skipped += 1
                    continue
                _running[domain] = "scan_all"
            try:
                done.append(scan_site(domain, log)["domain"])
            except ActionError as exc:
                failed.append({"domain": domain, "error": exc.message})
                log(f"{domain}: {exc.message}")
            finally:
                with _lock:
                    _running.pop(domain, None)
        return {"scanned": done, "skipped": skipped, "failed": failed}

    return start_job("disk.scan_all", work, {})


def _rel_path(params):
    value = v._get(params, "path", False, "")
    if not isinstance(value, str) or len(value) > MAX_PATH or "\x00" in value:
        raise ActionError("invalid_params", "path must be a folder inside the website")
    parts = [p for p in value.strip("/").split("/") if p not in ("", ".")]
    if any(p == ".." for p in parts):
        raise ActionError("invalid_params", "path must be a folder inside the website")
    return parts


def _child(node, name):
    for c in node.get("c", []):
        if c.get("n") == name:
            return c
    return None


def _present(node, path):
    other = node.get("o") or [0, 0]
    return {
        "path": path,
        "name": node.get("n", ""),
        "bytes": node.get("s", 0),
        "files": node.get("f", 0),
        "folders": node.get("d", 0),
        "direct_bytes": node.get("fs", 0),
        "direct_files": node.get("fc", 0),
        "truncated": bool(node.get("x")),
        "other": {"bytes": other[0], "count": other[1]},
        "children": [{
            "name": c.get("n", ""), "bytes": c.get("s", 0), "files": c.get("f", 0), "folders": c.get("d", 0),
            "truncated": bool(c.get("x")), "has_children": bool(c.get("c")) or bool(c.get("x")),
        } for c in node.get("c", [])],
        "top_files": [{"name": t[0], "bytes": t[1], "mtime": t[2]} for t in node.get("top", [])],
    }


@action("disk.usage")
def disk_usage(params):
    """The last scan of a website, opened at one folder (path relative to the site root)."""
    domain = v.domain(params, "domain")
    parts = _rel_path(params)
    with _lock:
        scanning = domain in _running
    data = _load(domain)
    if data is None:
        return {"domain": domain, "scanned_at": None, "scanning": scanning, "node": None}
    tree = data["tree"]
    node, found = tree, []
    for part in parts:
        child = _child(node, part)
        if child is None:
            break
        node = child
        found.append(part)
    result = {
        "domain": domain,
        "scanned_at": data.get("scanned_at"),
        "seconds": data.get("seconds"),
        "scanning": scanning,
        "partial": bool(data.get("partial")),
        "errors": data.get("errors", 0),
        "total_bytes": tree.get("s", 0),
        "total_files": tree.get("f", 0),
        "found": len(found) == len(parts),
        "node": _present(node, "/".join(found)),
    }
    if not found:
        result["largest"] = data.get("largest", [])
    return result


@action("disk.summary")
def disk_summary(params):
    """Totals for the panel overview: every scanned website and every database."""
    sites = {}
    for domain in _site_domains():
        data = _load(domain)
        if data:
            tree = data["tree"]
            sites[domain] = {"bytes": tree.get("s", 0), "files": tree.get("f", 0), "scanned_at": data.get("scanned_at")}
    try:
        db_sizes = databases._sizes()
    except ActionError:
        db_sizes = {}
    with _lock:
        running = sorted(d for d in _running)
    return {"sites": sites, "databases": db_sizes, "scanning": running}
