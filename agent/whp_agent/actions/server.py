"""Server management: OS updates (apt), reboot, health checks, maintenance
tasks (database optimize, cleanup) and PHP versions (install, remove).

There is deliberately no shutdown or power off action: a server that is off
can only be started again from the hosting provider's console.

Package installs run in their own transient systemd unit (``whp-apt-<id>``)
with output in ``/var/log/webhosting-panel/apt/<id>.log``. They keep running
when an upgrade restarts the agent, and ``server.apt_status`` reads the result
back afterwards.
"""

import glob
import gzip
import json
import os
import re
import subprocess
import tarfile
import time
import urllib.error
import urllib.request
import uuid
from concurrent.futures import ThreadPoolExecutor

from .. import paths
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import SAFE_ENV, run
from . import services as svc

SERVER_DIR = os.path.join(paths.STATE_DIR, "server")
APT_LOG_DIR = os.path.join(paths.LOG_DIR, "apt")
REBOOT_MARKER = os.path.join(SERVER_DIR, "reboot.json")
CVSS_CACHE = os.path.join(SERVER_DIR, "cvss.json")
REBOOT_FILE = os.environ.get("WHP_REBOOT_FILE", "/run/reboot-required")
REBOOT_PKGS = REBOOT_FILE + ".pkgs"
WINDOW_CONF = os.environ.get("WHP_APT_WINDOW_CONF", "/etc/apt/apt.conf.d/21whp-panel-window")
APT_ARCHIVES = "/var/cache/apt/archives"
VHOSTS = os.environ.get("WHP_VHOSTS", "/var/www/vhosts")
SITES_DB = os.path.join(paths.STATE_DIR, "sites")
INSTALLER_ENV = os.path.join(paths.CONFIG_DIR, "installer.env")
INSTALL_SH = os.path.join(paths.ROOT_DIR, "installer", "install.sh")
COREDUMP_DIR = "/var/lib/systemd/coredump"
NVD_URL = os.environ.get("WHP_NVD_API", "https://services.nvd.nist.gov/rest/json/cves/2.0")
USER_AGENT = "webhosting-panel-agent (+https://github.com/rhisijmuiden/webhosting-panel)"
REBOOT_UNIT = "whp-reboot"
RUN_ID_RE = re.compile(r"^[0-9a-f]{32}$")
PHP_RE = re.compile(r"^[0-9]\.[0-9]$")
SYSTEM_DATABASES = ("information_schema", "performance_schema", "mysql", "sys")
MARIADB = ["mariadb", "--protocol=socket", "-uroot", "--batch", "--skip-column-names"]

APT_ENV = {
    "DEBIAN_FRONTEND": "noninteractive",
    "APT_LISTCHANGES_FRONTEND": "none",
    "NEEDRESTART_MODE": "l",
    "UCF_FORCE_CONFFOLD": "1",
}
APT_OPTS = ["-o", "DPkg::Lock::Timeout=600", "-o", "Dpkg::Options::=--force-confdef",
            "-o", "Dpkg::Options::=--force-confold", "-y", "-q"]
UPGRADE_MODES = {
    # What unattended-upgrades is allowed to install (security, Debian point
    # releases, Sury PHP, Rspamd, CrowdSec: see installer/modules/10-base.sh).
    "security": [["unattended-upgrade", "-v"]],
    # Everything that has an update, new dependencies (a new kernel) included,
    # but never removes a package.
    "all": [["apt-get", *APT_OPTS, "update"], ["apt-get", *APT_OPTS, "--with-new-pkgs", "upgrade"]],
}


def _ensure_dirs():
    os.makedirs(SERVER_DIR, mode=0o750, exist_ok=True)
    os.makedirs(APT_LOG_DIR, mode=0o750, exist_ok=True)


def _read_json(path, default):
    try:
        with open(path) as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return default


def _write_json(path, data):
    _ensure_dirs()
    tmp = path + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(data, fh)
    os.replace(tmp, path)


# ---------------------------------------------------------------- status

def boot_time():
    try:
        with open("/proc/stat") as fh:
            for line in fh:
                if line.startswith("btime "):
                    return int(line.split()[1])
    except OSError:
        pass
    return None


def _version_key(text):
    return [int(p) if p.isdigit() else p for p in re.split(r"[.\-+~]", text)]


def kernels():
    """Running kernel and the newest kernel installed in /boot."""
    running = os.uname().release
    installed = [os.path.basename(p)[len("vmlinuz-"):] for p in glob.glob("/boot/vmlinuz-*")]
    newest = max(installed, key=_version_key) if installed else running
    return running, newest, sorted(installed, key=_version_key)


def _unit_of(pid):
    try:
        with open(f"/proc/{pid}/cgroup") as fh:
            parts = fh.read().strip().split("/")
    except OSError:
        return None
    for part in reversed(parts):
        if part.endswith(".service"):
            return part
    return None


def stale_services(limit=2000):
    """Services that still run code from replaced (deleted) libraries."""
    units = set()
    for i, pid in enumerate(p for p in os.listdir("/proc") if p.isdigit()):
        if i >= limit:
            break
        try:
            with open(f"/proc/{pid}/maps") as fh:
                stale = any(".so" in line and line.rstrip().endswith("(deleted)") for line in fh)
        except OSError:
            continue
        if stale:
            unit = _unit_of(pid)
            if unit and not unit.startswith(("whp-site-", "user@", "whp-apt-")):
                units.add(unit)
    return sorted(units)


def reboot_reasons():
    reasons = []
    running, newest, _ = kernels()
    if _version_key(newest) > _version_key(running):
        reasons.append({"kind": "kernel", "detail": f"{running} -> {newest}"})
    if os.path.exists(REBOOT_FILE):
        try:
            with open(REBOOT_PKGS) as fh:
                pkgs = sorted({p.strip() for p in fh if p.strip()})
        except OSError:
            pkgs = []
        reasons.append({"kind": "packages", "detail": ", ".join(pkgs[:20])})
    stale = stale_services()
    if stale:
        reasons.append({"kind": "libraries", "detail": ", ".join(stale[:20])})
    return reasons


def _scheduled_reboot():
    marker = _read_json(REBOOT_MARKER, None)
    if not marker:
        return None
    proc = run(["systemctl", "is-active", f"{REBOOT_UNIT}.timer"], timeout=10, check=False)
    marker["pending"] = proc.stdout.strip() == "active"
    bt = boot_time()
    marker["completed"] = bool(bt and bt > int(marker.get("requested_at", 0)))
    return marker


def _apt_lists_age():
    try:
        newest = max(os.path.getmtime(p) for p in glob.glob("/var/lib/apt/lists/*_Release") or ["/var/lib/apt/lists"])
    except (OSError, ValueError):
        return None
    return int(time.time() - newest)


def _os_release():
    data = {}
    try:
        with open("/etc/os-release") as fh:
            for line in fh:
                k, _, val = line.strip().partition("=")
                data[k] = val.strip('"')
    except OSError:
        pass
    return data.get("PRETTY_NAME", "Linux")


@action("server.status")
def status(params):
    running, newest, installed = kernels()
    bt = boot_time()
    return {
        "os": _os_release(),
        "kernel": running,
        "kernel_newest": newest,
        "kernels_installed": installed,
        "boot_time": bt,
        "uptime_seconds": int(time.time() - bt) if bt else None,
        "reboot_reasons": reboot_reasons(),
        "reboot": _scheduled_reboot(),
        "apt_lists_age": _apt_lists_age(),
        "apt_running": _running_apt_units(),
        "auto_updates": "window" if os.path.exists(WINDOW_CONF) else "daily",
        "can_shutdown": False,
    }


# ---------------------------------------------------------------- updates

INST_RE = re.compile(r"^Inst (\S+) (?:\[([^\]]+)\] )?\((\S+) (.+?) \[([^\]]+)\]\)")


def parse_simulation(text):
    """Pending upgrades from `apt-get -s dist-upgrade` output."""
    out = []
    for line in text.splitlines():
        m = INST_RE.match(line)
        if not m:
            continue
        name, current, candidate, origins, arch = m.groups()
        out.append({
            "name": name,
            "current": current,
            "candidate": candidate,
            "arch": arch,
            "origin": origins,
            "security": "-security" in origins or "Debian-Security" in origins,
            "new": current is None,
            "kernel": name.startswith("linux-image-"),
        })
    return out


def pending_updates(refresh=False):
    if refresh:
        run(["apt-get", "-o", "DPkg::Lock::Timeout=120", "-q", "update"], timeout=300, env=APT_ENV)
    proc = run(["apt-get", "-s", "-o", "Debug::NoLocking=1", "dist-upgrade"], timeout=180, check=False, env=APT_ENV)
    return parse_simulation(proc.stdout or "")


@action("server.updates")
def updates(params):
    refresh = v.boolean(params, "refresh", required=False, default=False)
    pkgs = pending_updates(refresh)
    return {
        "packages": pkgs,
        "total": len(pkgs),
        "security": sum(1 for p in pkgs if p["security"]),
        "kernel": any(p["kernel"] for p in pkgs),
        "apt_lists_age": _apt_lists_age(),
    }


# ---------------------------------------------------------------- CVEs

CHANGELOG_HEAD = re.compile(r"^(\S+) \(([^)]+)\) [^;]*;")
CVE_RE = re.compile(r"\bCVE-\d{4}-\d{4,7}\b")


def changelog_cves(changelog, installed):
    """CVE ids in the changelog entries that are newer than the installed version."""
    found, started = [], False
    for line in changelog.splitlines()[:3000]:
        m = CHANGELOG_HEAD.match(line)
        if m:
            if installed and m.group(2) == installed:
                break
            started = True
            continue
        if started:
            for cve in CVE_RE.findall(line):
                if cve not in found:
                    found.append(cve)
    return found


def _deb_path(pkg):
    version = pkg["candidate"].replace(":", "%3a")
    path = os.path.join(APT_ARCHIVES, f"{pkg['name']}_{version}_{pkg['arch']}.deb")
    return path if os.path.exists(path) else None


def deb_changelog(path, name):
    """The Debian changelog inside a .deb, read without unpacking it to disk."""
    wanted = (f"./usr/share/doc/{name}/changelog.Debian.gz", f"usr/share/doc/{name}/changelog.Debian.gz")
    proc = subprocess.Popen(["dpkg-deb", "--fsys-tarfile", path], stdout=subprocess.PIPE,
                            stderr=subprocess.DEVNULL, env=SAFE_ENV)
    try:
        with tarfile.open(fileobj=proc.stdout, mode="r|*") as tar:
            for member in tar:
                if member.isfile() and member.name in wanted and member.size < 20 * 1024 * 1024:
                    fh = tar.extractfile(member)
                    return gzip.decompress(fh.read()).decode("utf-8", "replace") if fh else None
    except (tarfile.TarError, OSError, EOFError):
        return None
    finally:
        proc.stdout.close()
        proc.kill()
        proc.wait()
    return None


def _nvd_score(cve, api_key=None, timeout=20):
    req = urllib.request.Request(f"{NVD_URL}?cveId={cve}", headers={"User-Agent": USER_AGENT, **({"apiKey": api_key} if api_key else {})})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        data = json.load(resp)
    for item in data.get("vulnerabilities") or []:
        metrics = (item.get("cve") or {}).get("metrics") or {}
        for key in ("cvssMetricV40", "cvssMetricV31", "cvssMetricV30", "cvssMetricV2"):
            for m in metrics.get(key) or []:
                score = (m.get("cvssData") or {}).get("baseScore")
                if isinstance(score, (int, float)):
                    return float(score)
    return None


def cvss_scores(cves, api_key=None, max_lookups=40, log=lambda s: None, sleep=time.sleep):
    """CVSS base scores from the NVD, cached on disk. Unknown scores are retried after a day."""
    cache = _read_json(CVSS_CACHE, {})
    now = time.time()
    lookups = 0
    pause = 0.7 if api_key else 6.5  # NVD: 50 or 5 requests per 30 seconds
    for cve in cves:
        hit = cache.get(cve)
        if hit and (hit.get("score") is not None or now - hit.get("at", 0) < 86400):
            continue
        if lookups >= max_lookups:
            break
        if lookups:
            sleep(pause)
        lookups += 1
        try:
            cache[cve] = {"score": _nvd_score(cve, api_key), "at": now}
        except (urllib.error.URLError, OSError, ValueError) as exc:
            log(f"NVD lookup for {cve} failed: {exc}")
            cache[cve] = {"score": None, "at": now}
    _write_json(CVSS_CACHE, cache)
    return {cve: (cache.get(cve) or {}).get("score") for cve in cves}


def security_scan(params, log):
    threshold = float(params.get("threshold", 9.0))
    log("Refreshing the package lists")
    pkgs = [p for p in pending_updates(refresh=True) if p["security"]]
    if not pkgs:
        log("No security updates waiting")
        return {"packages": [], "max_score": None, "critical": False, "threshold": threshold, "checked_at": int(time.time())}
    log(f"{len(pkgs)} security updates, downloading them to read their changelogs")
    run(["apt-get", *APT_OPTS, "--download-only", "install", *[p["name"] for p in pkgs]],
        timeout=1800, check=False, env=APT_ENV)
    all_cves = []
    for p in pkgs:
        deb = _deb_path(p)
        text = deb_changelog(deb, p["name"]) if deb else None
        p["cves"] = changelog_cves(text, p["current"]) if text else []
        all_cves += [c for c in p["cves"] if c not in all_cves]
    log(f"{len(all_cves)} CVEs fixed by these updates, looking up their CVSS scores")
    scores = cvss_scores(all_cves, params.get("nvd_api_key") or None, log=log)
    best = None
    for p in pkgs:
        p["cves"] = [{"id": c, "score": scores.get(c)} for c in p["cves"]]
        known = [c["score"] for c in p["cves"] if c["score"] is not None]
        p["max_score"] = max(known) if known else None
        if p["max_score"] is not None:
            best = max(best or 0, p["max_score"])
    critical = best is not None and best >= threshold
    log(f"Highest CVSS score: {best if best is not None else 'unknown'}" + (" (critical)" if critical else ""))
    return {"packages": pkgs, "max_score": best, "critical": critical, "threshold": threshold, "checked_at": int(time.time())}


@action("server.security_scan")
def security_scan_action(params):
    threshold = params.get("threshold", 9.0)
    if not isinstance(threshold, (int, float)) or not 0 <= threshold <= 10:
        raise ActionError("invalid_params", "threshold must be a number from 0 to 10")
    key = v.string(params, "nvd_api_key", required=False, default="", max_len=64, pattern=r"^[A-Za-z0-9-]*$")
    return start_job("server.security_scan", security_scan, {"threshold": float(threshold), "nvd_api_key": key})


# ---------------------------------------------------------------- installs in a transient unit

def _running_apt_units():
    proc = run(["systemctl", "list-units", "--type=service", "--state=active,activating", "--no-legend", "--plain", "whp-apt-*"],
               timeout=10, check=False)
    return [line.split()[0] for line in (proc.stdout or "").splitlines() if line.strip()]


def _apt_paths(run_id):
    return os.path.join(APT_LOG_DIR, f"{run_id}.log"), os.path.join(APT_LOG_DIR, f"{run_id}.exit")


def start_unit(run_id, commands, env=None):
    """Start commands (a list of argv lists, run one after the other) in whp-apt-<id>."""
    _ensure_dirs()
    logfile, exitfile = _apt_paths(run_id)
    script = 'rc=0; for c in "$@"; do eval "set -- $c"; echo "==> $*"; "$@" || { rc=$?; break; }; done; echo "$rc" >"$WHP_EXIT"'
    # Every command is passed as one shell-quoted word list, never interpreted further.
    words = [" ".join(_quote(a) for a in argv) for argv in commands]
    argv = ["systemd-run", "--unit", f"whp-apt-{run_id}", "--collect", "--quiet",
            "-p", f"StandardOutput=append:{logfile}", "-p", f"StandardError=append:{logfile}",
            f"--setenv=WHP_EXIT={exitfile}", f"--setenv=PATH={SAFE_ENV['PATH']}", "--setenv=LANG=C.UTF-8"]
    for k, val in {**APT_ENV, **(env or {})}.items():
        argv.append(f"--setenv={k}={val}")
    run(argv + ["/bin/bash", "-c", script, "whp-apt", *words], timeout=30)


def _quote(arg):
    return "'" + arg.replace("'", "'\\''") + "'"


def unit_status(run_id):
    logfile, exitfile = _apt_paths(run_id)
    try:
        with open(exitfile) as fh:
            code = int(fh.read().strip() or 1)
    except (OSError, ValueError):
        code = None
    active = run(["systemctl", "is-active", f"whp-apt-{run_id}.service"], timeout=10, check=False).stdout.strip()
    try:
        with open(logfile, errors="replace") as fh:
            log = fh.read()[-200000:]
    except OSError:
        log = ""
    if code is not None:
        state = "succeeded" if code == 0 else "failed"
    elif active in ("active", "activating"):
        state = "running"
    else:
        state = "failed"  # the unit is gone without writing its exit code
    return {"id": run_id, "status": state, "exit_code": code, "log": log.splitlines()[-1500:]}


def follow_unit(run_id, log, timeout=7200, poll=2.0, sleep=time.sleep):
    """Wait for whp-apt-<id>, copying its log lines into the job log."""
    logfile, _ = _apt_paths(run_id)
    pos, waited = 0, 0.0
    while True:
        try:
            with open(logfile, errors="replace") as fh:
                fh.seek(pos)
                chunk = fh.read()
                pos = fh.tell()
            for line in chunk.splitlines():
                log(line)
        except OSError:
            pass
        st = unit_status(run_id)
        if st["status"] != "running":
            return st
        if waited >= timeout:
            raise ActionError("timeout", "The package run did not finish in time; it keeps running in the background")
        sleep(poll)
        waited += poll


def _unit_job(name, commands, log, env=None, after=None):
    run_id = uuid.uuid4().hex
    log(f"Run id {run_id}")
    start_unit(run_id, commands, env)
    st = follow_unit(run_id, log)
    if st["status"] != "succeeded":
        raise ActionError("command_failed", f"{name} failed (exit code {st['exit_code']})", {"run": run_id})
    result = {"run": run_id}
    if after:
        result.update(after())
    return result


@action("server.upgrade")
def upgrade(params):
    mode = v.choice(params, "mode", list(UPGRADE_MODES))
    if _running_apt_units():
        raise ActionError("conflict", "Another package run is still busy")

    def work(_p, log):
        log(f"Installing updates ({mode})")
        return _unit_job("The update", UPGRADE_MODES[mode], log, after=lambda: {
            "reboot_reasons": reboot_reasons(), "remaining": len(pending_updates())})

    return start_job("server.upgrade", work, {})


@action("server.apt_status")
def apt_status(params):
    run_id = v.string(params, "run", pattern=RUN_ID_RE.pattern)
    logfile, _ = _apt_paths(run_id)
    if not os.path.exists(logfile):
        raise ActionError("not_found", "Unknown package run")
    return unit_status(run_id)


@action("server.auto_updates")
def auto_updates(params):
    """window: the panel installs updates in its maintenance window (unattended-upgrades' own
    daily run is switched off); daily: Debian's apt-daily-upgrade timer does it."""
    mode = v.choice(params, "mode", ["window", "daily"])
    if mode == "window":
        tmp = WINDOW_CONF + ".tmp"
        with open(tmp, "w") as fh:
            fh.write("// Managed by the Webhosting Panel: updates are installed in the panel's maintenance window.\n"
                     'APT::Periodic::Unattended-Upgrade "0";\n')
        os.chmod(tmp, 0o644)
        os.replace(tmp, WINDOW_CONF)
    else:
        try:
            os.unlink(WINDOW_CONF)
        except FileNotFoundError:
            pass
    return {"mode": mode}


# ---------------------------------------------------------------- reboot (never shutdown)

@action("server.reboot")
def reboot(params):
    delay = v.integer(params, "delay", required=False, default=10, minimum=5, maximum=86400)
    reason = v.string(params, "reason", required=False, default="manual", max_len=200)
    if run(["systemctl", "is-active", f"{REBOOT_UNIT}.timer"], timeout=10, check=False).stdout.strip() == "active":
        raise ActionError("conflict", "A reboot is already scheduled")
    if _running_apt_units():
        raise ActionError("conflict", "Packages are being installed; reboot when that is done")
    now = int(time.time())
    _write_json(REBOOT_MARKER, {"requested_at": now, "at": now + delay, "reason": reason, "boot_before": boot_time()})
    run(["systemd-run", "--unit", REBOOT_UNIT, "--collect", "--quiet", f"--on-active={delay}s",
         "--timer-property=AccuracySec=1s", "systemctl", "reboot"], timeout=30)
    return {"scheduled": True, "at": now + delay, "reason": reason}


@action("server.reboot_cancel")
def reboot_cancel(params):
    run(["systemctl", "stop", f"{REBOOT_UNIT}.timer"], timeout=30, check=False)
    marker = _read_json(REBOOT_MARKER, None)
    if marker:
        marker["cancelled_at"] = int(time.time())
        _write_json(REBOOT_MARKER, marker)
    return {"cancelled": True}


# ---------------------------------------------------------------- health check

def check_site(domain, ssl, timeout=20):
    scheme, port = ("https", 443) if ssl else ("http", 80)
    try:
        proc = run(["curl", "-s", "-k", "-o", "/dev/null", "-w", "%{http_code} %{time_total}",
                    "--max-time", str(timeout), "-A", "webhosting-panel-healthcheck",
                    "--resolve", f"{domain}:{port}:127.0.0.1", f"{scheme}://{domain}/"],
                   timeout=timeout + 5, check=False)
        code, _, secs = (proc.stdout or "000 0").strip().partition(" ")
        code = int(code) if code.isdigit() else 0
        return {"domain": domain, "code": code, "ms": int(float(secs or 0) * 1000),
                "ok": 0 < code < 500, "error": None if code else f"no answer (curl exit {proc.returncode})"}
    except ActionError as exc:
        return {"domain": domain, "code": 0, "ms": None, "ok": False, "error": exc.message}


@action("server.health")
def health(params):
    sites = v.list_of(params, "sites", lambda s: s if isinstance(s, dict) else {}, required=False, max_items=5000)
    checked = []
    for s in sites:
        checked.append((v.domain(s, "domain"), bool(s.get("ssl"))))
    services = []
    for unit, label in svc.known_services().items():
        st = svc._status(unit)
        if st["installed"]:
            services.append({**st, "label": label})
    with ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(lambda d: check_site(*d), checked))
    return {
        "services": services,
        "services_down": [s["service"] for s in services if not s["active"]],
        "sites": results,
        "sites_down": [r["domain"] for r in results if not r["ok"]],
        "boot_time": boot_time(),
        "checked_at": int(time.time()),
    }


# ---------------------------------------------------------------- database optimize

def _ident(name):
    return "`" + name.replace("`", "``") + "`"


def _sql(query, timeout=600):
    return run(MARIADB, input_text=query, timeout=timeout).stdout


def fragmented_tables(min_free_mb, min_ratio):
    out = _sql(
        "SELECT TABLE_SCHEMA, TABLE_NAME, IFNULL(ENGINE,''), IFNULL(DATA_LENGTH,0)+IFNULL(INDEX_LENGTH,0), IFNULL(DATA_FREE,0) "
        "FROM information_schema.TABLES WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA NOT IN ("
        + ",".join(f"'{d}'" for d in SYSTEM_DATABASES) + ");", timeout=300)
    tables = []
    for line in out.splitlines():
        parts = line.split("\t")
        if len(parts) != 5:
            continue
        schema, name, engine, size, free = parts[0], parts[1], parts[2], int(parts[3] or 0), int(parts[4] or 0)
        if engine.lower() not in ("innodb", "aria", "myisam"):
            continue
        if free >= min_free_mb * 1024 * 1024 and free >= min_ratio * max(size, 1):
            tables.append({"schema": schema, "table": name, "engine": engine, "size": size, "free": free})
    return tables


def db_optimize(params, log):
    analyze = params.get("analyze", True)
    tables = fragmented_tables(params.get("min_free_mb", 50), params.get("min_ratio", 0.1))
    log(f"{len(tables)} tables with more than {params.get('min_free_mb', 50)} MB unused space")
    freed, done, failed = 0, [], []
    for t in tables[:500]:
        log(f"Optimizing {t['schema']}.{t['table']} ({t['free'] // 1048576} MB free)")
        try:
            _sql(f"OPTIMIZE NO_WRITE_TO_BINLOG TABLE {_ident(t['schema'])}.{_ident(t['table'])};", timeout=3600)
            done.append(f"{t['schema']}.{t['table']}")
            freed += t["free"]
        except ActionError as exc:
            log(f"  failed: {exc.message}")
            failed.append(f"{t['schema']}.{t['table']}")
    if analyze:
        log("Updating index statistics (ANALYZE) for all databases")
        run(["mariadb-check", "--protocol=socket", "-uroot", "--analyze", "--all-databases", "--skip-write-binlog", "--silent"],
            timeout=3600, check=False)
    return {"optimized": done, "failed": failed, "freed_bytes": freed, "analyzed": bool(analyze)}


@action("server.db_optimize")
def db_optimize_action(params):
    p = {
        "analyze": v.boolean(params, "analyze", required=False, default=True),
        "min_free_mb": v.integer(params, "min_free_mb", required=False, default=50, minimum=1, maximum=100000),
        "min_ratio": 0.1,
    }
    return start_job("server.db_optimize", db_optimize, p)


# ---------------------------------------------------------------- cleanup

def _prune_tree(root, older_than, match=None, dry_run=False, stop_at=None):
    """Delete regular files older than `older_than` seconds below root.

    Walks with directory file descriptors and never follows symlinks, so a site
    user cannot point the root-run cleanup at files outside their folder."""
    count, size = 0, 0
    cutoff = time.time() - older_than
    if not os.path.isdir(root) or os.path.islink(root):
        return count, size
    for _dirpath, _dirs, files, dirfd in os.fwalk(root, follow_symlinks=False):
        for name in files:
            if match and not match(name):
                continue
            try:
                st = os.stat(name, dir_fd=dirfd, follow_symlinks=False)
            except OSError:
                continue
            if not (st.st_mode & 0o170000 == 0o100000) or st.st_mtime > cutoff:
                continue
            if not dry_run:
                try:
                    os.unlink(name, dir_fd=dirfd)
                except OSError:
                    continue
            count += 1
            size += st.st_size
            if stop_at and count >= stop_at:
                return count, size
    return count, size


def _disk_free(path="/"):
    st = os.statvfs(path)
    return st.f_bavail * st.f_frsize


CLEANUP_DEFAULTS = {
    "apt": True,            # apt autoremove --purge (old kernels) + apt clean
    "journal_days": 30,     # systemd journal older than this
    "jobs_days": 30,        # finished agent job files
    "apt_logs_days": 90,    # logs of panel package runs
    "site_tmp_days": 7,     # /var/www/vhosts/<site>/tmp
    "site_sessions_days": 2,  # PHP sessions in /var/www/vhosts/<site>/sessions
    "coredump_days": 7,
    "dry_run": False,
}


def cleanup(params, log):
    p = {**CLEANUP_DEFAULTS, **params}
    dry = p["dry_run"]
    report = {"dry_run": dry, "items": []}
    free_before = _disk_free()

    def add(key, count, size, note=None):
        report["items"].append({"key": key, "files": count, "bytes": size, "note": note})
        log(f"{key}: {count} files, {size // 1048576} MB" + (f" ({note})" if note else ""))

    if p["apt"]:
        if dry:
            proc = run(["apt-get", "-s", "-o", "Debug::NoLocking=1", "autoremove", "--purge"], timeout=180, check=False, env=APT_ENV)
            removable = [line.split()[1] for line in (proc.stdout or "").splitlines() if line.startswith("Purg ") or line.startswith("Remv ")]
            add("apt_autoremove", len(removable), 0, ", ".join(removable[:20]) or None)
        else:
            run(["apt-get", *APT_OPTS, "autoremove", "--purge"], timeout=1800, check=False, env=APT_ENV)
            add("apt_autoremove", 0, 0)
        cache = sum(os.path.getsize(f) for f in glob.glob(os.path.join(APT_ARCHIVES, "*.deb")))
        if not dry:
            run(["apt-get", "clean"], timeout=300, check=False, env=APT_ENV)
        add("apt_cache", len(glob.glob(os.path.join(APT_ARCHIVES, "*.deb"))) if dry else 0, cache)
    if p["journal_days"]:
        if dry:
            proc = run(["journalctl", "--disk-usage"], timeout=60, check=False)
            add("journal", 0, 0, (proc.stdout or "").strip() or None)
        else:
            run(["journalctl", f"--vacuum-time={int(p['journal_days'])}d"], timeout=600, check=False)
            add("journal", 0, 0)
    if p["jobs_days"]:
        add("agent_jobs", *_prune_tree(paths.JOBS_DIR, p["jobs_days"] * 86400, lambda n: n.endswith(".json"), dry))
    if p["apt_logs_days"]:
        add("apt_logs", *_prune_tree(APT_LOG_DIR, p["apt_logs_days"] * 86400, None, dry))
    if p["coredump_days"]:
        add("coredumps", *_prune_tree(COREDUMP_DIR, p["coredump_days"] * 86400, None, dry))
    tmp_n = tmp_s = ses_n = ses_s = 0
    for site in sorted(os.listdir(VHOSTS)) if os.path.isdir(VHOSTS) else []:
        base = os.path.join(VHOSTS, site)
        if os.path.islink(base):
            continue
        if p["site_tmp_days"]:
            n, s = _prune_tree(os.path.join(base, "tmp"), p["site_tmp_days"] * 86400, None, dry)
            tmp_n, tmp_s = tmp_n + n, tmp_s + s
        if p["site_sessions_days"]:
            n, s = _prune_tree(os.path.join(base, "sessions"), p["site_sessions_days"] * 86400,
                               lambda f: f.startswith("sess_"), dry)
            ses_n, ses_s = ses_n + n, ses_s + s
    if p["site_tmp_days"]:
        add("site_tmp", tmp_n, tmp_s)
    if p["site_sessions_days"]:
        add("site_sessions", ses_n, ses_s)
    report["freed_bytes"] = max(0, _disk_free() - free_before) if not dry else sum(i["bytes"] for i in report["items"])
    return report


@action("server.cleanup")
def cleanup_action(params):
    p = {"apt": v.boolean(params, "apt", required=False, default=True),
         "dry_run": v.boolean(params, "dry_run", required=False, default=False)}
    for key in ("journal_days", "jobs_days", "apt_logs_days", "site_tmp_days", "site_sessions_days", "coredump_days"):
        p[key] = v.integer(params, key, required=False, default=CLEANUP_DEFAULTS[key], minimum=0, maximum=3650)
    return start_job("server.cleanup", cleanup, p)


# ---------------------------------------------------------------- PHP versions

def _installer_value(text, name):
    m = re.search(rf"^{name}=\(([^)]*)\)", text, re.M) or re.search(rf"^{name}=(\S+)", text, re.M)
    return m.group(1).split() if m else []


def php_facts():
    try:
        with open(INSTALL_SH) as fh:
            text = fh.read()
    except OSError:
        text = ""
    supported = _installer_value(text, "SUPPORTED_PHP")
    panel = (_installer_value(text, "PANEL_PHP") or ["8.4"])[0]
    installed = sorted({os.path.basename(os.path.dirname(p)) for p in glob.glob("/etc/php/*/fpm")
                        if os.path.exists(f"/usr/sbin/php-fpm{os.path.basename(os.path.dirname(p))}")}, key=_version_key)
    configured = (_env_get(INSTALLER_ENV, "WHP_PHP") or "").replace(",", " ").split()
    return {"supported": supported, "panel": panel, "installed": installed, "configured": configured}


def _env_get(path, key):
    try:
        with open(path) as fh:
            for line in fh:
                if line.startswith(key + "="):
                    return line.split("=", 1)[1].strip().strip("'\"")
    except OSError:
        pass
    return None


def _env_set(path, key, value):
    lines, found = [], False
    with open(path) as fh:
        lines = fh.read().splitlines()
    for i, line in enumerate(lines):
        if line.startswith(key + "="):
            lines[i], found = f"{key}={value}", True
    if not found:
        lines.append(f"{key}={value}")
    st = os.stat(path)
    tmp = path + ".tmp"
    with open(tmp, "w") as fh:
        fh.write("\n".join(lines) + "\n")
    os.chown(tmp, st.st_uid, st.st_gid)
    os.chmod(tmp, st.st_mode & 0o777)
    os.replace(tmp, path)


def sites_on_php(version):
    used = []
    for f in glob.glob(os.path.join(SITES_DB, "*.env")):
        if _env_get(f, "SITE_PHP") == version:
            used.append(_env_get(f, "SITE_DOMAIN") or os.path.basename(f)[:-4])
    return sorted(used)


@action("server.php_versions")
def php_versions(params):
    facts = php_facts()
    facts["sites"] = {ver: len(sites_on_php(ver)) for ver in facts["installed"]}
    return facts


def _php_version_param(params):
    ver = v.string(params, "version", pattern=PHP_RE.pattern, max_len=3)
    return ver


@action("server.php_install")
def php_install(params):
    ver = _php_version_param(params)
    facts = php_facts()
    if ver not in facts["supported"]:
        raise ActionError("invalid_params", f"PHP {ver} is not supported by this installer ({', '.join(facts['supported'])})")
    if ver in facts["installed"]:
        raise ActionError("conflict", f"PHP {ver} is already installed")
    if _running_apt_units():
        raise ActionError("conflict", "Another package run is still busy")

    def work(_p, log):
        configured = [x for x in facts["configured"] if x != ver] + [ver]
        _env_set(INSTALLER_ENV, "WHP_PHP", ",".join(sorted(set(configured), key=_version_key)))
        log(f"Installing PHP {ver} (installer --upgrade with the saved answers)")
        return _unit_job(f"Installing PHP {ver}", [["/bin/bash", INSTALL_SH, "--upgrade"]], log,
                         env={"WHP_LANG": "en", "TERM": "dumb"}, after=lambda: {"installed": php_facts()["installed"]})

    return start_job("server.php_install", work, {})


@action("server.php_remove")
def php_remove(params):
    ver = _php_version_param(params)
    facts = php_facts()
    if ver == facts["panel"]:
        raise ActionError("forbidden", f"PHP {ver} runs the panel itself and cannot be removed")
    if ver not in facts["installed"]:
        raise ActionError("not_found", f"PHP {ver} is not installed")
    if len([x for x in facts["installed"] if x != ver]) < 1:
        raise ActionError("forbidden", "The last PHP version cannot be removed")
    used = sites_on_php(ver)
    if used:
        raise ActionError("conflict", f"{len(used)} websites still use PHP {ver}", {"sites": used[:50]})
    if _running_apt_units():
        raise ActionError("conflict", "Another package run is still busy")
    listed = run(["dpkg-query", "-W", "-f", "${Package} ${db:Status-Abbrev}\n", f"php{ver}*", f"libapache2-mod-php{ver}"],
                 timeout=60, check=False).stdout
    pkgs = sorted({line.split()[0] for line in listed.splitlines()
                   if len(line.split()) > 1 and line.split()[1].startswith("i")
                   and re.match(rf"^(php{re.escape(ver)}(-|$)|libapache2-mod-php{re.escape(ver)}$)", line.split()[0])})

    def work(_p, log):
        configured = [x for x in facts["configured"] if x != ver]
        if os.path.exists(INSTALLER_ENV):
            _env_set(INSTALLER_ENV, "WHP_PHP", ",".join(configured))
        log(f"Removing PHP {ver}: {' '.join(pkgs) or 'no packages'}")
        commands = [["apt-get", *APT_OPTS, "purge", *pkgs]] if pkgs else []
        commands.append(["systemctl", "reset-failed"])

        def after():
            state = os.path.join(paths.STATE_DIR, "php-versions")
            left = php_facts()["installed"]
            with open(state, "w") as fh:
                fh.write("".join(f"{x}\n" for x in left))
            return {"removed": pkgs, "installed": left}

        return _unit_job(f"Removing PHP {ver}", commands, log, after=after)

    return start_job("server.php_remove", work, {})
