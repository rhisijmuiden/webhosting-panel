"""Security scanning for the panel's vulnerability alerts and security overview.

    vuln.sync      download the Wordfence feed into the local database (job)
    vuln.status    what the local database holds
    vuln.match     which of the given core/plugin/theme versions are vulnerable
    malware.report the last malware scan of a site (whp-malware-scan)
    malware.scan   scan one site now (job)
    posture.get    how the server itself is doing: OS updates, reboot, SSH, ClamAV
"""

import json
import os
import re
import time

from .. import paths, vulndb
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

MALWARE_DIR = os.environ.get("WHP_MALWARE_DIR", os.path.join(paths.STATE_DIR, "malware"))
MALWARE_SCAN = "/usr/local/sbin/whp-malware-scan"
VHOSTS = os.environ.get("WHP_VHOSTS", "/var/www/vhosts")
CLAMAV_DB = os.environ.get("WHP_CLAMAV_DB", "/var/lib/clamav")
REBOOT_FILE = os.environ.get("WHP_REBOOT_FILE", "/run/reboot-required")
SOURCES = ("wordfence", "wpvulnerability")


def _item(x):
    if not isinstance(x, dict):
        raise ActionError("invalid_params", "items must be objects")
    kind = x.get("type")
    slug = str(x.get("slug") or "").lower()
    version = str(x.get("version") or "")
    if kind not in ("core", "plugin", "theme"):
        raise ActionError("invalid_params", "type must be core, plugin or theme")
    if not vulndb.SLUG_RE.fullmatch(slug):
        raise ActionError("invalid_params", f"Invalid slug: {slug[:40]}")
    if not vulndb.VERSION_RE.fullmatch(version):
        raise ActionError("invalid_params", f"Invalid version for {slug}")
    return {"type": kind, "slug": slug, "version": version}


@action("vuln.match")
def vuln_match(params):
    source = v.choice(params, "source", SOURCES)
    items = v.list_of(params, "items", _item, max_items=500)
    return vulndb.match(items, source)


@action("vuln.status")
def vuln_status(params):
    return vulndb.wordfence_status()


@action("vuln.sync")
def vuln_sync(params):
    key = v.string(params, "api_key", max_len=256)

    def work(_params, log):
        return vulndb.sync_wordfence(key, log)

    return start_job("vuln.sync", work, {})


# ---------------------------------------------------------------- malware

def _report(domain):
    try:
        with open(os.path.join(MALWARE_DIR, f"{domain}.json")) as fh:
            data = json.load(fh)
    except FileNotFoundError:
        return {"domain": domain, "scanned_at": None, "infected": 0, "findings": []}
    except ValueError:
        raise ActionError("command_failed", "The malware report is not valid JSON")
    root = os.path.join(VHOSTS, domain) + os.sep
    findings = []
    for f in data.get("findings") or []:
        path = str(f.get("file") or "")
        findings.append({
            "engine": str(f.get("engine") or ""),
            "file": path[len(root):] if path.startswith(root) else os.path.basename(path),
            "signature": str(f.get("signature") or "")[:200],
        })
    return {"domain": domain, "scanned_at": data.get("scanned_at"), "infected": len(findings), "findings": findings[:200]}


@action("malware.report")
def malware_report(params):
    domain = v.domain(params, "domain")
    return {"available": os.access(MALWARE_SCAN, os.X_OK), **_report(domain)}


@action("malware.scan")
def malware_scan(params):
    domain = v.domain(params, "domain")
    if not os.path.isdir(os.path.join(VHOSTS, domain)):
        raise ActionError("not_found", f"Unknown website: {domain}")
    if not os.access(MALWARE_SCAN, os.X_OK):
        raise ActionError("not_found", "The malware scanner is not installed (ClamAV is off on this server)")

    def work(_params, log):
        log(f"Scanning {domain}")
        proc = run([MALWARE_SCAN, domain], timeout=3600, check=False)
        if proc.returncode not in (0, 1):
            raise ActionError("command_failed", "The malware scan failed", {"stderr": (proc.stderr or "")[-1000:]})
        return _report(domain)

    return start_job("malware.scan", work, {})


# ---------------------------------------------------------------- server posture

def _security_updates():
    """Packages with a pending upgrade, from the apt lists the daily apt timer refreshes."""
    try:
        proc = run(["apt-get", "-s", "-o", "Debug::NoLocking=1", "dist-upgrade"], timeout=120, check=False)
    except ActionError:
        return None, None
    total, security = 0, 0
    for line in (proc.stdout or "").splitlines():
        if line.startswith("Inst "):
            total += 1
            if "-security" in line or "Debian-Security" in line:
                security += 1
    return total, security


def _sshd():
    try:
        proc = run(["sshd", "-T"], timeout=20, check=False)
    except ActionError:
        return {}
    conf = {}
    for line in (proc.stdout or "").splitlines():
        k, _, val = line.partition(" ")
        if k in ("permitrootlogin", "passwordauthentication", "port", "kbdinteractiveauthentication"):
            conf[k] = val.strip()
    return conf


def _active(unit):
    try:
        return run(["systemctl", "is-active", unit], timeout=10, check=False).stdout.strip() == "active"
    except ActionError:
        return False


def _clamav_age():
    newest = None
    for name in ("daily.cld", "daily.cvd"):
        try:
            m = os.path.getmtime(os.path.join(CLAMAV_DB, name))
            newest = max(newest or 0, m)
        except OSError:
            pass
    return int(time.time() - newest) if newest else None


def _last_malware_scan():
    newest = None
    try:
        for e in os.scandir(MALWARE_DIR):
            if e.name.endswith(".json"):
                newest = max(newest or 0, e.stat().st_mtime)
    except OSError:
        return None
    return int(newest) if newest else None


@action("posture.get")
def posture_get(params):
    total, security = _security_updates()
    ssh = _sshd()
    try:
        with open("/etc/apt/apt.conf.d/20auto-upgrades") as fh:
            unattended = bool(re.search(r'Unattended-Upgrade\s+"1"', fh.read()))
    except OSError:
        unattended = False
    return {
        "updates": {"total": total, "security": security, "unattended": unattended},
        "reboot_required": os.path.exists(REBOOT_FILE),
        "ssh": {
            "root_login": ssh.get("permitrootlogin"),
            "password_auth": ssh.get("passwordauthentication"),
            "port": ssh.get("port"),
        },
        "services": {u: _active(u) for u in ("crowdsec", "crowdsec-firewall-bouncer", "nftables", "clamav-daemon", "clamav-freshclam")},
        "clamav_signature_age": _clamav_age(),
        "malware_scanner": os.access(MALWARE_SCAN, os.X_OK),
        "last_malware_scan": _last_malware_scan(),
        "vulndb": vulndb.wordfence_status(),
        "checked_at": int(time.time()),
    }
