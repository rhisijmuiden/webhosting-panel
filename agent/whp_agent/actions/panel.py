"""Panel version, update check, update and release channel."""

import os

from .. import paths
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

CHANNELS = {"production": "main", "test": "test"}
UPDATE_SCRIPT = os.path.join(paths.ROOT_DIR, "agent", "scripts", "update.sh")


def git(*args, timeout=60, check=True):
    return run(["git", "-c", "safe.directory=" + paths.ROOT_DIR, "-C", paths.ROOT_DIR, *args],
               timeout=timeout, check=check).stdout.strip()


def read_env(key, default=None):
    try:
        with open(paths.PANEL_ENV) as fh:
            for line in fh:
                if line.startswith(key + "="):
                    return line.split("=", 1)[1].strip().strip('"')
    except FileNotFoundError:
        pass
    return default


def write_env(key, value):
    lines = []
    found = False
    try:
        with open(paths.PANEL_ENV) as fh:
            lines = fh.read().splitlines()
    except FileNotFoundError:
        pass
    for i, line in enumerate(lines):
        if line.startswith(key + "="):
            lines[i] = f"{key}={value}"
            found = True
    if not found:
        lines.append(f"{key}={value}")
    tmp = paths.PANEL_ENV + ".tmp"
    st = os.stat(paths.PANEL_ENV) if os.path.exists(paths.PANEL_ENV) else None
    with open(tmp, "w") as fh:
        fh.write("\n".join(lines) + "\n")
    if st:
        os.chown(tmp, st.st_uid, st.st_gid)
        os.chmod(tmp, st.st_mode & 0o777)
    os.replace(tmp, paths.PANEL_ENV)


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


def _run_update(channel, log):
    branch = CHANNELS[channel]
    log(f"Updating to channel {channel} (branch {branch})")
    proc = run(["/bin/bash", UPDATE_SCRIPT, branch], timeout=1800, check=False,
               env={"WHP_ROOT": paths.ROOT_DIR, "WHP_CONFIG_DIR": paths.CONFIG_DIR})
    for line in (proc.stdout + proc.stderr).splitlines():
        log(line)
    if proc.returncode != 0:
        raise ActionError("command_failed", f"Update failed with exit code {proc.returncode}")
    return {"channel": channel, "version": local_version(), "commit": git("rev-parse", "--short", "HEAD")}


@action("panel.update")
def update(params):
    channel = current_channel()
    return start_job("panel.update", lambda p, log: _run_update(channel, log), params)


@action("panel.set_channel")
def set_channel(params):
    channel = v.choice(params, "channel", list(CHANNELS))
    write_env("WHP_CHANNEL", channel)

    def work(p, log):
        log(f"Channel set to {channel}")
        return _run_update(channel, log)

    return start_job("panel.set_channel", work, params)
