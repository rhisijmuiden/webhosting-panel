"""backup.* actions end to end against the real whp-backup CLI on a local target.

Skipped when restic, rclone or jq are not installed.
"""

import json
import os
import shutil
import stat
import sys
import tempfile
import time
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths  # noqa: E402
from whp_agent.actions import backup  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

CLI = HERE.parent.parent / "backup" / "bin" / "whp-backup"
HAVE_TOOLS = all(shutil.which(b) for b in ("restic", "rclone", "jq", "flock"))


def wait(job):
    for _ in range(600):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.1)
    raise AssertionError("job did not finish")


@unittest.skipUnless(HAVE_TOOLS, "restic, rclone and jq are needed")
class BackupActionsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.root = root
        (root / "etc/backup").mkdir(parents=True)
        (root / "www/klant.nl").mkdir(parents=True)
        (root / "www/klant.nl/index.html").write_text("v1\n")
        (root / "etc/backup/backup.conf").write_text(
            f'SITES_ROOT="{root}/www"\nMAIL_ROOT="{root}/vmail"\nSYSTEM_PATHS="{root}/www"\nDATABASES="no"\n')
        # The agent runs commands with a clean environment, so point a wrapper at the temp folders.
        wrapper = root / "whp-backup"
        wrapper.write_text(
            "#!/bin/sh\n"
            f"export WHP_ETC={root}/etc WHP_STATE_DIR={root}/state WHP_LOG_DIR={root}/log WHP_BACKUP_LOCK={root}/lock\n"
            f'exec {CLI} "$@"\n')
        wrapper.chmod(0o755)
        self._saved = (backup.WHP_BACKUP, backup.SECRETS_TMP, paths.JOBS_DIR, backup.SYSTEMD_DIR)
        backup.WHP_BACKUP = str(wrapper)
        backup.SECRETS_TMP = str(root / "secrets")
        os.makedirs(backup.SECRETS_TMP)
        paths.JOBS_DIR = str(root / "jobs")
        backup.SYSTEMD_DIR = root / "systemd"

    def tearDown(self):
        backup.WHP_BACKUP, backup.SECRETS_TMP, paths.JOBS_DIR, backup.SYSTEMD_DIR = self._saved
        self.tmp.cleanup()

    def test_target_backup_list_restore(self):
        out = backup.backup_target_add({"name": "nas", "type": "local", "path": str(self.root / "remote")})
        self.assertTrue(out["initialized"])
        self.assertEqual(os.listdir(backup.SECRETS_TMP), [])
        self.assertEqual([t["name"] for t in backup.backup_targets({})["targets"]], ["nas"])
        self.assertTrue(backup.backup_target_test({"target": "nas"})["repository"])

        state = wait(backup.backup_run({"target": "nas", "full": True, "scopes": ["site:klant.nl"]}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertEqual(state["result"]["status"], "ok")

        snaps = backup.backup_list({"target": "nas", "scope": "site:klant.nl"})["snapshots"]
        self.assertEqual(len(snaps), 1)
        self.assertEqual(snaps[0]["kind"], "full")

        (self.root / "www/klant.nl/index.html").write_text("broken\n")
        state = wait(backup.backup_restore({"target": "nas", "snapshot": snaps[0]["short_id"], "mode": "in_place"}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertEqual((self.root / "www/klant.nl/index.html").read_text(), "v1\n")

        status = backup.backup_status({})
        self.assertEqual(status["last_run"]["status"], "ok")

    def test_repo_key_is_passed_as_file_and_reused(self):
        backup.backup_target_add({"name": "a", "type": "local", "path": str(self.root / "remote")})
        key = backup.backup_target_export_key({"target": "a"})["repository_key"]
        out = backup.backup_target_add({"name": "b", "type": "local", "path": str(self.root / "remote"), "repo_key": key})
        self.assertTrue(out["initialized"])
        with self.assertRaises(ActionError):
            backup.backup_target_add({"name": "c", "type": "local", "path": str(self.root / "remote"),
                                      "repo_key": "wrong-key"})

    def test_errors_are_mapped(self):
        with self.assertRaises(ActionError) as ctx:
            backup.backup_list({"target": "missing"})
        self.assertEqual(ctx.exception.code, "not_found")
        backup.backup_target_add({"name": "x", "type": "local", "path": str(self.root / "r")})
        with self.assertRaises(ActionError) as ctx:
            backup.backup_target_add({"name": "x", "type": "local", "path": str(self.root / "r")})
        self.assertEqual(ctx.exception.code, "conflict")

    def test_validation(self):
        bad = [
            (backup.backup_list, {"target": "../etc"}),
            (backup.backup_run, {"scopes": ["site:../../etc"]}),
            (backup.backup_restore, {"target": "nas", "snapshot": "abc;rm", "mode": "in_place"}),
            (backup.backup_restore, {"target": "nas", "snapshot": "latest", "mode": "copy", "to": "/root/../etc"}),
            (backup.backup_target_add, {"name": "n", "type": "smb", "path": "x"}),
            (backup.backup_schedule, {"incremental_time": "25:00"}),
        ]
        for fn, params in bad:
            with self.assertRaises(ActionError, msg=str(params)) as ctx:
                fn(params)
            self.assertEqual(ctx.exception.code, "invalid_params", params)


class ScheduleTest(unittest.TestCase):
    def test_schedule_writes_dropins(self):
        tmp = tempfile.TemporaryDirectory()
        saved = (backup.SYSTEMD_DIR, backup.run)
        calls = []

        class P:
            stdout = "enabled\n"

        backup.SYSTEMD_DIR = Path(tmp.name)
        backup.run = lambda argv, timeout=30, check=True: calls.append(argv) or P()
        try:
            out = backup.backup_schedule({"incremental_time": "01:15", "full_day": "Sat", "full_time": "04:00"})
            self.assertEqual(out["full"], {"enabled": True, "day": "Sat", "time": "04:00"})
            conf = (Path(tmp.name) / "whp-backup-full.timer.d/schedule.conf").read_text()
            self.assertIn("OnCalendar=Sat *-*-* 04:00:00", conf)
            self.assertIn(["systemctl", "daemon-reload"], calls)
            got = backup.backup_get_schedule({})
            self.assertEqual(got["incremental"]["time"], "01:15")
            self.assertEqual(got["full"]["day"], "Sat")
        finally:
            backup.SYSTEMD_DIR, backup.run = saved
            tmp.cleanup()


if __name__ == "__main__":
    unittest.main()
