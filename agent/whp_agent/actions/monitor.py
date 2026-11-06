"""Monitoring checks: websites (HTTP answer + certificate), disks, services and load.

One call to ``monitor.check`` returns everything the panel's monitoring needs.
It only reads: no files are written and nothing is restarted. The panel
decides what is a warning, what is down and whom to alert.
"""

import datetime
import os
import socket
import ssl
import time
from concurrent.futures import ThreadPoolExecutor

from .. import validate as v
from ..registry import ActionError, action
from ..run import run
from . import services as svc
from .server import check_site

# Real file systems; tmpfs, overlay, proc and friends are skipped.
DISK_TYPES = {"ext2", "ext3", "ext4", "xfs", "btrfs", "zfs", "f2fs", "jfs", "reiserfs"}
MOUNTS = "/proc/mounts"
MEMINFO = "/proc/meminfo"


# ---------------------------------------------------------------- certificates

def _openssl_date(value):
    """'Dec  4 10:00:00 2026 GMT' -> unix time."""
    value = " ".join(value.split())
    return int(datetime.datetime.strptime(value, "%b %d %H:%M:%S %Y %Z").replace(tzinfo=datetime.timezone.utc).timestamp())


def parse_x509(text):
    """Output of `openssl x509 -noout -enddate -issuer` -> {expires_at, issuer}."""
    out = {"expires_at": None, "issuer": None}
    for line in text.splitlines():
        key, _, value = line.partition("=")
        key = key.strip().lower()
        if key == "notafter":
            out["expires_at"] = _openssl_date(value.strip())
        elif key == "issuer":
            parts = {k.strip(): val.strip() for k, _, val in (p.partition("=") for p in value.split(",")) if val}
            out["issuer"] = parts.get("O") or parts.get("CN") or value.strip()
    return out


def served_certificate(domain, timeout=10):
    """The certificate this server hands out for ``domain`` (SNI on 127.0.0.1:443)."""
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    with socket.create_connection(("127.0.0.1", 443), timeout=timeout) as sock:
        with ctx.wrap_socket(sock, server_hostname=domain) as tls:
            der = tls.getpeercert(binary_form=True)
    if not der:
        raise ActionError("not_found", "no certificate")
    return ssl.DER_cert_to_pem_cert(der)


def check_certificate(domain, now=None):
    now = int(now or time.time())
    try:
        pem = served_certificate(domain)
        info = parse_x509(run(["openssl", "x509", "-noout", "-enddate", "-issuer"], input_text=pem, timeout=10).stdout)
        match = run(["openssl", "x509", "-noout", "-checkhost", domain], input_text=pem, timeout=10, check=False)
        matches = "does match" in (match.stdout or "")
    except (OSError, ValueError, ActionError) as exc:
        message = exc.message if isinstance(exc, ActionError) else str(exc)
        return {"ok": False, "days_left": None, "expires_at": None, "issuer": None, "matches": None, "error": message[:200]}
    expires = info["expires_at"]
    days = None if expires is None else (expires - now) // 86400
    return {
        "ok": bool(expires and expires > now and matches),
        "days_left": days,
        "expires_at": expires,
        "issuer": info["issuer"],
        "matches": matches,
        "error": None if matches else "certificate is not for this domain",
    }


def check_one(site):
    domain, ssl_on = site
    result = check_site(domain, ssl_on, timeout=15)
    result["ssl"] = check_certificate(domain) if ssl_on else None
    return result


# ---------------------------------------------------------------- server

def disks(mounts_file=MOUNTS):
    seen, out = set(), []
    try:
        with open(mounts_file, encoding="utf-8") as fh:
            lines = fh.read().splitlines()
    except OSError:
        lines = []
    for line in lines:
        parts = line.split()
        if len(parts) < 3 or parts[2] not in DISK_TYPES:
            continue
        device, mount = parts[0], parts[1].replace("\\040", " ")
        if device in seen:
            continue
        seen.add(device)
        try:
            st = os.statvfs(mount)
        except OSError:
            continue
        total = st.f_blocks * st.f_frsize
        free = st.f_bavail * st.f_frsize
        used = (st.f_blocks - st.f_bfree) * st.f_frsize
        usable = used + free
        inodes_used = st.f_files - st.f_ffree
        out.append({
            "mount": mount, "device": device, "fs": parts[2], "total": total, "used": used, "free": free,
            "pct": round(used * 100 / usable, 1) if usable else 0.0,
            "inodes_pct": round(inodes_used * 100 / st.f_files, 1) if st.f_files else None,
        })
    return out


def memory(meminfo=MEMINFO):
    values = {}
    try:
        with open(meminfo, encoding="utf-8") as fh:
            for line in fh:
                key, _, rest = line.partition(":")
                values[key] = int(rest.split()[0]) * 1024 if rest.split() else 0
    except (OSError, ValueError, IndexError):
        return None
    total, avail = values.get("MemTotal", 0), values.get("MemAvailable", 0)
    swap_total, swap_free = values.get("SwapTotal", 0), values.get("SwapFree", 0)
    return {
        "total": total, "available": avail,
        "pct": round((total - avail) * 100 / total, 1) if total else 0.0,
        "swap_total": swap_total,
        "swap_pct": round((swap_total - swap_free) * 100 / swap_total, 1) if swap_total else 0.0,
    }


def mail_queue():
    """Messages waiting in the Postfix queue (None when Postfix is not there)."""
    try:
        proc = run(["postqueue", "-j"], timeout=20, check=False)
    except ActionError:
        return None
    if proc.returncode != 0:
        return None
    return sum(1 for line in proc.stdout.splitlines() if line.strip().startswith("{"))


def service_states():
    out = []
    for unit, label in svc.known_services().items():
        st = svc._status(unit)
        if st["installed"]:
            out.append({**st, "label": label})
    return out


@action("monitor.check")
def check(params):
    sites = v.list_of(params, "sites", lambda s: s if isinstance(s, dict) else {}, required=False, max_items=5000)
    targets = [(v.domain(s, "domain"), bool(s.get("ssl"))) for s in sites]
    with ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(check_one, targets))
    services = service_states()
    try:
        load = list(os.getloadavg())
    except OSError:
        load = None
    return {
        "sites": results,
        "services": services,
        "disks": disks(),
        "memory": memory(),
        "load": load,
        "cpus": os.cpu_count() or 1,
        "mail_queue": mail_queue(),
        "checked_at": int(time.time()),
    }
