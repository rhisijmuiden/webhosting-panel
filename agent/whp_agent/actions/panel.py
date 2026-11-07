"""Panel version, update check, update and release channel."""

import os
import time

from .. import paths
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

CHANNELS = {"production": "main", "test": "test"}
UPDATE_SCRIPT = os.path.join(paths.ROOT_DIR, "agent", "scripts", "update.sh")
# The updater runs in its own systemd unit, outside the agent's cgroup: an agent
# restart (needrestart after apt, a crash, the update itself) or a timeout here
# must never kill it halfway, which left the panel in maintenance mode (HTTP 503).
UPDATE_UNIT = "whp-panel-update"
UPDATE_LOG = os.path.join(paths.LOG_DIR, "panel-update.log")
UPDATE_TIMEOUT = 3 * 3600


def git(*args, timeout=60, check=True):
    return run(["git", "-c", "safe.directory=" + paths.ROOT_DIR, "-C", paths.ROOT_DIR, *args],
               timeout=timeout, check=check).stdout.strip()


def read_env(key, default=None, path=None):
    try:
        with open(path or paths.PANEL_ENV) as fh:
            for line in fh:
                if line.startswith(key + "="):
                    return line.split("=", 1)[1].strip().strip('"')
    except FileNotFoundError:
        pass
    return default


def write_env(key, value, path=None):
    path = path or paths.PANEL_ENV
    lines = []
    found = False
    try:
        with open(path) as fh:
            lines = fh.read().splitlines()
    except FileNotFoundError:
        pass
    for i, line in enumerate(lines):
        if line.startswith(key + "="):
            lines[i] = f"{key}={value}"
            found = True
    if not found:
        lines.append(f"{key}={value}")
    tmp = path + ".tmp"
    st = os.stat(path) if os.path.exists(path) else None
    with open(tmp, "w") as fh:
        fh.write("\n".join(lines) + "\n")
    if st:
        os.chown(tmp, st.st_uid, st.st_gid)
        os.chmod(tmp, st.st_mode & 0o777)
    os.replace(tmp, path)


def current_channel():
    channel = read_env("WHP_CHANNEL", "production")
    return channel if channel in CHANNELS else "production"


def local_version():
    try:
        with open(os.path.join(paths.ROOT_DIR, "VERSION")) as fh:
            return fh.read().strip()
    except FileNotFoundError:
        return "0.0.0"


@action("panel.version")
def version(params):
    return {
        "version": local_version(),
        "channel": current_channel(),
        "branch": git("rev-parse", "--abbrev-ref", "HEAD", check=False) or None,
        "commit": git("rev-parse", "--short", "HEAD", check=False) or None,
        "commit_date": git("log", "-1", "--format=%cI", check=False) or None,
    }


@action("panel.check_update")
def check_update(params):
    channel = v.choice(params, "channel", list(CHANNELS), required=False, default=current_channel())
    branch = CHANNELS[channel]
    git("fetch", "--quiet", "origin", branch, timeout=120)
    remote = f"origin/{branch}"
    remote_version = git("show", f"{remote}:VERSION", check=False) or None
    head = git("rev-parse", "HEAD")
    remote_head = git("rev-parse", remote)
    behind = int(git("rev-list", "--count", f"HEAD..{remote}", check=False) or 0)
    log = git("log", "--format=%h%x09%s", "-n", "20", f"HEAD..{remote}", check=False)
    return {
        "channel": channel,
        "branch": branch,
        "current_version": local_version(),
        "latest_version": remote_version,
        "current_commit": head[:7],
        "latest_commit": remote_head[:7],
        "update_available": head != remote_head,
        "commits_behind": behind,
        "changes": [dict(zip(("commit", "subject"), line.split("\t", 1))) for line in log.splitlines() if "\t" in line],
    }


def update_running():
    return run(["systemctl", "is-active", "--quiet", UPDATE_UNIT + ".service"], check=False).returncode == 0


def update_argv(branch):
    return [
        "systemd-run", "--wait", "--collect", "--quiet", "--unit", UPDATE_UNIT,
        "--description", f"Webhosting Panel update ({branch})",
        "--property", "UMask=0022",
        "--property", f"StandardOutput=truncate:{UPDATE_LOG}",
        "--property", f"StandardError=append:{UPDATE_LOG}",
        f"--setenv=WHP_ROOT={paths.ROOT_DIR}", f"--setenv=WHP_CONFIG_DIR={paths.CONFIG_DIR}",
        "--setenv=PATH=" + "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
        "--setenv=LANG=C.UTF-8",
        "/bin/bash", UPDATE_SCRIPT, branch,
    ]


def _update_log_lines():
    try:
        with open(UPDATE_LOG, errors="replace") as fh:
            return fh.read().splitlines()
    except FileNotFoundError:
        return []


def _run_update(channel, log):
    branch = CHANNELS[channel]
    log(f"Updating to channel {channel} (branch {branch})")
    if update_running():
        raise ActionError("conflict", "An update is already running; wait for it to finish.")
    started = time.time()
    try:
        proc = run(update_argv(branch), timeout=UPDATE_TIMEOUT, check=False)
    except ActionError as exc:
        if exc.code != "timeout":
            raise
        for line in _update_log_lines():
            log(line)
        raise ActionError("timeout", "The update is still running in the background; check again later.")
    for line in _update_log_lines():
        log(line)
    if proc.stderr.strip():
        log(proc.stderr.strip())
    if proc.returncode != 0:
        raise ActionError("command_failed", f"Update failed with exit code {proc.returncode}")
    log(f"Finished in {int(time.time() - started)} s")
    return {"channel": channel, "version": local_version(), "commit": git("rev-parse", "--short", "HEAD")}


@action("panel.update")
def update(params):
    channel = current_channel()
    return start_job("panel.update", lambda p, log: _run_update(channel, log), params)


@action("panel.set_channel")
def set_channel(params):
    channel = v.choice(params, "channel", list(CHANNELS))
    write_env("WHP_CHANNEL", channel)
    # Keep the installer's saved answer in step, so installer --upgrade (run by
    # every update) keeps this channel too.
    if os.path.exists(paths.INSTALLER_ENV):
        write_env("WHP_CHANNEL", channel, paths.INSTALLER_ENV)

    def work(p, log):
        log(f"Channel set to {channel}")
        return _run_update(channel, log)

    return start_job("panel.set_channel", work, params)
