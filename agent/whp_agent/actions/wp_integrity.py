"""WordPress integrity check: compare core and plugin files with the originals on
WordPress.org (WP-CLI verify-checksums), report what changed, link it to the
malware scan and put the original files back.

    wpint.check    check one install now (background: true starts a job)
    wpint.report   the last stored check of an install
    wpint.restore  put original files back / move added files to quarantine (job)

Checksums come from WordPress.org: core files of every release and plugins in the
WordPress.org directory. Themes and commercial plugins have no published
checksums; they are listed as "cannot be verified".
"""

import hashlib
import json
import os
import re
import stat
import time
from pathlib import Path

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

STATE = Path(paths.STATE_DIR) / "wp-integrity"
MALWARE_DIR = os.environ.get("WHP_MALWARE_DIR", os.path.join(paths.STATE_DIR, "malware"))
MALWARE_SCAN = "/usr/local/sbin/whp-malware-scan"
WORKER = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "wp_integrity_worker.py")
PYTHON = "/usr/bin/python3"
MAX_FILES = 500
MAX_HASH_BYTES = 20 * 1024 * 1024
_SLUG = re.compile(r"^[a-z0-9][a-z0-9._-]{0,99}$")
_VERSION = re.compile(r"^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$")
_LOCALE_RE = re.compile(r"^\$wp_local_package\s*=\s*'([A-Za-z_]{2,12})'\s*;", re.M)

# WP-CLI `core verify-checksums` prints one warning per file.
_CORE_LINES = [
    (re.compile(r"File doesn't verify against checksum: (.+)$"), "modified"),
    (re.compile(r"File doesn't exist: (.+)$"), "missing"),
    (re.compile(r"File should not exist: (.+)$"), "added"),
]
_PLUGIN_MESSAGES = {"checksum does not match": "modified", "file is missing": "missing", "file was added": "added"}
_PLUGIN_SKIP = re.compile(r"Could not retrieve the checksums for version (\S+) of plugin (\S+?),? skipping", re.I)


def _domain_path(params):
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    return domain, path


def _key(domain, path):
    site = wordpress.site_info(domain)
    suffix = "root" if not path else hashlib.sha1(path.encode()).hexdigest()[:8]
    return f"{site['id'] or domain}-{suffix}"


def read_locale(wp_dir):
    try:
        with open(os.path.join(wp_dir, "wp-includes", "version.php"), errors="replace") as fh:
            m = _LOCALE_RE.search(fh.read(20000))
    except OSError:
        return "en_US"
    return m.group(1) if m else "en_US"


def _file_info(wp_dir, rel):
    """md5, size and mtime of a file in the install, read without following symlinks."""
    full = os.path.join(wp_dir, rel)
    real_parent = os.path.realpath(os.path.dirname(full))
    if real_parent != wp_dir and not real_parent.startswith(wp_dir + os.sep):
        return {}
    try:
        fd = os.open(full, os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_NONBLOCK", 0))
    except OSError:
        return {}
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode):
            return {}
        info = {"size": st.st_size, "mtime": int(st.st_mtime)}
        if st.st_size <= MAX_HASH_BYTES:
            h = hashlib.md5()
            with os.fdopen(os.dup(fd), "rb") as fh:
                for chunk in iter(lambda: fh.read(1024 * 1024), b""):
                    h.update(chunk)
            info["md5"] = h.hexdigest()
        return info
    finally:
        os.close(fd)


def _malware_hits(domain, wp_dir):
    """Malware scan hits inside this install: {relative path: signature}."""
    try:
        with open(os.path.join(MALWARE_DIR, f"{domain}.json")) as fh:
            data = json.load(fh)
    except (OSError, ValueError):
        return {}, None
    hits = {}
    prefix = wp_dir + os.sep
    for f in data.get("findings") or []:
        path = str(f.get("file") or "")
        if path.startswith(prefix):
            hits[path[len(prefix):]] = str(f.get("signature") or "")[:200]
    return hits, data.get("scanned_at")


def _core(domain, path, wp_dir):
    proc = wordpress.wp(domain, ["core", "verify-checksums"], path=path, timeout=300, check=False)
    text = "\n".join(x for x in (proc.stderr or "", proc.stdout or "") if x)
    files = []
    for line in text.splitlines():
        line = re.sub(r"^(PHP )?Warning:\s*", "", line.strip())
        for rx, issue in _CORE_LINES:
            m = rx.search(line)
            if m:
                files.append({"file": m.group(1).strip(), "issue": issue})
                break
    if files:
        status = "changed"
        message = None
    elif proc.returncode == 0 and "verifies against checksums" in text:
        status, message = "ok", None
    elif re.search(r"(Couldn't|Could not) get checksums", text, re.I):
        status, message = "unverifiable", "WordPress.org has no checksums for this version"
    else:
        status, message = "error", wordpress.explain(proc)
    return {"type": "core", "slug": "wordpress", "name": "WordPress", "version": wordpress.read_version(wp_dir),
            "locale": read_locale(wp_dir), "status": status, "message": message, "base": "", "files": files}


def _plugins(domain, path, errors):
    try:
        rows = wordpress.wp_json(domain, ["plugin", "list", "--format=json", "--fields=name,title,version,status"], path=path, timeout=180)
    except ActionError as exc:
        errors.append(f"plugins: {exc.message}")
        return []
    plugins = {}
    for r in rows if isinstance(rows, list) else []:
        name = str(r.get("name") or "")
        if r.get("status") in ("must-use", "dropin") or not _SLUG.fullmatch(name):
            continue
        plugins[name] = {"type": "plugin", "slug": name, "name": str(r.get("title") or name)[:200],
                         "version": str(r.get("version") or ""), "active": r.get("status") in ("active", "active-network"),
                         "status": "ok", "message": None, "base": f"wp-content/plugins/{name}", "files": []}
    if not plugins:
        return []
    proc = wordpress.wp(domain, ["plugin", "verify-checksums", "--all", "--format=json"], path=path, timeout=600, check=False)
    out = (proc.stdout or "").strip()
    rows = []
    for line in reversed(out.splitlines()):
        if line.strip()[:1] == "[":
            try:
                rows = json.loads(line.strip())
            except ValueError:
                pass
            break
    for r in rows:
        slug = str(r.get("plugin_name") or "")
        if slug not in plugins:
            continue
        issue = _PLUGIN_MESSAGES.get(str(r.get("message") or "").strip().lower(), "modified")
        plugins[slug]["files"].append({"file": str(r.get("file") or ""), "issue": issue})
        plugins[slug]["status"] = "changed"
    text = "\n".join(x for x in (proc.stderr or "", proc.stdout or "") if x)
    for m in _PLUGIN_SKIP.finditer(text):
        slug = m.group(2).strip().rstrip(",.")
        if slug in plugins and plugins[slug]["status"] == "ok":
            plugins[slug]["status"] = "unverifiable"
            plugins[slug]["message"] = "Not in the WordPress.org plugin directory (commercial or custom plugin)"
    if proc.returncode != 0 and not rows and not _PLUGIN_SKIP.search(text):
        errors.append(f"plugin checksums: {wordpress.explain(proc)}")
        for p in plugins.values():
            p["status"] = "error"
    return sorted(plugins.values(), key=lambda p: (p["status"] != "changed", p["slug"]))


def check(domain, path):
    """Check an install and store the report."""
    site, wp_dir = wordpress.require_install(domain, path)
    wp_dir = os.path.realpath(wp_dir)
    errors = []
    components = [_core(domain, path, wp_dir), *_plugins(domain, path, errors)]
    malware, malware_at = _malware_hits(domain, wp_dir)
    counted = set()
    for c in components:
        c["total_files"] = len(c["files"])
        c["files"] = c["files"][:MAX_FILES]
        for f in c["files"]:
            rel = f"{c['base']}/{f['file']}" if c["base"] else f["file"]
            if f["issue"] != "missing":
                f.update(_file_info(wp_dir, rel))
            if rel in malware:
                f["malware"] = malware[rel]
                counted.add(rel)
    # Malware hits in core or plugin folders that the checksums did not flag (e.g. an unverifiable plugin).
    other = []
    for rel, sig in sorted(malware.items()):
        if rel not in counted:
            other.append({"file": rel, "signature": sig})
    changed = [c for c in components if c["status"] == "changed"]
    report = {
        "domain": domain, "path": path, "version": components[0]["version"],
        # Where the install lives below the site root (for links to the file manager).
        "root_rel": os.path.relpath(wp_dir, os.path.realpath(site["root"])), "locale": components[0]["locale"],
        "status": "changed" if changed else ("error" if components[0]["status"] == "error" else "ok"),
        "changed_files": sum(c["total_files"] for c in changed),
        "components": components,
        "malware_elsewhere": other[:100],
        "malware_scanned_at": malware_at,
        "errors": errors,
        "checked_at": int(time.time()),
    }
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    tmp = STATE / f".{_key(domain, path)}.json"
    tmp.write_text(json.dumps(report))
    os.replace(tmp, STATE / f"{_key(domain, path)}.json")
    return report


def load(domain, path):
    try:
        return json.loads((STATE / f"{_key(domain, path)}.json").read_text())
    except (OSError, ValueError):
        return None


@action("wpint.check")
def wpint_check(params):
    domain, path = _domain_path(params)
    wordpress.require_install(domain, path)
    if v.boolean(params, "background", required=False, default=False):
        def work(_params, log):
            log(f"Checking {domain}/{path}")
            return check(domain, path)

        return start_job("wpint.check", work, {})
    return check(domain, path)


@action("wpint.report")
def wpint_report(params):
    domain, path = _domain_path(params)
    return load(domain, path) or {"domain": domain, "path": path, "checked_at": None, "components": []}


# ---------------------------------------------------------------- restore

def _zip_url(component):
    version = component.get("version") or ""
    if not _VERSION.fullmatch(version):
        raise ActionError("conflict", f"Unknown version of {component['slug']}, cannot download the original")
    if component["type"] == "core":
        locale = component.get("locale") or "en_US"
        if locale == "en_US":
            return f"https://downloads.wordpress.org/release/wordpress-{version}.zip", "wordpress/"
        return f"https://downloads.wordpress.org/release/{locale}/wordpress-{version}.zip", "wordpress/"
    return f"https://downloads.wordpress.org/plugin/{component['slug']}.{version}.zip", f"{component['slug']}/"


def _selection(x):
    if not isinstance(x, dict):
        raise ActionError("invalid_params", "items must be objects")
    kind = x.get("type")
    slug = str(x.get("slug") or "")
    if kind not in ("core", "plugin") or not _SLUG.fullmatch(slug):
        raise ActionError("invalid_params", "Each item needs type core/plugin and a slug")
    files = x.get("files", "all")
    if files != "all" and (not isinstance(files, list) or len(files) > MAX_FILES or not all(isinstance(f, str) for f in files)):
        raise ActionError("invalid_params", "files must be 'all' or a list of paths")
    return {"type": kind, "slug": slug, "files": files, "quarantine_added": bool(x.get("quarantine_added", False))}


def plan(report, selections):
    """Worker items for the chosen components; only files the last check reported."""
    by_key = {(c["type"], c["slug"]): c for c in report.get("components") or []}
    items = []
    for s in selections:
        c = by_key.get((s["type"], s["slug"]))
        if not c or c.get("status") != "changed":
            raise ActionError("conflict", f"{s['slug']} has no changed files in the last check; run the check again")
        wanted = None if s["files"] == "all" else set(s["files"])
        restore, remove = [], []
        for f in c["files"]:
            if wanted is not None and f["file"] not in wanted:
                continue
            if f["issue"] == "added":
                if s["quarantine_added"]:
                    remove.append(f["file"])
            else:
                restore.append(f["file"])
        if not restore and not remove:
            continue
        url, prefix = _zip_url(c) if restore else ("", "")
        items.append({"key": f"{c['type']}:{c['slug']}", "url": url, "prefix": prefix, "base": c["base"],
                      "restore": restore, "remove": remove, "malware": any(f.get("malware") for f in c["files"])})
    if not items:
        raise ActionError("invalid_params", "Nothing to restore")
    return items


def restore(domain, path, selections, log):
    site, wp_dir = wordpress.require_install(domain, path)
    wp_dir = os.path.realpath(wp_dir)
    report = load(domain, path)
    if not report:
        raise ActionError("conflict", "Run the integrity check first")
    items = plan(report, selections)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    request = {
        "install_dir": wp_dir,
        "quarantine": os.path.join(site["root"], "quarantine", "wordpress-integrity", stamp),
        "items": [{k: i[k] for k in ("key", "url", "prefix", "base", "restore", "remove")} for i in items],
    }
    for i in items:
        log(f"{i['key']}: restoring {len(i['restore'])} file(s), quarantining {len(i['remove'])}")
    proc = wordpress.as_user(site, [PYTHON, "-I", WORKER], cwd=wp_dir, input_text=json.dumps(request), timeout=1200)
    try:
        out = json.loads((proc.stdout or "").strip().splitlines()[-1])
    except (ValueError, IndexError):
        raise ActionError("command_failed", "The restore worker gave no answer", {"stderr": (proc.stderr or "")[-1000:]})
    if not out.get("ok"):
        raise ActionError("command_failed", f"Restore failed: {out.get('error')}")
    restored = sum(len(r["restored"]) for r in out["results"])
    quarantined = sum(len(r["quarantined"]) for r in out["results"])
    errors = [e for r in out["results"] for e in r["errors"]]
    for e in errors:
        log(f"Error: {e}")
    log(f"Restored {restored} file(s), moved {quarantined} file(s) to quarantine")
    rescanned = False
    if any(i["malware"] for i in items) and os.access(MALWARE_SCAN, os.X_OK):
        log("Scanning the website for malware again")
        run([MALWARE_SCAN, domain], timeout=3600, check=False)
        rescanned = True
    log("Checking the files again")
    after = check(domain, path)
    return {
        "restored": restored, "quarantined": quarantined, "errors": errors,
        "quarantine_dir": request["quarantine"][len(site["root"]) + 1:] if quarantined else None,
        "malware_rescanned": rescanned, "report": after,
    }


@action("wpint.restore")
def wpint_restore(params):
    domain, path = _domain_path(params)
    selections = v.list_of(params, "items", _selection, max_items=200)
    if not selections:
        raise ActionError("invalid_params", "Choose what to restore")
    wordpress.require_install(domain, path)
    if not load(domain, path):
        raise ActionError("conflict", "Run the integrity check first")

    def work(_params, log):
        return restore(domain, path, selections, log)

    return start_job("wpint.restore", work, {})
