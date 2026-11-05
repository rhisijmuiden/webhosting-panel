"""Backups, through the whp-backup CLI (backup/bin/whp-backup).

Secrets (passwords, SSH keys, OneDrive tokens, repository keys) arrive as
params and are handed to whp-backup as files in a private temporary folder,
never on the command line, so they do not show up in `ps`.
"""

import json
import os
import re
import shutil
import tempfile
from pathlib import Path

from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

WHP_BACKUP = "/usr/local/sbin/whp-backup"
SYSTEMD_DIR = Path("/etc/systemd/system")
SECRETS_TMP = "/run/whp-agent"

TYPES = ["sftp", "scp", "ftp", "ftps", "onedrive", "local"]
TARGET_NAME = r"[a-z0-9][a-z0-9_-]{0,39}"
SNAPSHOT = r"[0-9a-f]{8}|[0-9a-f]{64}|latest"
SCOPE = r"all|system|sites|mail|databases|(site|mail):[a-z0-9.-]{1,253}|db:[A-Za-z0-9_]{1,64}"
DAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]

# whp-backup error codes mapped onto the agent's codes.
_CODES = {
    "invalid_params": "invalid_params",
    "not_found": "not_found",
    "conflict": "conflict",
    "busy": "conflict",
}


def _backup(argv, timeout=120):
    proc = run([WHP_BACKUP, "--json", *argv], timeout=timeout, check=False)
    try:
        doc = json.loads((proc.stdout or "").strip().splitlines()[-1])
    except (ValueError, IndexError):
        raise ActionError("command_failed", "whp-backup returned no JSON", {"stderr": proc.stderr[-2000:]})
    if not doc.get("ok"):
        err = doc.get("error") or {}
        code = err.get("code", "command_failed")
        raise ActionError(_CODES.get(code, "command_failed"), err.get("message", "whp-backup failed"), {"code": code})
    return doc.get("data")


def _target(params, key="target", required=True):
    return v.string(params, key, required=required, max_len=40, pattern=TARGET_NAME)


def _scopes(params):
    return v.list_of(params, "scopes", lambda s: v.string({"s": s}, "s", max_len=270, pattern=SCOPE),
                     required=False, max_items=200)


class _Secrets:
    """Private folder for secret files that lives only during one call."""

    def __enter__(self):
        base = SECRETS_TMP if os.path.isdir(SECRETS_TMP) else None
        self.dir = tempfile.mkdtemp(prefix="backup-", dir=base)
        os.chmod(self.dir, 0o700)
        return self

    def file(self, name, content):
        path = os.path.join(self.dir, name)
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w") as fh:
            fh.write(content if content.endswith("\n") else content + "\n")
        return path

    def __exit__(self, *exc):
        shutil.rmtree(self.dir, ignore_errors=True)


def _secret(params, key, max_len=16384):
    value = params.get(key)
    if value is None or value == "":
        return None
    if not isinstance(value, str) or len(value) > max_len or "\x00" in value:
        raise ActionError("invalid_params", f"{key} is invalid")
    return value


@action("backup.status")
def backup_status(params):
    return _backup(["status"], timeout=60)


@action("backup.targets")
def backup_targets(params):
    return {"targets": _backup(["target", "list"], timeout=30)}


@action("backup.target_add")
def backup_target_add(params):
    kind = v.choice(params, "type", TYPES)
    argv = ["target", "add", "--name", _target(params, "name"), "--type", kind,
            "--path", v.string(params, "path", max_len=500, pattern=r"[^\"]+")]
    host = v.string(params, "host", required=False, max_len=253, pattern=r"[A-Za-z0-9.:-]+")
    if host:
        argv += ["--host", host]
    port = v.integer(params, "port", required=False, minimum=1, maximum=65535)
    if port:
        argv += ["--port", str(port)]
    user = v.string(params, "user", required=False, max_len=128, pattern=r"[A-Za-z0-9._@+-]+")
    if user:
        argv += ["--user", user]
    if kind == "ftps":
        argv += ["--tls", v.choice(params, "tls", ["explicit", "implicit"], required=False, default="explicit")]
    if kind == "ftp" and v.boolean(params, "allow_plain_ftp", required=False, default=False):
        argv.append("--allow-plain-ftp")
    if kind == "onedrive":
        drive_id = v.string(params, "drive_id", required=False, max_len=200, pattern=r"[A-Za-z0-9!._-]+")
        if drive_id:
            argv += ["--onedrive-drive-id", drive_id]
        drive_type = v.choice(params, "drive_type", ["personal", "business", "documentLibrary"], required=False)
        if drive_type:
            argv += ["--onedrive-drive-type", drive_type]
    if v.boolean(params, "update", required=False, default=False):
        argv.append("--update")

    with _Secrets() as s:
        for key, flag, fname in (("password", "--password-file", "password"),
                                 ("ssh_key", "--ssh-key", "id_key"),
                                 ("known_hosts", "--known-hosts", "known_hosts"),
                                 ("onedrive_token", "--onedrive-token-file", "token.json"),
                                 ("repo_key", "--repo-key-file", "repo.key")):
            value = _secret(params, key)
            if value is not None:
                argv += [flag, s.file(fname, value)]
        return _backup(argv, timeout=300)


@action("backup.target_test")
def backup_target_test(params):
    proc = run([WHP_BACKUP, "--json", "target", "test", _target(params)], timeout=120, check=False)
    try:
        doc = json.loads(proc.stdout.strip().splitlines()[-1])
    except (ValueError, IndexError):
        raise ActionError("command_failed", "whp-backup returned no JSON")
    if not doc.get("ok"):
        err = doc.get("error") or {}
        raise ActionError(_CODES.get(err.get("code"), "command_failed"), err.get("message", "test failed"))
    return doc["data"]


@action("backup.target_remove")
def backup_target_remove(params):
    return _backup(["target", "remove", _target(params)], timeout=30)


@action("backup.target_export_key")
def backup_target_export_key(params):
    return _backup(["target", "export-key", _target(params)], timeout=30)


@action("backup.list")
def backup_list(params):
    argv = ["snapshots", "--target", _target(params)]
    scope = v.string(params, "scope", required=False, max_len=270, pattern=SCOPE)
    if scope:
        argv += ["--scope", scope]
    host = v.string(params, "host", required=False, max_len=253, pattern=r"[A-Za-z0-9.-]+")
    if host:
        argv += ["--host", host]
    return {"snapshots": _backup(argv, timeout=180)}


def _run_job(argv, timeout):
    def work(_params, log):
        log("whp-backup " + " ".join(argv))
        return _backup(argv, timeout=timeout)
    return work


@action("backup.run")
def backup_run(params):
    argv = ["run", "--full" if v.boolean(params, "full", required=False, default=False) else "--incremental"]
    target = _target(params, required=False)
    if target:
        argv += ["--target", target]
    for scope in _scopes(params):
        argv += ["--scope", scope]
    if v.boolean(params, "prune", required=False, default=False):
        argv.append("--prune")
    return start_job("backup.run", _run_job(argv, timeout=24 * 3600), params)


@action("backup.restore")
def backup_restore(params):
    argv = ["restore", "--target", _target(params),
            "--snapshot", v.string(params, "snapshot", max_len=64, pattern=SNAPSHOT)]
    mode = v.choice(params, "mode", ["copy", "in_place"])
    if mode == "copy":
        to = v.string(params, "to", max_len=500, pattern=r"/[A-Za-z0-9._/-]*")
        if ".." in to.split("/"):
            raise ActionError("invalid_params", "to may not contain ..")
        argv += ["--to", to]
    else:
        argv.append("--in-place")
    include = v.string(params, "include", required=False, max_len=1000, pattern=r"/[^\n]*")
    if include:
        if ".." in include.split("/"):
            raise ActionError("invalid_params", "include may not contain ..")
        argv += ["--include", include]
    database = v.string(params, "database", required=False, max_len=64, pattern=r"[A-Za-z0-9_]+")
    if database:
        argv += ["--database", database]
    return start_job("backup.restore", _run_job(argv, timeout=24 * 3600), params)


@action("backup.check")
def backup_check(params):
    argv = ["check", "--target", _target(params)]
    if v.boolean(params, "read_data", required=False, default=False):
        argv.append("--read-data")
    return start_job("backup.check", _run_job(argv, timeout=12 * 3600), params)


@action("backup.prune")
def backup_prune(params):
    return start_job("backup.prune", _run_job(["prune", "--target", _target(params)], timeout=12 * 3600), params)


def _time(params, key, default):
    value = v.string(params, key, required=False, default=default, max_len=5, pattern=r"([01][0-9]|2[0-3]):[0-5][0-9]")
    return value


def _dropin(timer, calendar, enabled):
    d = SYSTEMD_DIR / f"{timer}.d"
    d.mkdir(parents=True, exist_ok=True)
    (d / "schedule.conf").write_text(f"# Managed by the panel\n[Timer]\nOnCalendar=\nOnCalendar={calendar}\n")
    run(["systemctl", "daemon-reload"], timeout=30)
    run(["systemctl", "enable" if enabled else "disable", "--now", timer], timeout=30)


@action("backup.schedule")
def backup_schedule(params):
    """Set the backup times: daily incremental and weekly full."""
    inc_time = _time(params, "incremental_time", "02:30")
    full_time = _time(params, "full_time", "03:30")
    full_day = v.choice(params, "full_day", DAYS, required=False, default="Sun")
    inc_on = v.boolean(params, "incremental_enabled", required=False, default=True)
    full_on = v.boolean(params, "full_enabled", required=False, default=True)
    _dropin("whp-backup-incremental.timer", f"*-*-* {inc_time}:00", inc_on)
    _dropin("whp-backup-full.timer", f"{full_day} *-*-* {full_time}:00", full_on)
    return {
        "incremental": {"enabled": inc_on, "time": inc_time},
        "full": {"enabled": full_on, "day": full_day, "time": full_time},
    }


@action("backup.get_schedule")
def backup_get_schedule(params):
    def read(timer, default):
        f = SYSTEMD_DIR / f"{timer}.d" / "schedule.conf"
        cal = default
        if f.exists():
            found = re.findall(r"^OnCalendar=(.+)$", f.read_text(), re.M)
            if found:
                cal = found[-1]
        enabled = run(["systemctl", "is-enabled", timer], timeout=15, check=False).stdout.strip() == "enabled"
        return cal, enabled

    inc_cal, inc_on = read("whp-backup-incremental.timer", "*-*-* 02:30:00")
    full_cal, full_on = read("whp-backup-full.timer", "Sun *-*-* 03:30:00")
    full_parts = full_cal.split()
    return {
        "incremental": {"enabled": inc_on, "time": inc_cal.split()[-1][:5]},
        "full": {"enabled": full_on, "day": full_parts[0] if len(full_parts) == 3 else "Sun",
                 "time": full_parts[-1][:5]},
    }
