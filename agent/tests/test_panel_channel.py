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


if __name__ == "__main__":
    unittest.main()
