"""Cron jobs of a website (panel: Cron jobs).

Every cron job is a systemd timer + oneshot service that runs as the site's
own Linux user, in the subscription's resource jail (slice), so its CPU,
memory and disk use count for the website like PHP does:

    /etc/systemd/system/whp-cron-<id>.timer     when (OnCalendar, from the cron expression)
    /etc/systemd/system/whp-cron-<id>.service   what (bash -c, PHP script or URL)
    /var/lib/webhosting-panel/cron/<id>.json    state (domain, schedule, type ...)
    /var/lib/webhosting-panel/cron/runs/<id>.jsonl  last runs (written by cron_finish.py)

After every run, ExecStopPost (as root) calls whp_agent/cron_finish.py, which
saves the result, the duration and the output of that run. The panel reads the
runs with cron.runs every minute, keeps them and mails the customer when a run
failed. A job never runs twice at the same time: while it still runs, the
timer skips. The names whp-cron-* stay apart from whp-wpcron-* (WP-cron) and
whp-app-* (app installer background tasks).
"""

import json
import os
import re
import shlex
import time

from .. import paths, wordpress
from .. import validate as v
from ..registry import ActionError, action
from ..run import run

SYSTEMD_DIR = os.environ.get("WHP_SYSTEMD_DIR", "/etc/systemd/system")
STATE_DIR = os.path.join(paths.STATE_DIR, "cron")
RUNS_DIR = os.path.join(STATE_DIR, "runs")
# `php` in a cron command is the PHP version of the website: a folder per version
# with one symlink, put first on PATH (world readable, unlike the state dir).
PHP_DIR = os.environ.get("WHP_CRON_PHP_DIR", "/usr/local/lib/whp-cron/php")
FINISH = os.path.join(paths.ROOT_DIR, "agent", "whp_agent", "cron_finish.py")
ZONEINFO = os.environ.get("WHP_ZONEINFO", "/usr/share/zoneinfo")

TYPES = ("command", "php", "url")
TIMEOUTS = (1, 5, 15, 30, 60, 120, 240, 480, 720, 1440)  # minutes
KEEP_RUNS = 50
_ID = r"[a-f0-9]{10}"
_REL = re.compile(r"^[A-Za-z0-9_.][A-Za-z0-9_.@+-]{0,99}(/[A-Za-z0-9_.][A-Za-z0-9_.@+-]{0,99}){0,15}$")
_URL = re.compile(r"^https?://[^\s\"'\\]{1,2000}$")
_TZ = re.compile(r"^[A-Za-z0-9_+-]{1,40}(/[A-Za-z0-9_+-]{1,40}){0,2}$")

# ----------------------------------------------------------------- schedule

MACROS = {
    "@yearly": "0 0 1 1 *", "@annually": "0 0 1 1 *", "@monthly": "0 0 1 * *",
    "@weekly": "0 0 * * 0", "@daily": "0 0 * * *", "@midnight": "0 0 * * *", "@hourly": "0 * * * *",
}
_FIELDS = (  # name, minimum, maximum, names
    ("minute", 0, 59, None),
    ("hour", 0, 23, None),
    ("day of month", 1, 31, None),
    ("month", 1, 12, ["jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"]),
    ("day of week", 0, 7, ["sun", "mon", "tue", "wed", "thu", "fri", "sat"]),
)
_DOW = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"]


def _value(text, field):
    name, lo, hi, names = field
    t = text.lower()
    if names and t in names:
        return names.index(t) + (1 if name == "month" else 0)
    if not t.isdigit() or len(t) > 2:
        raise ActionError("invalid_params", f"Invalid {name}: {text}")
    n = int(t)
    if n < lo or n > hi:
        raise ActionError("invalid_params", f"The {name} must be between {lo} and {hi}")
    return n


def _expand(text, field):
    """One cron field ("*/5", "1-5", "mon-fri", "0,30") as a sorted list of values."""
    name, lo, hi, _ = field
    out = set()
    for part in text.split(","):
        if not part:
            raise ActionError("invalid_params", f"Invalid {name}: {text}")
        rng, _, step_text = part.partition("/")
        step = 1
        if step_text:
            if not step_text.isdigit() or not 0 < int(step_text) <= hi:
                raise ActionError("invalid_params", f"Invalid step in the {name}: {part}")
            step = int(step_text)
        if rng == "*":
            start, end = lo, hi
        elif "-" in rng:
            a, _, b = rng.partition("-")
            start, end = _value(a, field), _value(b, field)
            if start > end:
                raise ActionError("invalid_params", f"Invalid range in the {name}: {part}")
        else:
            start = _value(rng, field)
            end = hi if step_text else start  # "5/15" = from 5 every 15
        out.update(range(start, end + 1, step))
    if name == "day of week" and 7 in out:  # 7 is Sunday too
        out.discard(7)
        out.add(0)
    return sorted(out)


def parse_schedule(expr):
    """A five field cron expression (or @daily ...) as five value lists.
    Returns (fields, restricted) where restricted tells whether day of month and
    day of week were limited (then cron runs when EITHER matches)."""
    if not isinstance(expr, str):
        raise ActionError("invalid_params", "schedule must be a string")
    expr = " ".join(expr.split())
    expr = MACROS.get(expr.lower(), expr)
    parts = expr.split(" ")
    if len(parts) != 5:
        raise ActionError("invalid_params", "The schedule needs five fields: minute hour day month weekday")
    fields = [_expand(p, f) for p, f in zip(parts, _FIELDS)]
    restricted = (not parts[2].startswith("*"), not parts[4].startswith("*"))
    return fields, restricted


def _join(values, lo, hi, fmt="{:02d}"):
    if values == list(range(lo, hi + 1)):
        return "*"
    return ",".join(fmt.format(n) for n in values)


def to_calendar(expr, timezone=None):
    """systemd OnCalendar= lines for a cron expression (one, or two for the OR of
    day of month and day of week)."""
    fields, (dom_set, dow_set) = parse_schedule(expr)
    minute, hour, dom, month, dow = fields
    tz = f" {timezone}" if timezone else ""
    time_part = f"{_join(hour, 0, 23)}:{_join(minute, 0, 59)}:00"
    mon = _join(month, 1, 12)
    days = _join(dom, 1, 31)
    weekdays = "" if dow == list(range(0, 7)) else ",".join(_DOW[d] for d in dow) + " "
    if dom_set and dow_set:
        return [f"*-{mon}-{days} {time_part}{tz}", f"{weekdays}*-{mon}-* {time_part}{tz}"]
    if dom_set:
        return [f"*-{mon}-{days} {time_part}{tz}"]
    if dow_set:
        return [f"{weekdays}*-{mon}-* {time_part}{tz}"]
    return [f"*-{mon}-* {time_part}{tz}"]


# ----------------------------------------------------------------- state

def unit_base(job_id):
    return f"whp-cron-{job_id}"


def _state_file(job_id):
    return os.path.join(STATE_DIR, f"{job_id}.json")


def read_state(job_id):
    try:
        with open(_state_file(job_id)) as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return None


def _write(path, text, mode=0o644):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = f"{path}.whp-new"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, mode)
    with os.fdopen(fd, "w") as fh:
        fh.write(text)
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def write_state(st):
    os.makedirs(STATE_DIR, mode=0o750, exist_ok=True)
    _write(_state_file(st["id"]), json.dumps(st, indent=1), 0o640)


def all_states():
    try:
        names = sorted(os.listdir(STATE_DIR))
    except OSError:
        return []
    out = []
    for n in names:
        if re.fullmatch(rf"{_ID}\.json", n):
            st = read_state(n[:-5])
            if st:
                out.append(st)
    return out


# ----------------------------------------------------------------- validation

def _job_id(params):
    return v.string(params, "id", max_len=10, pattern=_ID)


def _timezone(params):
    tz = v.string(params, "timezone", required=False, default="", max_len=100) or ""
    if not tz or tz == "UTC":
        return tz or None
    if not _TZ.fullmatch(tz) or ".." in tz or not os.path.isfile(os.path.join(ZONEINFO, tz)):
        raise ActionError("invalid_params", f"Unknown timezone: {tz}")
    return tz


def _job_params(params):
    kind = v.choice(params, "type", TYPES)
    command = v.string(params, "command", max_len=2000)
    if not command.strip() or "\r" in command:
        raise ActionError("invalid_params", "command may not be empty")
    command = command.strip()
    args = (v.string(params, "args", required=False, default="", max_len=1000) or "").strip()
    if "\r" in args:
        raise ActionError("invalid_params", "args contains invalid characters")
    if kind == "php":
        script = command.lstrip("/")
        if not _REL.fullmatch(script) or any(s in (".", "..") for s in script.split("/")):
            raise ActionError("invalid_params", "The PHP script must be a path inside the website folder, like public/cron.php")
        command = script
    elif kind == "url" and not _URL.fullmatch(command):
        raise ActionError("invalid_params", "The URL must start with http:// or https://")
    schedule = v.string(params, "schedule", max_len=200)
    tz = _timezone(params)
    to_calendar(schedule, tz)  # validates
    return {
        "id": _job_id(params),
        "domain": v.domain(params, "domain"),
        "name": (v.string(params, "name", required=False, default="", max_len=80) or "").strip(),
        "type": kind,
        "command": command,
        "args": args if kind == "php" else "",
        "schedule": " ".join(schedule.split()),
        "timezone": tz,
        "timeout": v.choice(params, "timeout", TIMEOUTS, required=False, default=60),
        "enabled": bool(v.boolean(params, "enabled", required=False, default=True)),
    }


def _site_for(domain):
    site = wordpress.site_info(domain)
    if not re.fullmatch(r"s_[a-z0-9_]{1,30}", site.get("user") or ""):
        raise ActionError("internal", f"Website {domain} has no valid system user")
    return site


# ----------------------------------------------------------------- files

def _unit_quote(text):
    """One argument for ExecStart: systemd unquotes it, so escape quotes, backslashes, % and $."""
    return '"' + text.replace("\\", "\\\\").replace('"', '\\"').replace("%", "%%").replace("$", "$$") + '"'


def _unit_text(text):
    """Free text (Description=): one line, no specifiers."""
    return " ".join(str(text).split()).replace("%", "%%")[:200]


def php_bin_dir(version):
    return os.path.join(PHP_DIR, version)


def ensure_php_dir(version):
    """/usr/local/lib/whp-cron/php/<version>/php -> /usr/bin/php<version>."""
    d = php_bin_dir(version)
    link = os.path.join(d, "php")
    target = f"/usr/bin/php{version}"
    os.makedirs(d, mode=0o755, exist_ok=True)
    if os.path.islink(link) and os.readlink(link) == target:
        return d
    if os.path.lexists(link):
        os.unlink(link)
    os.symlink(target, link)
    return d


def shell_command(site, job):
    """What bash -c runs for the job."""
    if job["type"] == "php":
        args = f" {job['args']}" if job.get("args") else ""
        return f"exec /usr/bin/php{site['php']} {shlex.quote(job['command'])}{args}"
    if job["type"] == "url":
        seconds = max(30, int(job["timeout"]) * 60 - 5)
        return ("exec /usr/bin/curl -sS -f -L --proto =http,https --max-redirs 5 "
                f"--max-time {seconds} -A whp-cron/1.0 -o /dev/null "
                "-w 'HTTP %{http_code}, %{size_download} bytes in %{time_total} s\\n' -- "
                f"{shlex.quote(job['command'])}")
    return job["command"]


def render_service(site, job):
    base = unit_base(job["id"])
    label = _unit_text(job.get("name") or job["id"])
    path = f"{php_bin_dir(site['php'])}:/usr/local/bin:/usr/bin:/bin"
    root = site["root"]
    env = (f"Environment=HOME={root} TMPDIR={root}/tmp PATH={path} LANG=C.UTF-8 SITE_ROOT={root}"
           f" DOCUMENT_ROOT={site['docroot']} WHP_CRON_JOB={job['id']}\n")
    return (
        "# Managed by whp-agent (Cron jobs). Changes are overwritten.\n"
        "[Unit]\n"
        f"Description=Cron job {label} of {site['domain']}\n"
        "After=network-online.target mariadb.service valkey-server.service\n"
        "Wants=network-online.target\n\n"
        "[Service]\n"
        "Type=oneshot\n"
        f"User={site['user']}\nGroup={site['user']}\n"
        f"Slice={site.get('slice') or 'whp-sites.slice'}\n"
        f"WorkingDirectory={root}\n"
        f"{env}"
        f"ExecStart=/bin/bash --noprofile --norc -c {_unit_quote(shell_command(site, job))}\n"
        # As root, after every run (also a failed or timed out one): save the result.
        f"ExecStopPost=+/usr/bin/python3 -I {FINISH} {job['id']} {STATE_DIR}\n"
        f"TimeoutStartSec={int(job['timeout'])}min\nTimeoutStopSec=30s\n"
        "KillMode=control-group\n"
        "TasksMax=256\nNice=10\nIOSchedulingClass=best-effort\nIOSchedulingPriority=7\n"
        f"SyslogIdentifier={base}\n"
        "UMask=0022\n"
        "NoNewPrivileges=yes\nPrivateTmp=yes\nPrivateDevices=yes\n"
        "ProtectSystem=full\nProtectHome=yes\n"
        "ProtectKernelTunables=yes\nProtectKernelModules=yes\nProtectKernelLogs=yes\n"
        "ProtectControlGroups=yes\nProtectClock=yes\nProtectHostname=yes\n"
        "RestrictSUIDSGID=yes\nRestrictRealtime=yes\nRestrictNamespaces=yes\nLockPersonality=yes\n"
        "RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX\n"
    )


def render_timer(job):
    lines = "".join(f"OnCalendar={c}\n" for c in to_calendar(job["schedule"], job.get("timezone")))
    return (
        "# Managed by whp-agent (Cron jobs). Changes are overwritten.\n"
        "[Unit]\n"
        f"Description=Schedule of cron job {_unit_text(job.get('name') or job['id'])} ({_unit_text(job['schedule'])})\n\n"
        "[Timer]\n"
        f"{lines}"
        "AccuracySec=1s\nPersistent=false\n\n"
        "[Install]\nWantedBy=timers.target\n"
    )


def _files(job_id):
    base = unit_base(job_id)
    return os.path.join(SYSTEMD_DIR, f"{base}.service"), os.path.join(SYSTEMD_DIR, f"{base}.timer")


def write_units(site, job):
    ensure_php_dir(site["php"])
    service, timer = _files(job["id"])
    _write(service, render_service(site, job))
    _write(timer, render_timer(job))


def _activate(job, suspended):
    timer = f"{unit_base(job['id'])}.timer"
    if job.get("enabled") and not suspended:
        run(["systemctl", "enable", timer], timeout=30, check=False)
        run(["systemctl", "restart", timer], timeout=30, check=False)
    else:
        run(["systemctl", "disable", "--now", timer], timeout=30, check=False)


# ----------------------------------------------------------------- status

def _ts(value):
    """systemctl show --timestamp=unix gives "@1793957400" (or "" / "n/a")."""
    value = (value or "").strip()
    return int(value[1:]) if value.startswith("@") and value[1:].isdigit() else None


def job_status(job_id):
    base = unit_base(job_id)
    proc = run(["systemctl", "show", f"{base}.service", f"{base}.timer", "--timestamp=unix",
                "-p", "Id,ActiveState,SubState,UnitFileState,NextElapseUSecRealtime,LastTriggerUSec,ExecMainStartTimestamp"],
               timeout=20, check=False)
    blocks, cur = {}, {}
    for line in (proc.stdout or "").splitlines() + [""]:
        if not line.strip():
            if cur.get("Id"):
                blocks[cur["Id"]] = cur
            cur = {}
            continue
        k, _, val = line.partition("=")
        cur[k] = val
    svc = blocks.get(f"{base}.service", {})
    tmr = blocks.get(f"{base}.timer", {})
    running = svc.get("ActiveState") in ("activating", "active", "deactivating")
    return {
        "id": job_id,
        "running": running,
        "running_since": _ts(svc.get("ExecMainStartTimestamp")) if running else None,
        "scheduled": tmr.get("ActiveState") == "active",
        "next_run": _ts(tmr.get("NextElapseUSecRealtime")),
        "last_trigger": _ts(tmr.get("LastTriggerUSec")),
    }


# ----------------------------------------------------------------- runs

def _runs_file(job_id):
    return os.path.join(RUNS_DIR, f"{job_id}.jsonl")


def read_runs(job_id):
    try:
        with open(_runs_file(job_id)) as fh:
            lines = fh.readlines()
    except OSError:
        return []
    out = []
    for line in lines:
        try:
            out.append(json.loads(line))
        except ValueError:
            continue
    return out


# ----------------------------------------------------------------- actions

@action("cron.apply")
def cron_apply(params):
    """Write (or rewrite) the job's units from the panel's settings and (de)activate its timer."""
    job = _job_params(params)
    site = _site_for(job["domain"])
    old = read_state(job["id"])
    if old and old.get("domain") != job["domain"]:
        raise ActionError("conflict", "This cron job belongs to another website")
    write_units(site, job)
    write_state({**job, "updated": int(time.time())})
    run(["systemctl", "daemon-reload"], timeout=60)
    _activate(job, site.get("suspended"))
    return {"id": job["id"], "unit": f"{unit_base(job['id'])}.timer",
            "calendar": to_calendar(job["schedule"], job.get("timezone")), "status": job_status(job["id"])}


@action("cron.calendar")
def cron_calendar(params):
    """Check a schedule without saving anything: the OnCalendar= lines it becomes."""
    tz = _timezone(params)
    return {"calendar": to_calendar(v.string(params, "schedule", max_len=200), tz)}


@action("cron.list")
def cron_list(params):
    """Live status (running, next run) of many jobs: {ids: [...]} or every job of {domain}."""
    ids = params.get("ids")
    domain = v.domain(params, "domain", required=False)
    if ids is not None and (not isinstance(ids, list) or len(ids) > 1000
                            or not all(isinstance(i, str) and re.fullmatch(_ID, i) for i in ids)):
        raise ActionError("invalid_params", "ids must be a list of cron job ids")
    out = {}
    for st in all_states():
        if (ids is not None and st["id"] not in ids) or (domain and st.get("domain") != domain):
            continue
        out[st["id"]] = {"domain": st.get("domain"), "enabled": st.get("enabled"), **job_status(st["id"])}
    return {"jobs": out}


@action("cron.run")
def cron_run(params):
    """Start the job now (in the background). Nothing happens when it already runs."""
    job_id = _job_id(params)
    st = read_state(job_id)
    if not st:
        raise ActionError("not_found", "Unknown cron job")
    status = job_status(job_id)
    if status["running"]:
        return {"started": False, **status}
    run(["systemctl", "reset-failed", f"{unit_base(job_id)}.service"], timeout=20, check=False)
    run(["systemctl", "start", "--no-block", f"{unit_base(job_id)}.service"], timeout=30)
    return {"started": True, **job_status(job_id)}


@action("cron.stop")
def cron_stop(params):
    """Stop a running job (its run is saved as stopped)."""
    job_id = _job_id(params)
    if not read_state(job_id):
        raise ActionError("not_found", "Unknown cron job")
    os.makedirs(RUNS_DIR, mode=0o750, exist_ok=True)
    with open(os.path.join(RUNS_DIR, f"{job_id}.stopped"), "w") as fh:
        fh.write(str(int(time.time())))
    run(["systemctl", "stop", f"{unit_base(job_id)}.service"], timeout=60, check=False)
    return job_status(job_id)


@action("cron.runs")
def cron_runs(params):
    """Runs that finished after `since` (unix time) for every job, oldest first.
    The panel asks every minute with a little overlap and keeps them by invocation."""
    since = params.get("since", 0)
    if isinstance(since, bool) or not isinstance(since, (int, float)) or since < 0:
        raise ActionError("invalid_params", "since must be a unix time")
    limit = v.integer(params, "limit", required=False, default=1000, minimum=1, maximum=5000)
    ids = params.get("ids")
    if ids is not None and (not isinstance(ids, list) or not all(isinstance(i, str) and re.fullmatch(_ID, i) for i in ids)):
        raise ActionError("invalid_params", "ids must be a list of cron job ids")
    try:
        names = os.listdir(RUNS_DIR)
    except OSError:
        names = []
    out = []
    for n in names:
        if not re.fullmatch(rf"{_ID}\.jsonl", n) or (ids is not None and n[:-6] not in ids):
            continue
        out += [r for r in read_runs(n[:-6]) if (r.get("finished") or 0) > since]
    out.sort(key=lambda r: r.get("finished") or 0)
    return {"runs": out[-limit:], "now": time.time()}


@action("cron.suspend")
def cron_suspend(params):
    """Pause (suspended=true) or resume every enabled job of a website."""
    domain = v.domain(params, "domain")
    suspended = v.boolean(params, "suspended")
    done = []
    for st in all_states():
        if st.get("domain") == domain:
            if suspended:
                run(["systemctl", "stop", f"{unit_base(st['id'])}.service"], timeout=60, check=False)
            _activate(st, suspended)
            done.append(st["id"])
    return {"domain": domain, "suspended": suspended, "jobs": done}


@action("cron.remove")
def cron_remove(params):
    """Stop the job and remove its units, state and run history."""
    job_id = _job_id(params)
    base = unit_base(job_id)
    run(["systemctl", "disable", "--now", f"{base}.timer"], timeout=30, check=False)
    run(["systemctl", "stop", f"{base}.service"], timeout=60, check=False)
    for f in (*_files(job_id), _state_file(job_id), _runs_file(job_id), os.path.join(RUNS_DIR, f"{job_id}.stopped")):
        if os.path.exists(f):
            os.unlink(f)
    run(["systemctl", "daemon-reload"], timeout=60, check=False)
    run(["systemctl", "reset-failed", f"{base}.service"], timeout=20, check=False)
    return {"id": job_id, "removed": True}


def refresh_units(domain):
    """Rewrite the jobs of a site, e.g. after its PHP version or resource slice changed."""
    changed = []
    for st in all_states():
        if st.get("domain") != domain:
            continue
        try:
            write_units(_site_for(domain), st)
            changed.append(st["id"])
        except (ActionError, OSError):
            continue
    if changed:
        run(["systemctl", "daemon-reload"], timeout=60, check=False)
    return changed
