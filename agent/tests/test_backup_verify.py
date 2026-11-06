"""backup.verify end to end against the real whp-backup CLI on a local target.

Skipped when restic, rclone or jq are not installed.
"""

import shutil
import sys
import tempfile
import time
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths  # noqa: E402
from whp_agent.actions import backup, backup_verify  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

CLI = HERE.parent.parent / "backup" / "bin" / "whp-backup"
HAVE_TOOLS = all(shutil.which(b) for b in ("restic", "rclone", "jq", "flock"))


def wait(job):
    for _ in range(900):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.1)
    raise AssertionError("job did not finish")


class ArgvTest(unittest.TestCase):
    def test_options_are_validated_and_mapped(self):
        argv = backup_verify._argv({"target": "nas", "sites": 3, "mail": 0, "databases": 2,
                                    "system": False, "kinds": "latest", "max_age_hours": 48,
                                    "scopes": ["site:klant.nl"]})
        self.assertEqual(argv, ["verify", "--target", "nas", "--scope", "site:klant.nl", "--sites", "3",
                                "--mail", "0", "--databases", "2", "--no-system", "--kinds", "latest",
                                "--max-age-hours", "48"])

    def test_bad_values_are_refused(self):
        for bad in ({"target": "../x"}, {"sites": -1}, {"kinds": "all"}, {"scopes": ["site:../etc"]},
                    {"max_age_hours": "x"}):
            with self.assertRaises(ActionError, msg=bad):
                backup_verify._argv(bad)

    def test_verify_last_without_report(self):
        saved = backup_verify.STATE_FILE
        backup_verify.STATE_FILE = Path("/nonexistent/verify-last.json")
        try:
            self.assertEqual(backup_verify.backup_verify_last({}), {"report": None})
        finally:
            backup_verify.STATE_FILE = saved


@unittest.skipUnless(HAVE_TOOLS, "restic, rclone and jq are needed")
class VerifyJobTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        (root / "etc/backup").mkdir(parents=True)
        (root / "www/klant.nl").mkdir(parents=True)
        (root / "www/klant.nl/index.php").write_text("<?php echo 'hi';\n")
        (root / "etc/backup/backup.conf").write_text(
            f'SITES_ROOT="{root}/www"\nMAIL_ROOT="{root}/vmail"\nSYSTEM_PATHS="{root}/www"\nDATABASES="no"\n'
            f'VERIFY_DIR="{root}/verify"\nVERIFY_MIN_FREE_PERCENT="0"\n')
        wrapper = root / "whp-backup"
        wrapper.write_text(
            "#!/bin/sh\n"
            f"export WHP_ETC={root}/etc WHP_STATE_DIR={root}/state WHP_LOG_DIR={root}/log WHP_BACKUP_LOCK={root}/lock\n"
            f'exec {CLI} "$@"\n')
        wrapper.chmod(0o755)
        self._saved = (backup.WHP_BACKUP, paths.JOBS_DIR, backup_verify.STATE_FILE)
        backup.WHP_BACKUP = str(wrapper)
        paths.JOBS_DIR = str(root / "jobs")
        backup_verify.STATE_FILE = root / "state/backup/verify-last.json"
        backup._backup(["target", "add", "--name", "local1", "--type", "local", "--path", str(root / "remote")])
        backup._backup(["run", "--full", "--target", "local1"])

    def tearDown(self):
        backup.WHP_BACKUP, paths.JOBS_DIR, backup_verify.STATE_FILE = self._saved
        self.tmp.cleanup()

    def test_verify_job_reports_and_cleans_up(self):
        state = wait(backup_verify.backup_verify({"target": "local1", "databases": 0}))
        self.assertEqual(state["status"], "succeeded", state)
        report = state["result"]
        self.assertEqual(report["status"], "ok")
        scopes = [i["scope"] for i in report["targets"][0]["items"]]
        self.assertIn("site:klant.nl", scopes)
        self.assertTrue(all(i["status"] == "ok" for i in report["targets"][0]["items"]))
        self.assertEqual(backup_verify.backup_verify_last({})["report"]["status"], "ok")
        leftovers = list((Path(self.tmp.name) / "verify").glob("run-*"))
        self.assertEqual(leftovers, [])


if __name__ == "__main__":
    unittest.main()
