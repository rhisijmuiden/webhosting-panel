"""Automatic restore tests of the server backups (whp-backup verify).

The panel starts `backup.verify` on its schedule (or from the Restore tests
screen). whp-backup restores a sample of the newest restore points of every
location into an isolated scratch folder and a private MariaDB instance,
checks them and removes everything again; the job result is its JSON report.
`backup.verify_last` returns the report of the last run, also when the panel
missed the job (for example because the agent restarted meanwhile).
"""

import json
from pathlib import Path

from .. import validate as v
from ..jobs import start_job
from ..registry import action
from . import backup

STATE_FILE = Path("/var/lib/webhosting-panel/backup/verify-last.json")


def _argv(params):
    argv = ["verify"]
    target = backup._target(params, required=False)
    if target:
        argv += ["--target", target]
    for scope in backup._scopes(params):
        argv += ["--scope", scope]
    for key in ("sites", "mail", "databases"):
        n = v.integer(params, key, required=False, minimum=0, maximum=500)
        if n is not None:
            argv += [f"--{key}", str(n)]
    system = v.boolean(params, "system", required=False)
    if system is not None:
        argv.append("--system" if system else "--no-system")
    kinds = v.choice(params, "kinds", ["latest", "both"], required=False)
    if kinds:
        argv += ["--kinds", kinds]
    max_age = v.integer(params, "max_age_hours", required=False, minimum=0, maximum=10000)
    if max_age is not None:
        argv += ["--max-age-hours", str(max_age)]
    return argv


@action("backup.verify")
def backup_verify(params):
    argv = _argv(params)

    def work(_params, log):
        log("whp-backup " + " ".join(argv))
        report = backup._backup(argv, timeout=24 * 3600)
        for t in (report or {}).get("targets", []):
            log(f"{t.get('target')}: {t.get('status')} ({len(t.get('items') or [])} restore points tested)")
        return report

    return start_job("backup.verify", work, params)


@action("backup.verify_last")
def backup_verify_last(params):
    try:
        return {"report": json.loads(STATE_FILE.read_text())}
    except (OSError, ValueError):
        return {"report": None}
