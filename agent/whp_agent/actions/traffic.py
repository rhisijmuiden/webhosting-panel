"""Data traffic per website, for the traffic limit of hosting packages.

Nginx writes one short line per request to /var/log/nginx/whp-traffic.log:
"<server_name> <bytes_sent> <request_length>" (static files included). This
module reads the new part of that log on every call, adds it up per site and
per calendar month (UTC) and keeps the totals in
/var/lib/webhosting-panel/traffic.json. Log rotation (logrotate renames the
file to .1) is followed by inode, so no request is counted twice or lost.
"""

import json
import os
import re
import threading
import time

from .. import paths
from .. import validate as v
from ..registry import action

LOG_FILE = os.environ.get("WHP_TRAFFIC_LOG", "/var/log/nginx/whp-traffic.log")
KEEP_MONTHS = 13
CHUNK = 4 * 1024 * 1024
_NAME = re.compile(r"^[a-z0-9.-]{1,253}$")
_lock = threading.Lock()


def _state_file():
    return os.path.join(paths.STATE_DIR, "traffic.json")


def _load():
    try:
        with open(_state_file()) as fh:
            data = json.load(fh)
            if isinstance(data, dict):
                data.setdefault("months", {})
                return data
    except (FileNotFoundError, ValueError):
        pass
    return {"inode": None, "offset": 0, "months": {}}


def _save(data):
    os.makedirs(paths.STATE_DIR, exist_ok=True)
    tmp = _state_file() + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(data, fh, sort_keys=True)
    os.chmod(tmp, 0o600)
    os.replace(tmp, _state_file())


def _add_lines(text, totals):
    for line in text.splitlines():
        parts = line.split()
        if len(parts) != 3 or not parts[1].isdigit() or not parts[2].isdigit():
            continue
        name = parts[0].lower()
        if not _NAME.match(name):
            continue
        totals[name] = totals.get(name, 0) + int(parts[1]) + int(parts[2])


def _read_from(path, offset, totals):
    """Count complete lines of path from offset. Returns the new offset."""
    with open(path, "rb") as fh:
        fh.seek(offset)
        while True:
            data = fh.read(CHUNK)
            if not data:
                break
            end = data.rfind(b"\n")
            if end < 0:
                break  # half a line: Nginx is still writing it
            _add_lines(data[:end + 1].decode("utf-8", "replace"), totals)
            offset += end + 1
            if end + 1 < len(data):
                fh.seek(offset)
    return offset


def collect(log_file=None, now=None):
    log_file = log_file or LOG_FILE
    month = time.strftime("%Y-%m", time.gmtime(now))
    with _lock:
        state = _load()
        totals = {}
        try:
            st = os.stat(log_file)
        except FileNotFoundError:
            st = None
        if st is not None:
            offset = int(state.get("offset") or 0)
            if state.get("inode") != st.st_ino:
                # Rotated: finish the old file first (now .1), then start the new one at 0.
                old = log_file + ".1"
                try:
                    if state.get("inode") is not None and os.stat(old).st_ino == state["inode"]:
                        _read_from(old, offset, totals)
                except FileNotFoundError:
                    pass
                offset = 0
            elif st.st_size < offset:
                offset = 0  # truncated
            state["offset"] = _read_from(log_file, offset, totals)
            state["inode"] = st.st_ino
        bucket = state["months"].setdefault(month, {})
        for name, value in totals.items():
            bucket[name] = bucket.get(name, 0) + value
        for old_month in sorted(state["months"])[:-KEEP_MONTHS]:
            del state["months"][old_month]
        _save(state)
        return state


@action("traffic.report")
def traffic_report(params):
    """Read new log lines and return the traffic per site name (bytes in + out) for a month."""
    month = v.string(params, "month", required=False, max_len=7, pattern=r"20[0-9]{2}-(0[1-9]|1[0-2])")
    state = collect()
    month = month or time.strftime("%Y-%m", time.gmtime())
    return {"month": month, "sites": state["months"].get(month, {})}
