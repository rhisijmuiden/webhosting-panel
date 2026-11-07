"""Saves one run of a cron job. Called as root by systemd after every run:

    ExecStopPost=+/usr/bin/python3 -I .../cron_finish.py <job id> <state dir>

systemd passes SERVICE_RESULT, EXIT_CODE, EXIT_STATUS and INVOCATION_ID in the
environment. The output of the run comes from the journal (stdout and stderr of
the job), the start and end from systemctl. One JSON line per run is appended to
<state dir>/runs/<id>.jsonl, which keeps the last KEEP runs.

Standalone on purpose (python3 -I, standard library only): it runs outside the
agent, also when the agent is restarting.
"""

import json
import os
import re
import subprocess
import sys
import time

KEEP = 50
MAX_OUTPUT = 64 * 1024


def _show(unit):
    try:
        out = subprocess.run(
            ["systemctl", "show", unit, "--timestamp=unix", "-p",
             "ExecMainStartTimestamp,ExecMainStartTimestampMonotonic,ExecMainExitTimestampMonotonic,ExecMainPID"],
            capture_output=True, text=True, timeout=15).stdout
    except (OSError, subprocess.SubprocessError):
        return {}
    return dict(line.split("=", 1) for line in out.splitlines() if "=" in line)


def _output(invocation):
    if not invocation or not re.fullmatch(r"[a-f0-9]{32}", invocation):
        return ""
    for attempt in range(3):
        try:
            out = subprocess.run(["journalctl", f"_SYSTEMD_INVOCATION_ID={invocation}", "-o", "cat", "--no-pager", "-q"],
                                 capture_output=True, timeout=30).stdout
        except (OSError, subprocess.SubprocessError):
            return ""
        if out or attempt == 2:
            return out.decode("utf-8", "replace")
        time.sleep(0.3)  # journald may still be reading the last lines
    return ""


def status_of(result, exit_code, stopped):
    if stopped:
        return "stopped"
    if result == "success":
        return "success"
    if result == "timeout":
        return "timeout"
    if result in ("oom-kill",):
        return "oom"
    return "failed"


def record(job_id, env, show, output, now, stopped=False):
    start = show.get("ExecMainStartTimestamp", "")
    started = int(start[1:]) if start.startswith("@") and start[1:].isdigit() else None
    mono_start = show.get("ExecMainStartTimestampMonotonic", "0")
    mono_end = show.get("ExecMainExitTimestampMonotonic", "0")
    duration = None
    if mono_start.isdigit() and mono_end.isdigit() and int(mono_end) >= int(mono_start) > 0:
        duration = (int(mono_end) - int(mono_start)) // 1000
    elif started:
        duration = max(0, int((now - started) * 1000))
    exit_status = env.get("EXIT_STATUS", "")
    truncated = len(output) > MAX_OUTPUT
    return {
        "id": job_id,
        "invocation": env.get("INVOCATION_ID") or f"{job_id}-{int(now * 1000)}",
        "started": started if started is not None else int(now - (duration or 0) / 1000),
        "finished": round(now, 3),
        "duration_ms": duration,
        "status": status_of(env.get("SERVICE_RESULT", ""), env.get("EXIT_CODE", ""), stopped),
        "result": env.get("SERVICE_RESULT", ""),
        "exit_code": env.get("EXIT_CODE", ""),
        "exit_status": int(exit_status) if exit_status.lstrip("-").isdigit() else None,
        "signal": exit_status if exit_status and not exit_status.lstrip("-").isdigit() else None,
        "output": output[-MAX_OUTPUT:],
        "truncated": truncated,
    }


def append(runs_dir, job_id, rec, keep=KEEP):
    os.makedirs(runs_dir, mode=0o750, exist_ok=True)
    path = os.path.join(runs_dir, f"{job_id}.jsonl")
    try:
        with open(path) as fh:
            lines = [ln for ln in fh.read().splitlines() if ln.strip()]
    except OSError:
        lines = []
    lines = (lines + [json.dumps(rec, separators=(",", ":"))])[-keep:]
    tmp = f"{path}.new"
    with open(tmp, "w") as fh:
        fh.write("\n".join(lines) + "\n")
    os.chmod(tmp, 0o640)
    os.replace(tmp, path)


def main(argv):
    if len(argv) != 3 or not re.fullmatch(r"[a-f0-9]{10}", argv[1]) or not os.path.isabs(argv[2]):
        print("usage: cron_finish.py <job id> <state dir>", file=sys.stderr)
        return 2
    job_id, state = argv[1], argv[2]
    runs_dir = os.path.join(state, "runs")
    marker = os.path.join(runs_dir, f"{job_id}.stopped")
    stopped = os.path.exists(marker)
    if stopped:
        os.unlink(marker)
    env = dict(os.environ)
    rec = record(job_id, env, _show(f"whp-cron-{job_id}.service"), _output(env.get("INVOCATION_ID", "")), time.time(), stopped)
    append(runs_dir, job_id, rec)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
