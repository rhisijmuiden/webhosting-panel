import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from whp_agent.actions import load_all  # noqa: E402

load_all()
from whp_agent.actions import panel  # noqa: E402


class PanelChannelTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.panel_env = os.path.join(self.tmp.name, "panel.env")
        self.installer_env = os.path.join(self.tmp.name, "installer.env")
        with open(self.panel_env, "w") as fh:
            fh.write('APP_NAME="Webhosting Panel"\nWHP_CHANNEL=production\n')
        with open(self.installer_env, "w") as fh:
            fh.write("WHP_HOSTNAME=web01.example.test\nWHP_CHANNEL=production\n")
        for name, value in (("PANEL_ENV", self.panel_env), ("INSTALLER_ENV", self.installer_env)):
            patcher = mock.patch.object(panel.paths, name, value)
            patcher.start()
            self.addCleanup(patcher.stop)

    def test_set_channel_writes_panel_and_installer_env(self):
        with mock.patch.object(panel, "start_job", return_value={"job": "x"}) as job:
            panel.set_channel({"channel": "test"})
        job.assert_called_once()
        self.assertEqual(panel.current_channel(), "test")
        self.assertEqual(panel.read_env("WHP_CHANNEL", path=self.installer_env), "test")
        with open(self.installer_env) as fh:
            self.assertIn("WHP_HOSTNAME=web01.example.test", fh.read())

    def test_set_channel_without_installer_env(self):
        os.remove(self.installer_env)
        with mock.patch.object(panel, "start_job", return_value={"job": "x"}):
            panel.set_channel({"channel": "test"})
        self.assertEqual(panel.current_channel(), "test")
        self.assertFalse(os.path.exists(self.installer_env))



class PanelUpdateTest(unittest.TestCase):
    """The updater runs in its own unit, so an agent restart or timeout never strands
    the panel in maintenance mode (HTTP 503)."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.log_path = os.path.join(self.tmp.name, "panel-update.log")
        patcher = mock.patch.object(panel, "UPDATE_LOG", self.log_path)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.lines = []

    def test_update_runs_in_own_systemd_unit(self):
        argv = panel.update_argv("test")
        self.assertEqual(argv[0], "systemd-run")
        self.assertIn("--wait", argv)
        self.assertEqual(argv[argv.index("--unit") + 1], panel.UPDATE_UNIT)
        self.assertIn(f"StandardOutput=truncate:{self.log_path}", argv)
        self.assertEqual(argv[-3:], ["/bin/bash", panel.UPDATE_SCRIPT, "test"])

    def test_update_logs_output_and_succeeds(self):
        with open(self.log_path, "w") as fh:
            fh.write("==> Fetching test\n==> Done: 1.0.0\n")
        done = mock.Mock(returncode=0, stdout="", stderr="")
        with mock.patch.object(panel, "update_running", return_value=False), \
                mock.patch.object(panel, "run", return_value=done) as run, \
                mock.patch.object(panel, "git", return_value="abc1234"):
            result = panel._run_update("test", self.lines.append)
        self.assertEqual(run.call_args[0][0][0], "systemd-run")
        self.assertIn("==> Done: 1.0.0", self.lines)
        self.assertEqual(result["commit"], "abc1234")

    def test_update_refuses_while_another_runs(self):
        with mock.patch.object(panel, "update_running", return_value=True), \
                mock.patch.object(panel, "run") as run:
            with self.assertRaises(panel.ActionError) as err:
                panel._run_update("test", self.lines.append)
        self.assertEqual(err.exception.code, "conflict")
        run.assert_not_called()

    def test_failed_update_reports_exit_code(self):
        failed = mock.Mock(returncode=3, stdout="", stderr="")
        with mock.patch.object(panel, "update_running", return_value=False), \
                mock.patch.object(panel, "run", return_value=failed):
            with self.assertRaises(panel.ActionError) as err:
                panel._run_update("production", self.lines.append)
        self.assertEqual(err.exception.code, "command_failed")

    def test_timeout_leaves_update_running(self):
        with mock.patch.object(panel, "update_running", return_value=False), \
                mock.patch.object(panel, "run", side_effect=panel.ActionError("timeout", "slow")):
            with self.assertRaises(panel.ActionError) as err:
                panel._run_update("test", self.lines.append)
        self.assertEqual(err.exception.code, "timeout")
        self.assertIn("background", err.exception.message)


if __name__ == "__main__":
    unittest.main()
