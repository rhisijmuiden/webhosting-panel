"""Website logs: the Nginx access and error log, the PHP error log and the
PHP-FPM log of one website, for the log viewer in the panel.

Where the logs are (written by the installer's templates):

  access  /var/www/vhosts/<domain>/logs/access.log     Nginx, format "whp"
  error   /var/www/vhosts/<domain>/logs/error.log      Nginx error log
  php     /var/www/vhosts/<domain>/logs/php-error.log  PHP error_log of the pool
  fpm     /var/log/webhosting-panel/php-fpm/<id>.log   PHP-FPM master of the site

logrotate keeps old copies next to them (access.log.1, access.log.2.gz, ...),
see installer/modules/56-site-logs.sh.

The site folder and its logs/ folder belong to the website's own user, who
can put symlinks or hard links there. So every path is opened step by step
with O_NOFOLLOW from the vhosts folder, and only regular files with a single
link are read. Nothing is ever written.

sitelog.read reads the newest matching entries from the end of a file (or
streams a compressed copy), and gives a cursor (inode + offset). Passing that
cursor back as "after" returns only what was added since: the live tail.
"""

from __future__ import annotations

import collections
import gzip
import os
import re
import stat
import zlib
from datetime import datetime, timezone

from whp_agent import paths
from whp_agent import validate as v
from whp_agent.registry import ActionError, action

VHOSTS = os.environ.get("WHP_VHOSTS", "/var/www/vhosts")
FPM_DIR = os.path.join(paths.LOG_DIR, "php-fpm")

LOGS = {
    "access": "access.log",
    "error": "error.log",
    "php": "php-error.log",
    "fpm": None,  # <site id>.log in FPM_DIR
}
MAX_LINES = 1000
DEFAULT_LINES = 300
MAX_LINE_CHARS = 4000          # longer lines are cut (a single request can be huge)
SCAN_BYTES = 64 * 1024 * 1024  # how far back a search reads in a plain file
SCAN_GZ_BYTES = 256 * 1024 * 1024
TAIL_BYTES = 1024 * 1024       # most a live tail call returns
BLOCK = 64 * 1024
MAX_ROTATED = 60
LEVELS = ("error", "warning", "notice", "info", "deprecated")
STATUS_CLASSES = ("2xx", "3xx", "4xx", "5xx", "other")

_ROTATED = re.compile(r"^(?P<base>[a-z-]+\.log|[a-z0-9_]+\.log)\.(?P<n>\d{1,3})(?P<gz>\.gz)?$")
_SITE_ID = re.compile(r"^[a-z0-9_]{1,253}$")

# 203.0.113.5 - - [07/Nov/2026:01:02:03 +0100] "GET /x HTTP/2.0" 200 512 "ref" "ua" rt=0.012 cache=HIT
_ACCESS = re.compile(
    r'^(?P<ip>\S+) \S+ (?P<user>\S+) \[(?P<time>[^\]]+)\] "(?P<request>(?:[^"\\]|\\.)*)" (?P<status>\d{3}) (?P<bytes>\d+|-)'
    r'(?: "(?P<referer>(?:[^"\\]|\\.)*)" "(?P<agent>(?:[^"\\]|\\.)*)")?(?: rt=(?P<rt>[\d.]+|-))?(?: cache=(?P<cache>\S*))?'
)
# 2026/11/07 01:02:03 [error] 1234#1234: *56 message, client: 1.2.3.4, server: x, request: "GET / HTTP/1.1", host: "x"
_NGINX_ERROR = re.compile(r"^(?P<time>\d{4}/\d\d/\d\d \d\d:\d\d:\d\d) \[(?P<level>[a-z]+)\] \d+#\d+: (?:\*\d+ )?(?P<message>.*)$", re.S)
_NGINX_FIELDS = re.compile(r', (client|server|request|upstream|host|referrer): ("(?:[^"\\]|\\.)*"|[^,]*)')
# [07-Nov-2026 01:02:03 UTC] PHP Warning:  Undefined variable $x in /var/www/.../index.php on line 3
_PHP = re.compile(r"^\[(?P<time>\d\d-[A-Za-z]{3}-\d{4} \d\d:\d\d:\d\d)(?: (?P<tz>[^\]]+))?\] (?P<message>.*)$", re.S)
_PHP_LEVEL = re.compile(r"^PHP (?P<kind>Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated|Strict Standards|Catchable fatal error|[A-Z][a-z]+ error)s?:\s+(?P<text>.*)$", re.S)
_PHP_WHERE = re.compile(r" in (?P<file>/\S+?)(?::(?P<line>\d+)| on line (?P<line2>\d+))\b")
# [07-Nov-2026 01:02:03] WARNING: [pool site-x] child 12 said into stderr: "..."
_FPM = re.compile(r"^\[(?P<time>\d\d-[A-Za-z]{3}-\d{4} \d\d:\d\d:\d\d(?:\.\d+)?)\] (?P<level>[A-Z]+): (?P<message>.*)$", re.S)

_LEVEL_MAP = {
    "emerg": "error", "alert": "error", "crit": "error", "error": "error", "fatal error": "error",
    "parse error": "error", "recoverable fatal error": "error", "catchable fatal error": "error",
    "warn": "warning", "warning": "warning", "notice": "notice", "info": "info", "debug": "info",
    "deprecated": "deprecated", "strict standards": "deprecated",
}


# ------------------------------------------------------------------ opening files safely

def _site(domain):
    from .. import wordpress
    site = wordpress.site_info(domain)
    return {"domain": domain, "id": site.get("id") or "", "root": os.path.join(VHOSTS, domain)}


def _open_dir(path_parts, base):
    """Open base/part/part/... as directories without following any symlink."""
    fd = os.open(base, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for part in path_parts:
            nfd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd)
            fd = nfd
    except FileNotFoundError:
        os.close(fd)
        return None
    except OSError as e:
        os.close(fd)
        raise ActionError("forbidden", f"The log folder cannot be opened safely ({e.strerror})")
    return fd


def _log_dir(site, log):
    """(dir fd, base file name) of a log, or (None, name) when the folder is missing."""
    if log == "fpm":
        if not _SITE_ID.match(site["id"]):
            return None, ""
        return _open_dir([], FPM_DIR) if os.path.isdir(FPM_DIR) else None, f"{site['id']}.log"
    return _open_dir([site["domain"], "logs"], VHOSTS), LOGS[log]


def _is_log_file(st):
    return stat.S_ISREG(st.st_mode) and st.st_nlink == 1


def _open_file(dfd, name):
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=dfd)
    except FileNotFoundError:
        return None, None
    except OSError as e:
        raise ActionError("forbidden", f"{name} cannot be opened safely ({e.strerror})")
    st = os.fstat(fd)
    if not _is_log_file(st):
        os.close(fd)
        raise ActionError("forbidden", f"{name} is not a regular log file")
    return fd, st


def _files(dfd, base):
    """The current file and its rotated copies, newest first."""
    out = []
    if dfd is None:
        return out
    try:
        names = os.listdir(dfd)
    except OSError:
        return out
    for name in names:
        if name == base:
            n = 0
        else:
            m = _ROTATED.match(name)
            if not m or m["base"] != base:
                continue
            n = int(m["n"])
        try:
            st = os.stat(name, dir_fd=dfd, follow_symlinks=False)
        except OSError:
            continue
        if not _is_log_file(st):
            continue
        out.append({"name": name, "n": n, "size": st.st_size, "modified": int(st.st_mtime), "compressed": name.endswith(".gz")})
    out.sort(key=lambda f: f["n"])
    return out[: MAX_ROTATED + 1]


# ------------------------------------------------------------------ parsing

def _unescape(s):
    if s is None:
        return None
    return s.replace('\\"', '"').replace("\\\\", "\\")


def _iso(text, fmt):
    try:
        dt = datetime.strptime(text, fmt)
    except ValueError:
        return None
    if dt.tzinfo is None:
        return dt.strftime("%Y-%m-%dT%H:%M:%S")
    return dt.isoformat()


def parse_access(line):
    m = _ACCESS.match(line)
    if not m:
        return {"raw": line}
    method, path, proto = None, None, None
    request = _unescape(m["request"])
    parts = request.split(" ")
    if len(parts) == 3:
        method, path, proto = parts
    elif request:
        path = request
    status = int(m["status"])
    return {
        "raw": line,
        "time": _iso(m["time"], "%d/%b/%Y:%H:%M:%S %z"),
        "ip": m["ip"],
        "user": None if m["user"] == "-" else m["user"],
        "method": method, "path": path, "protocol": proto,
        "status": status,
        "bytes": 0 if m["bytes"] == "-" else int(m["bytes"]),
        "referer": None if m["referer"] in (None, "-", "") else _unescape(m["referer"]),
        "agent": None if m["agent"] in (None, "-", "") else _unescape(m["agent"]),
        "duration": None if m["rt"] in (None, "-") else float(m["rt"]),
        "cache": m["cache"] if m["cache"] not in (None, "", "-") else None,
    }


def parse_nginx_error(line):
    m = _NGINX_ERROR.match(line)
    if not m:
        return {"raw": line}
    message = m["message"]
    fields = {}
    first = _NGINX_FIELDS.search(message)
    if first:
        for k, val in _NGINX_FIELDS.findall(message[first.start():]):
            fields[k] = _unescape(val.strip('"'))
        message = message[: first.start()]
    return {
        "raw": line,
        "time": _iso(m["time"], "%Y/%m/%d %H:%M:%S"),
        "level": _LEVEL_MAP.get(m["level"], "info"),
        "message": message,
        "ip": fields.get("client"),
        "request": fields.get("request"),
        "upstream": fields.get("upstream"),
    }


def parse_php(text):
    """One PHP error log entry; text can span several lines (stack trace)."""
    m = _PHP.match(text)
    if not m:
        return {"raw": text}
    message = m["message"]
    level = "info"
    lm = _PHP_LEVEL.match(message)
    if lm:
        level = _LEVEL_MAP.get(lm["kind"].lower(), "error" if "error" in lm["kind"].lower() else "info")
        message = lm["text"]
    head = message.split("\n", 1)[0]
    where = _PHP_WHERE.search(head)
    time = _iso(m["time"], "%d-%b-%Y %H:%M:%S")
    if time and (m["tz"] or "").upper() == "UTC":
        time += "+00:00"
    return {
        "raw": text,
        "time": time,
        "level": level,
        "message": message,
        "file": where["file"] if where else None,
        "line": int(where["line"] or where["line2"]) if where else None,
    }


def parse_fpm(text):
    m = _FPM.match(text)
    if not m:
        return {"raw": text}
    return {
        "raw": text,
        "time": _iso(m["time"].split(".")[0], "%d-%b-%Y %H:%M:%S"),
        "level": _LEVEL_MAP.get(m["level"].lower(), "info"),
        "message": m["message"],
    }


PARSERS = {"access": parse_access, "error": parse_nginx_error, "php": parse_php, "fpm": parse_fpm}


def _starts_entry(log, line):
    """PHP and FPM entries start with "[date"; other lines continue the entry above."""
    if log in ("php", "fpm"):
        return line.startswith("[")
    return True


def status_class(status):
    if isinstance(status, int) and 200 <= status < 600:
        return f"{status // 100}xx" if status >= 200 else "other"
    return "other"


# ------------------------------------------------------------------ filtering

class Filter:
    def __init__(self, log, params):
        self.log = log
        q = v.string(params, "q", required=False, default="", max_len=200) or ""
        self.terms = [t.lower() for t in q.split() if not t.startswith("-") or len(t) == 1]
        self.excluded = [t[1:].lower() for t in q.split() if t.startswith("-") and len(t) > 1]
        self.status = set(self._list(params, "status", STATUS_CLASSES))
        self.levels = set(self._list(params, "levels", LEVELS))
        self.ip = v.string(params, "ip", required=False, default="", max_len=64) or ""
        self.method = v.string(params, "method", required=False, default="", max_len=10, pattern=r"[A-Za-z]*") or ""
        self.method = self.method.upper()

    @staticmethod
    def _list(params, key, allowed):
        raw = params.get(key)
        if raw in (None, ""):
            return []
        if not isinstance(raw, list) or len(raw) > len(allowed) or any(x not in allowed for x in raw):
            raise ActionError("invalid_params", f"{key} must be a list of: {', '.join(allowed)}")
        return raw

    def active(self):
        return bool(self.terms or self.excluded or self.status or self.levels or self.ip or self.method)

    def quick(self, text):
        """Cheap test on the raw text before parsing."""
        if not (self.terms or self.excluded or self.ip):
            return True
        low = text.lower()
        if any(t not in low for t in self.terms) or any(t in low for t in self.excluded):
            return False
        return not self.ip or self.ip in text

    def match(self, entry):
        if self.ip and entry.get("ip") != self.ip and (self.log != "php" or self.ip not in entry["raw"]):
            return False
        if self.status and (self.log != "access" or status_class(entry.get("status")) not in self.status):
            return False
        if self.method and entry.get("method") != self.method:
            return False
        if self.levels and (self.log == "access" or entry.get("level") not in self.levels):
            return False
        return True


# ------------------------------------------------------------------ reading

def _clip(line):
    return line if len(line) <= MAX_LINE_CHARS else line[:MAX_LINE_CHARS] + " …"


def _decode(raw):
    return raw.decode("utf-8", errors="replace")


def _lines_backwards(fd, end, budget):
    """Complete lines of a file, newest first, starting at end. Yields (line, bytes read so far)."""
    pos = end
    rest = b""
    read = 0
    while pos > 0 and read < budget:
        size = min(BLOCK, pos)
        pos -= size
        chunk = os.pread(fd, size, pos)
        read += size
        buf = chunk + rest
        parts = buf.split(b"\n")
        rest = parts[0]
        for raw in reversed(parts[1:]):
            yield raw, read
    if rest and (pos == 0):
        yield rest, read


def _collect(log, lines_iter, flt, want):
    """Group continuation lines into entries (newest first), parse and filter."""
    parse = PARSERS[log]
    found = []
    pending = []
    for raw in lines_iter:
        line = _clip(_decode(raw).rstrip("\r"))
        if not line and not pending:
            continue
        if not _starts_entry(log, line):
            pending.append(line)
            if len(pending) > 200:
                pending = pending[-200:]
            continue
        text = "\n".join([line, *reversed(pending)]) if pending else line
        pending = []
        if not flt.quick(text):
            continue
        entry = parse(text)
        if flt.match(entry):
            found.append(entry)
            if len(found) >= want:
                break
    return found


def _read_tail(log, fd, end, flt, want):
    scanned = {"bytes": 0}

    def it():
        for raw, read in _lines_backwards(fd, end, SCAN_BYTES):
            scanned["bytes"] = read
            yield raw

    found = _collect(log, it(), flt, want)
    found.reverse()
    return found, scanned["bytes"] >= SCAN_BYTES and len(found) < want


def _read_gz(log, fd, flt, want):
    """A compressed copy is streamed from the start; keep the last matches."""
    keep = collections.deque(maxlen=want)
    parse = PARSERS[log]
    current = None
    truncated = False
    total = 0
    try:
        with os.fdopen(os.dup(fd), "rb") as fh, gzip.GzipFile(fileobj=fh) as gz:
            for raw in gz:
                total += len(raw)
                if total > SCAN_GZ_BYTES:
                    truncated = True
                    break
                line = _clip(_decode(raw.rstrip(b"\n")).rstrip("\r"))
                if not _starts_entry(log, line) and current is not None:
                    if current.count("\n") < 200:
                        current += "\n" + line
                    continue
                if current is not None and flt.quick(current):
                    e = parse(current)
                    if flt.match(e):
                        keep.append(e)
                current = line
    except (OSError, EOFError, zlib.error):
        truncated = True
    if current is not None and flt.quick(current):
        e = parse(current)
        if flt.match(e):
            keep.append(e)
    return list(keep), truncated


def _read_after(log, fd, start, end, flt, want):
    """New complete lines between start and end (the live tail)."""
    truncated = False
    if end - start > TAIL_BYTES:
        start = end - TAIL_BYTES
        truncated = True
    data = os.pread(fd, end - start, start) if end > start else b""
    cut = data.rfind(b"\n")
    if cut < 0:
        return [], start, truncated
    data = data[: cut + 1]
    next_offset = start + len(data)
    lines = data.split(b"\n")[:-1]
    if truncated and lines:
        lines = lines[1:]  # the first line is probably cut off
    found = _collect(log, reversed(lines), flt, MAX_LINES * 5)
    found.reverse()
    if len(found) > want:
        found = found[-want:]
        truncated = True
    return found, next_offset, truncated


def _log(params):
    return v.choice(params, "log", tuple(LOGS))


# ------------------------------------------------------------------ actions

@action("sitelog.files")
def sitelog_files(params):
    """The logs of a website with their rotated copies (name, size, modified)."""
    domain = v.domain(params, "domain")
    site = _site(domain)
    out = {}
    for log in LOGS:
        dfd, base = _log_dir(site, log)
        try:
            out[log] = _files(dfd, base)
        finally:
            if dfd is not None:
                os.close(dfd)
    return {"domain": domain, "logs": out}


@action("sitelog.read")
def sitelog_read(params):
    """Newest matching entries of one log file, oldest first, plus a cursor.

    params: domain, log (access|error|php|fpm), file (a name from sitelog.files,
    default the current log), lines, q (words; -word excludes), status (list of
    2xx..5xx, access log), levels (list, other logs), ip, method, after
    ({inode, offset} from an earlier call: only what was written since then).
    """
    domain = v.domain(params, "domain")
    log = _log(params)
    want = v.integer(params, "lines", required=False, default=DEFAULT_LINES, minimum=1, maximum=MAX_LINES)
    flt = Filter(log, params)
    after = params.get("after")
    if after is not None and not (isinstance(after, dict) and all(
            isinstance(after.get(k), int) and not isinstance(after.get(k), bool) and after[k] >= 0 for k in ("inode", "offset"))):
        raise ActionError("invalid_params", "after must be {inode, offset}")

    site = _site(domain)
    dfd, base = _log_dir(site, log)
    name = v.string(params, "file", required=False, default=base, max_len=80) or base
    if name != base:
        m = _ROTATED.match(name)
        if not m or m["base"] != base:
            raise ActionError("invalid_params", "Unknown log file")
        if after is not None:
            raise ActionError("invalid_params", "Live tail only works on the current log")

    result = {"domain": domain, "log": log, "file": name, "entries": [], "size": 0, "modified": None,
              "cursor": None, "truncated": False, "rotated": False, "exists": False, "filtered": flt.active()}
    if dfd is None:
        return result
    try:
        fd, st = _open_file(dfd, name)
    finally:
        os.close(dfd)
    if fd is None:
        if after is not None:
            result["cursor"] = {"inode": 0, "offset": 0}
        return result
    try:
        end = st.st_size
        result.update(exists=True, size=end, modified=int(st.st_mtime))
        if name.endswith(".gz"):
            entries, truncated = _read_gz(log, fd, flt, want)
            result.update(entries=entries, truncated=truncated)
            return result
        if after is not None:
            start = after["offset"]
            if after["inode"] != st.st_ino or start > end:
                start = 0  # rotated or emptied since the last call: read the new file from the start
                result["rotated"] = after["inode"] != 0
            entries, offset, truncated = _read_after(log, fd, start, end, flt, want)
            result.update(entries=entries, truncated=truncated, cursor={"inode": st.st_ino, "offset": offset})
            return result
        # Only complete lines: the cursor starts after the last newline.
        tail = os.pread(fd, min(end, BLOCK), max(0, end - BLOCK)) if end else b""
        cut = tail.rfind(b"\n")
        complete = end - len(tail) + cut + 1 if cut >= 0 else max(0, end - len(tail))
        entries, truncated = _read_tail(log, fd, complete, flt, want)
        result.update(entries=entries, truncated=truncated, cursor={"inode": st.st_ino, "offset": complete})
        return result
    finally:
        os.close(fd)
