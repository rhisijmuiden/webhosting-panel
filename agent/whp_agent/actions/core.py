import os
import platform
import shutil
import socket
import time

from .. import __version__, jobs
from .. import validate as v
from ..registry import ACTIONS, action


@action("ping")
def ping(params):
    return {"pong": True, "agent_version": __version__}


@action("actions.list")
def actions_list(params):
    return {"actions": sorted(ACTIONS)}


def _os_release():
    data = {}
    try:
        with open("/etc/os-release") as fh:
            for line in fh:
                if "=" in line:
                    k, val = line.rstrip().split("=", 1)
                    data[k] = val.strip('"')
    except FileNotFoundError:
        pass
    return data


def _container():
    if os.path.exists("/run/systemd/container"):
        with open("/run/systemd/container") as fh:
            return fh.read().strip() or None
    try:
        with open("/proc/1/environ", "rb") as fh:
            if b"container=lxc" in fh.read():
                return "lxc"
    except OSError:
        pass
    return None


def _meminfo():
    info = {}
    try:
        with open("/proc/meminfo") as fh:
            for line in fh:
                key, rest = line.split(":", 1)
                info[key] = int(rest.split()[0]) * 1024
    except (OSError, ValueError):
        return {"total": 0, "used": 0}
    total = info.get("MemTotal", 0)
    return {"total": total, "used": total - info.get("MemAvailable", 0)}


def _cpu_percent():
    def read():
        with open("/proc/stat") as fh:
            parts = [int(x) for x in fh.readline().split()[1:]]
        idle = parts[3] + parts[4]
        return idle, sum(parts)

    try:
        i1, t1 = read()
        time.sleep(0.2)
        i2, t2 = read()
        return round(100 * (1 - (i2 - i1) / max(t2 - t1, 1)), 1)
    except OSError:
        return 0.0


@action("system.info")
def system_info(params):
    osr = _os_release()
    disk = shutil.disk_usage("/")
    try:
        load = os.getloadavg()
    except OSError:
        load = (0.0, 0.0, 0.0)
    with open("/proc/uptime") as fh:
        uptime = float(fh.read().split()[0])
    return {
        "hostname": socket.getfqdn(),
        "os": osr.get("PRETTY_NAME", platform.platform()),
        "os_version": osr.get("VERSION_ID"),
        "codename": osr.get("VERSION_CODENAME"),
        "kernel": platform.release(),
        "container": _container(),
        "uptime_seconds": int(uptime),
        "load": [round(x, 2) for x in load],
        "cpu_percent": _cpu_percent(),
        "cpu_count": os.cpu_count(),
        "memory": _meminfo(),
        "disk": {"total": disk.total, "used": disk.used},
    }


@action("job.status")
def job_status(params):
    return jobs.read(v.string(params, "job", max_len=64))
