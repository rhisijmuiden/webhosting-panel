"""Resource usage samples for the statistics page.

The panel calls stats.sample once a minute. Counters (CPU time, network and
disk bytes) are turned into rates against the previous call, kept in memory.
Per-site usage comes from cgroup v2: every site runs as whp-site-<id>.service
inside whp.slice/whp-sites.slice (<id> = domain with . and - as _).
"""

import os
import shutil
import threading
import time

from ..registry import action

CGROUP_ROOT = os.environ.get("WHP_CGROUP_ROOT", "/sys/fs/cgroup/whp.slice/whp-sites.slice")
UNIT_PREFIX = "whp-site-"
UNIT_SUFFIX = ".service"

_lock = threading.Lock()
_previous = {}


def _read(path):
    try:
        with open(path) as fh:
            return fh.read()
    except OSError:
        return ""


def _cpu_times():
    parts = [int(x) for x in _read("/proc/stat").splitlines()[0].split()[1:]]
    idle = parts[3] + parts[4]
    return idle, sum(parts)


def _net_bytes():
    rx = tx = 0
    for line in _read("/proc/net/dev").splitlines()[2:]:
        name, data = line.split(":", 1)
        if name.strip() == "lo":
            continue
        fields = data.split()
        rx += int(fields[0])
        tx += int(fields[8])
    return rx, tx


def _meminfo():
    info = {}
    for line in _read("/proc/meminfo").splitlines():
        key, rest = line.split(":", 1)
        info[key] = int(rest.split()[0]) * 1024
    total = info.get("MemTotal", 0)
    return total, total - info.get("MemAvailable", 0), info.get("SwapTotal", 0) - info.get("SwapFree", 0)


def _slice_counters(path):
    cpu_usec = 0
    for line in _read(os.path.join(path, "cpu.stat")).splitlines():
        if line.startswith("usage_usec"):
            cpu_usec = int(line.split()[1])
    read_b = write_b = 0
    for line in _read(os.path.join(path, "io.stat")).splitlines():
        for field in line.split()[1:]:
            k, _, val = field.partition("=")
            if k == "rbytes":
                read_b += int(val)
            elif k == "wbytes":
                write_b += int(val)
    mem = _read(os.path.join(path, "memory.current")).strip()
    return {"cpu_usec": cpu_usec, "read": read_b, "write": write_b, "memory": int(mem) if mem.isdigit() else 0}


def _slices():
    out = {}
    try:
        entries = os.listdir(CGROUP_ROOT)
    except OSError:
        return out
    for entry in entries:
        if entry.startswith(UNIT_PREFIX) and entry.endswith(UNIT_SUFFIX):
            out[entry[len(UNIT_PREFIX):-len(UNIT_SUFFIX)]] = _slice_counters(os.path.join(CGROUP_ROOT, entry))
    return out


def _rate(new, old, seconds):
    if old is None or seconds <= 0 or new < old:
        return 0.0
    return (new - old) / seconds


@action("stats.sample")
def sample(params):
    now = time.monotonic()
    idle, total = _cpu_times()
    rx, tx = _net_bytes()
    mem_total, mem_used, swap_used = _meminfo()
    disk = shutil.disk_usage("/")
    slices = _slices()
    cpus = os.cpu_count() or 1

    with _lock:
        prev = dict(_previous)
        _previous.update({"at": now, "idle": idle, "total": total, "rx": rx, "tx": tx, "slices": slices})

    seconds = now - prev["at"] if prev else 0
    if prev and total > prev["total"]:
        cpu = 100 * (1 - (idle - prev["idle"]) / (total - prev["total"]))
    else:
        cpu = 0.0

    sites = []
    for site_id, counters in slices.items():
        old = (prev.get("slices") or {}).get(site_id) if prev else None
        sites.append({
            "site_id": site_id,
            # Percentage of the whole machine, like the server CPU figure.
            "cpu_percent": round(_rate(counters["cpu_usec"], old and old["cpu_usec"], seconds) / 1e6 / cpus * 100, 2),
            "memory_bytes": counters["memory"],
            "io_read_bps": round(_rate(counters["read"], old and old["read"], seconds)),
            "io_write_bps": round(_rate(counters["write"], old and old["write"], seconds)),
        })

    load = os.getloadavg()
    return {
        "interval_seconds": round(seconds, 1),
        "first_sample": not prev,
        "cpu_percent": round(cpu, 2),
        "load1": round(load[0], 2),
        "memory_used": mem_used,
        "memory_total": mem_total,
        "swap_used": swap_used,
        "disk_used": disk.used,
        "disk_total": disk.total,
        "net_rx_bps": round(_rate(rx, prev.get("rx") if prev else None, seconds)),
        "net_tx_bps": round(_rate(tx, prev.get("tx") if prev else None, seconds)),
        "sites": sites,
    }
