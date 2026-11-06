"""Vulnerability data for WordPress core, plugins and themes.

Two sources, chosen by the panel:

* ``wordfence``: the Wordfence Intelligence vulnerability data feed (v3, needs a
  free API key from wordfence.com, Account > Integrations). The whole feed is
  downloaded once a day (about 160 MB of JSON), read record by record and kept
  in a small SQLite database, so matching never sends anything about the sites
  on this server to a third party.
* ``wpvulnerability``: the WPVulnerability.net API (free, no key). Asked per
  plugin/theme slug and per core version; answers are cached for 12 hours.

Both are turned into the same record shape:

    {"id", "title", "description", "cve", "cvss_score", "severity", "ranges",
     "patched_versions", "remediation", "references", "source", "copyright"}
"""

import json
import os
import re
import sqlite3
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

from . import paths
from .registry import ActionError

DIR = os.path.join(paths.STATE_DIR, "vulndb")
DB_PATH = os.path.join(DIR, "wordfence.sqlite")
CACHE_DIR = os.path.join(DIR, "wpvulnerability")
WORDFENCE_URL = os.environ.get(
    "WHP_WORDFENCE_FEED", "https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production")
WPVULN_URL = os.environ.get("WHP_WPVULN_API", "https://www.wpvulnerability.net/")
CACHE_TTL = 12 * 3600
USER_AGENT = "webhosting-panel-agent (+https://github.com/rhisijmuiden/webhosting-panel)"
SLUG_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,99}$")
VERSION_RE = re.compile(r"^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$")
SEVERITIES = ("critical", "high", "medium", "low", "unknown")


# ---------------------------------------------------------------- versions

_SPECIAL = {"dev": 0, "alpha": 1, "a": 1, "beta": 2, "b": 2, "RC": 3, "rc": 3, "#": 4, "pl": 5, "p": 5}


def _canon(v):
    v = re.sub(r"[-_+]", ".", v.strip())
    v = re.sub(r"(?<=\d)(?=[^\d.])|(?<=[^\d.])(?=\d)", ".", v)
    return [p for p in v.split(".") if p != ""]


def _cmp_part(a, b):
    ad, bd = a.isdigit(), b.isdigit()
    if ad and bd:
        return (int(a) > int(b)) - (int(a) < int(b))
    if not ad and not bd:
        x, y = _SPECIAL.get(a, -1), _SPECIAL.get(b, -1)
        return (x > y) - (x < y)
    if ad:  # number against a word: compare "#" with the word
        return _cmp_part("#", b)
    return _cmp_part(a, "#")


def version_compare(a, b):
    """PHP's version_compare() (what WordPress uses), returning -1, 0 or 1."""
    pa, pb = _canon(a), _canon(b)
    for x, y in zip(pa, pb):
        c = _cmp_part(x, y)
        if c:
            return c
    if len(pa) == len(pb):
        return 0
    if len(pa) > len(pb):
        rest = pa[len(pb)]
        return 1 if rest.isdigit() else _cmp_part(rest, "#")
    rest = pb[len(pa)]
    return -1 if rest.isdigit() else _cmp_part("#", rest)


def _norm(v):
    """1.0 equals 1.0.0, a trailing dot does not count (as Wordfence compares)."""
    v = (v or "").strip().rstrip(".")
    return re.sub(r"(?<=\d)(?:\.0+)+(?=$|[^\d.])", "", v)


def in_range(version, r):
    """r: {"from", "from_inclusive", "to", "to_inclusive"}; "*" means unbounded."""
    version = _norm(version)
    if not version:
        return False
    lo, hi = r.get("from", "*"), r.get("to", "*")
    if lo not in ("*", "", None):
        c = version_compare(version, _norm(lo))
        if c < 0 or (c == 0 and not r.get("from_inclusive", True)):
            return False
    if hi not in ("*", "", None):
        c = version_compare(version, _norm(hi))
        if c > 0 or (c == 0 and not r.get("to_inclusive", True)):
            return False
    return True


def severity(score, rating=None):
    if rating:
        r = str(rating).strip().lower()
        r = {"c": "critical", "h": "high", "m": "medium", "l": "low", "n": "low", "none": "low"}.get(r, r)
        if r in SEVERITIES:
            return r
    try:
        s = float(score)
    except (TypeError, ValueError):
        return "unknown"
    if s >= 9.0:
        return "critical"
    if s >= 7.0:
        return "high"
    if s >= 4.0:
        return "medium"
    return "low" if s > 0 else "unknown"


def fixed_version(installed, record):
    """The lowest patched version above the installed one, or None."""
    best = None
    for p in record.get("patched_versions") or []:
        if not isinstance(p, str) or version_compare(_norm(p), _norm(installed)) <= 0:
            continue
        if best is None or version_compare(p, best) < 0:
            best = p
    return best


# ---------------------------------------------------------------- HTTP

def _open(url, headers=None, timeout=30):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "application/json", **(headers or {})})
    try:
        return urllib.request.urlopen(req, timeout=timeout)  # noqa: S310 - fixed https URLs
    except urllib.error.HTTPError as exc:
        if exc.code in (401, 403):
            raise ActionError("forbidden", "The vulnerability feed refused the API key")
        if exc.code == 429:
            raise ActionError("command_failed", "The vulnerability feed is rate limited, try again later")
        raise ActionError("command_failed", f"Vulnerability feed answered HTTP {exc.code}")
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise ActionError("command_failed", f"Vulnerability feed not reachable: {getattr(exc, 'reason', exc)}")


# ---------------------------------------------------------------- streaming JSON

def iter_object(fh, chunk=1 << 20):
    """Yield (key, value) of a top-level JSON object without loading it whole."""
    dec = json.JSONDecoder()
    buf = ""
    pos = 0
    eof = False

    def more():
        nonlocal buf, pos, eof
        data = fh.read(chunk)
        if isinstance(data, bytes):
            data = data.decode("utf-8", errors="replace")
        if not data:
            eof = True
        buf = buf[pos:] + data
        pos = 0

    def skip_ws():
        nonlocal pos
        while True:
            while pos < len(buf) and buf[pos] in " \t\r\n":
                pos += 1
            if pos < len(buf) or eof:
                return
            more()

    more()
    skip_ws()
    if pos >= len(buf) or buf[pos] != "{":
        raise ActionError("command_failed", "The vulnerability feed is not a JSON object")
    pos += 1
    while True:
        skip_ws()
        if pos < len(buf) and buf[pos] == "}":
            return
        if pos < len(buf) and buf[pos] == ",":
            pos += 1
            skip_ws()
        # key
        while True:
            try:
                key, end = dec.raw_decode(buf, pos)
                break
            except json.JSONDecodeError:
                if eof:
                    raise ActionError("command_failed", "The vulnerability feed is cut off")
                more()
        pos = end
        skip_ws()
        if buf[pos] != ":":
            raise ActionError("command_failed", "The vulnerability feed is not valid JSON")
        pos += 1
        skip_ws()
        while True:
            try:
                value, end = dec.raw_decode(buf, pos)
                break
            except json.JSONDecodeError:
                if eof:
                    raise ActionError("command_failed", "The vulnerability feed is cut off")
                more()
        pos = end
        yield key, value


# ---------------------------------------------------------------- Wordfence

SCHEMA = """
CREATE TABLE vulns (
  id TEXT PRIMARY KEY, title TEXT, description TEXT, cve TEXT, cve_link TEXT,
  cvss_score REAL, cvss_rating TEXT, cvss_vector TEXT, cwe TEXT, refs TEXT,
  published TEXT, updated TEXT, copyright TEXT
);
CREATE TABLE software (
  vuln_id TEXT, type TEXT, slug TEXT, name TEXT, ranges TEXT, patched INTEGER,
  patched_versions TEXT, remediation TEXT
);
CREATE INDEX software_slug ON software (type, slug);
CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);
"""


def _copyright(rec):
    c = rec.get("copyrights")
    if not isinstance(c, dict):
        return None
    notices = []
    for party, n in c.items():
        if party != "message" and isinstance(n, dict) and n.get("notice"):
            notices.append(str(n["notice"]))
    return " ".join(notices)[:1000] or None


def _ranges_wordfence(affected):
    out = []
    for r in (affected or {}).values() if isinstance(affected, dict) else []:
        if isinstance(r, dict):
            out.append({"from": str(r.get("from_version", "*")), "from_inclusive": bool(r.get("from_inclusive", True)),
                        "to": str(r.get("to_version", "*")), "to_inclusive": bool(r.get("to_inclusive", True))})
    return out


def import_wordfence(fh, db_path=DB_PATH, log=lambda s: None):
    """Read a Wordfence v3 feed from a file object into a new SQLite database."""
    os.makedirs(os.path.dirname(db_path), mode=0o750, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".wordfence-", suffix=".sqlite", dir=os.path.dirname(db_path))
    os.close(fd)
    con = sqlite3.connect(tmp)
    count = 0
    try:
        con.executescript(SCHEMA)
        for vid, rec in iter_object(fh):
            if not isinstance(rec, dict) or rec.get("informational"):
                continue
            cvss = rec.get("cvss") if isinstance(rec.get("cvss"), dict) else {}
            cwe = rec.get("cwe") if isinstance(rec.get("cwe"), dict) else {}
            con.execute("INSERT OR REPLACE INTO vulns VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", (
                str(rec.get("id") or vid), str(rec.get("title") or "")[:500], (rec.get("description") or "")[:4000],
                rec.get("cve"), rec.get("cve_link"), cvss.get("score"), cvss.get("rating"), cvss.get("vector"),
                cwe.get("name"), json.dumps([r for r in rec.get("references") or [] if isinstance(r, str)][:5]),
                rec.get("published"), rec.get("updated"), _copyright(rec),
            ))
            for sw in rec.get("software") or []:
                if not isinstance(sw, dict) or sw.get("type") not in ("core", "plugin", "theme"):
                    continue
                con.execute("INSERT INTO software VALUES (?,?,?,?,?,?,?,?)", (
                    str(rec.get("id") or vid), sw["type"], str(sw.get("slug") or "").lower(), sw.get("name"),
                    json.dumps(_ranges_wordfence(sw.get("affected_versions"))), 1 if sw.get("patched") else 0,
                    json.dumps([p for p in sw.get("patched_versions") or [] if isinstance(p, str)]),
                    (sw.get("remediation") or None),
                ))
            count += 1
            if count % 5000 == 0:
                log(f"{count} records read")
        con.execute("INSERT INTO meta VALUES ('updated_at', ?), ('records', ?), ('source', 'wordfence')",
                    (str(int(time.time())), str(count)))
        con.commit()
        con.close()
        os.chmod(tmp, 0o640)
        os.replace(tmp, db_path)
    except BaseException:
        con.close()
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise
    return count


def sync_wordfence(api_key, log=lambda s: None):
    if not api_key or not re.fullmatch(r"[A-Za-z0-9._-]{16,256}", api_key):
        raise ActionError("invalid_params", "A valid Wordfence Intelligence API key is needed")
    log("Downloading the Wordfence Intelligence feed")
    os.makedirs(DIR, mode=0o750, exist_ok=True)
    with tempfile.TemporaryFile(dir=DIR) as raw:
        with _open(WORDFENCE_URL, {"Authorization": f"Bearer {api_key}"}, timeout=120) as resp:
            size = 0
            while True:
                block = resp.read(1 << 20)
                if not block:
                    break
                raw.write(block)
                size += len(block)
        log(f"Downloaded {size // (1024 * 1024)} MB, building the local database")
        raw.seek(0)
        count = import_wordfence(raw, log=log)
    log(f"{count} vulnerabilities stored")
    return {"source": "wordfence", "records": count, "updated_at": int(time.time())}


def wordfence_status():
    if not os.path.exists(DB_PATH):
        return {"source": "wordfence", "ready": False, "records": 0, "updated_at": None}
    con = sqlite3.connect(f"file:{DB_PATH}?mode=ro", uri=True)
    try:
        meta = dict(con.execute("SELECT key, value FROM meta").fetchall())
    finally:
        con.close()
    return {"source": "wordfence", "ready": True, "records": int(meta.get("records", 0)),
            "updated_at": int(meta["updated_at"]) if meta.get("updated_at") else None}


def lookup_wordfence(kind, slug, con):
    rows = con.execute(
        "SELECT v.id, v.title, v.description, v.cve, v.cve_link, v.cvss_score, v.cvss_rating, v.refs, v.published,"
        " v.copyright, s.ranges, s.patched_versions, s.remediation, s.name"
        " FROM software s JOIN vulns v ON v.id = s.vuln_id WHERE s.type = ? AND s.slug = ?", (kind, slug)).fetchall()
    out = []
    for r in rows:
        refs = json.loads(r[7] or "[]")
        if r[4] and r[4] not in refs:
            refs.insert(0, r[4])
        out.append({
            "id": r[0], "title": r[1], "description": r[2], "cve": r[3], "cvss_score": r[5],
            "severity": severity(r[5], r[6]), "ranges": json.loads(r[10] or "[]"),
            "patched_versions": json.loads(r[11] or "[]"), "remediation": r[12], "references": refs[:5],
            "published": r[8], "source": "wordfence", "copyright": r[9], "software_name": r[13],
        })
    return out


# ---------------------------------------------------------------- WPVulnerability

_OPS = {"ge": (True, "from"), "gt": (False, "from"), "le": (True, "to"), "lt": (False, "to")}


def _record_wpvuln(v):
    op = v.get("operator") or {}
    r = {"from": "*", "from_inclusive": True, "to": "*", "to_inclusive": True}
    for side in ("min", "max"):
        o, ver = op.get(f"{side}_operator"), op.get(f"{side}_version")
        if o in _OPS and ver:
            inclusive, key = _OPS[o]
            r[key] = str(ver)
            r[f"{key}_inclusive"] = inclusive
    patched = []
    if r["to"] != "*" and not r["to_inclusive"] and not int(op.get("unfixed") or 0):
        patched = [r["to"]]
    sources = [s for s in v.get("source") or [] if isinstance(s, dict)]
    cve = next((s.get("id") or s.get("name") for s in sources
                if str(s.get("id") or s.get("name") or "").upper().startswith("CVE-")), None)
    cvss = ((v.get("impact") or {}).get("cvss") or {}) if isinstance(v.get("impact"), dict) else {}
    desc = next((s.get("description") for s in sources if s.get("description")), None) or v.get("description")
    return {
        "id": str(v.get("uuid") or v.get("name") or ""), "title": str(v.get("name") or "")[:500],
        "description": (desc or "")[:4000], "cve": cve, "cvss_score": _float(cvss.get("score")),
        "severity": severity(cvss.get("score"), cvss.get("severity")), "ranges": [r],
        "patched_versions": patched, "remediation": None,
        "references": [s.get("link") for s in sources if s.get("link")][:5],
        "published": next((s.get("date") for s in sources if s.get("date")), None),
        "source": "wpvulnerability", "copyright": None,
        "unfixed": bool(int(op.get("unfixed") or 0)), "closed": bool(int(op.get("closed") or 0)),
    }


def _float(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def _wpvuln_get(kind, key):
    """GET /{kind}/{key}/ with a 12 hour cache on disk."""
    cache = os.path.join(CACHE_DIR, kind, f"{key}.json")
    try:
        if time.time() - os.path.getmtime(cache) < CACHE_TTL:
            with open(cache) as fh:
                return json.load(fh)
    except (OSError, ValueError):
        pass
    url = urllib.parse.urljoin(WPVULN_URL, f"{kind}/{urllib.parse.quote(key)}/")
    with _open(url, timeout=20) as resp:
        try:
            data = json.loads(resp.read(5 << 20).decode("utf-8", errors="replace"))
        except ValueError:
            raise ActionError("command_failed", "WPVulnerability returned invalid JSON")
    os.makedirs(os.path.dirname(cache), mode=0o750, exist_ok=True)
    tmp = cache + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(data, fh)
    os.replace(tmp, cache)
    return data


def lookup_wpvulnerability(kind, slug, version):
    data = _wpvuln_get(kind, version if kind == "core" else slug)
    if not isinstance(data, dict) or data.get("error"):
        return []
    vulns = ((data.get("data") or {}).get("vulnerability") or []) if isinstance(data.get("data"), dict) else []
    out = []
    for v in vulns:
        if not isinstance(v, dict):
            continue
        rec = _record_wpvuln(v)
        if kind == "core":  # the core endpoint only lists what affects this exact version
            rec["ranges"] = [{"from": version, "from_inclusive": True, "to": version, "to_inclusive": True}]
        out.append(rec)
    return out


# ---------------------------------------------------------------- matching

def match(items, source):
    """items: [{"type", "slug", "version"}]. Returns the items that are vulnerable, each with
    its vulnerabilities and the version that fixes them."""
    con = None
    if source == "wordfence":
        if not os.path.exists(DB_PATH):
            raise ActionError("not_found", "The Wordfence database has not been downloaded yet")
        con = sqlite3.connect(f"file:{DB_PATH}?mode=ro", uri=True)
    results, errors = [], []
    try:
        for item in items:
            kind, slug, version = item["type"], item["slug"], item["version"]
            try:
                records = lookup_wordfence(kind, slug, con) if con else lookup_wpvulnerability(kind, slug, version)
            except ActionError as exc:
                errors.append(f"{kind} {slug}: {exc.message}")
                continue
            hits = []
            for rec in records:
                if any(in_range(version, r) for r in rec["ranges"]):
                    rec = dict(rec)
                    rec["fixed_in"] = fixed_version(version, rec)
                    rec.pop("ranges", None)
                    hits.append(rec)
            if hits:
                results.append({"type": kind, "slug": slug, "version": version, "vulnerabilities": hits})
    finally:
        if con:
            con.close()
    return {"source": source, "matches": results, "errors": errors[:20], "checked": len(items)}
