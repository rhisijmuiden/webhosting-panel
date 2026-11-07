"""Customer cron jobs: cron expression -> OnCalendar, units, runs and the
finish hook. systemctl and journalctl are recorded, not run."""

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import cron_finish, wordpress  # noqa: E402
from whp_agent.actions import cron as c  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

JOB = {"id": "0a1b2c3d4e", "domain": "klant.nl", "name": "Laravel scheduler", "type": "command",
       "command": "cd app && php artisan schedule:run", "schedule": "* * * * *", "timeout": 15}


class ScheduleTest(unittest.TestCase):
    def cal(self, expr, tz=None):
        return c.to_calendar(expr, tz)

    def test_common_expressions(self):
        self.assertEqual(self.cal("* * * * *"), ["*-*-* *:*:00"])
        self.assertEqual(self.cal("*/15 * * * *"), ["*-*-* *:00,15,30,45:00"])
        self.assertEqual(self.cal("30 3 * * *"), ["*-*-* 03:30:00"])
        self.assertEqual(self.cal("0 */6 * * *"), ["*-*-* 00,06,12,18:00:00"])
        self.assertEqual(self.cal("0 9 * * 1-5"), ["Mon,Tue,Wed,Thu,Fri *-*-* 09:00:00"])
        self.assertEqual(self.cal("0 0 1 * *"), ["*-*-01 00:00:00"])
        self.assertEqual(self.cal("15 2 * jan,jul sun"), ["Sun *-01,07-* 02:15:00"])

    def test_macros_names_and_sunday_as_seven(self):
        self.assertEqual(self.cal("@daily"), self.cal("0 0 * * *"))
        self.assertEqual(self.cal("@hourly"), ["*-*-* *:00:00"])
        self.assertEqual(self.cal("0 8 * * 7"), self.cal("0 8 * * 0"))
        self.assertEqual(self.cal("0 8 * * MON-FRI"), self.cal("0 8 * * 1-5"))
        self.assertEqual(self.cal("5/20 * * * *"), ["*-*-* *:05,25,45:00"])
        self.assertEqual(self.cal("0-30/10 * * * *"), ["*-*-* *:00,10,20,30:00"])

    def test_day_of_month_or_day_of_week(self):
        # cron runs when EITHER matches: two OnCalendar lines.
        self.assertEqual(self.cal("0 12 1,15 * fri"), ["*-*-01,15 12:00:00", "Fri *-*-* 12:00:00"])

    def test_timezone_suffix(self):
        self.assertEqual(self.cal("0 3 * * *", "Europe/Amsterdam"), ["*-*-* 03:00:00 Europe/Amsterdam"])

    def test_invalid(self):
        for bad in ("* * * *", "60 * * * *", "* 24 * * *", "* * 0 * *", "* * * 13 *", "*/0 * * * *",
                    "5-1 * * * *", "a * * * *", "1,,2 * * * *", "@reboot", "* * * * * *"):
            with self.assertRaises(ActionError, msg=bad):
                self.cal(bad)


class CronActionsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.root = root
        self.site_root = root / "vhosts/klant.nl"
        self.site_root.mkdir(parents=True)
        (root / "zoneinfo/Europe").mkdir(parents=True)
        (root / "zoneinfo/Europe/Amsterdam").write_text("")
        self.site = {"domain": "klant.nl", "id": "klant_nl", "user": "s_klant_nl", "php": "8.4", "root": str(self.site_root),
                     "docroot": str(self.site_root / "public"), "slice": "whp-sites-s7.slice", "suspended": False}
        self.calls = []
        self.show = ""

        def run(argv, **kw):
            self.calls.append(list(argv))
            out = self.show if argv[:2] == ["systemctl", "show"] else ""
            return SimpleNamespace(stdout=out, stderr="", returncode=0)

        self.patches = [
            mock.patch.object(c, "run", run),
            mock.patch.object(c, "STATE_DIR", str(root / "state")),
            mock.patch.object(c, "RUNS_DIR", str(root / "state/runs")),
            mock.patch.object(c, "SYSTEMD_DIR", str(root / "systemd")),
            mock.patch.object(c, "PHP_DIR", str(root / "php")),
            mock.patch.object(c, "ZONEINFO", str(root / "zoneinfo")),
            mock.patch.object(wordpress, "site_info", lambda d: dict(self.site) if d == "klant.nl" else (_ for _ in ()).throw(ActionError("not_found", "x"))),
        ]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    def unit(self, ext="service"):
        return (self.root / f"systemd/whp-cron-0a1b2c3d4e.{ext}").read_text()

    def test_apply_writes_units_as_site_user_in_slice(self):
        out = c.cron_apply({**JOB, "timezone": "Europe/Amsterdam"})
        self.assertEqual(out["calendar"], ["*-*-* *:*:00 Europe/Amsterdam"])
        svc = self.unit()
        for line in ("Type=oneshot", "User=s_klant_nl", "Group=s_klant_nl", "Slice=whp-sites-s7.slice",
                     f"WorkingDirectory={self.site_root}", "TimeoutStartSec=15min", "NoNewPrivileges=yes",
                     "ProtectHome=yes", 'ExecStart=/bin/bash --noprofile --norc -c "cd app && php artisan schedule:run"',
                     "SyslogIdentifier=whp-cron-0a1b2c3d4e"):
            self.assertIn(line, svc)
        self.assertIn(f"PATH={self.root}/php/8.4:/usr/local/bin", svc)
        self.assertIn("ExecStopPost=+/usr/bin/python3 -I ", svc)
        self.assertIn(f"cron_finish.py 0a1b2c3d4e {self.root}/state\n", svc)
        self.assertEqual(os.readlink(self.root / "php/8.4/php"), "/usr/bin/php8.4")
        self.assertIn("OnCalendar=*-*-* *:*:00 Europe/Amsterdam", self.unit("timer"))
        self.assertIn(["systemctl", "enable", "whp-cron-0a1b2c3d4e.timer"], self.calls)
        st = json.loads((self.root / "state/0a1b2c3d4e.json").read_text())
        self.assertEqual(st["domain"], "klant.nl")

    def test_disabled_or_suspended_job_has_no_active_timer(self):
        c.cron_apply({**JOB, "enabled": False})
        self.assertIn(["systemctl", "disable", "--now", "whp-cron-0a1b2c3d4e.timer"], self.calls)
        self.calls.clear()
        self.site["suspended"] = True
        c.cron_apply(JOB)
        self.assertIn(["systemctl", "disable", "--now", "whp-cron-0a1b2c3d4e.timer"], self.calls)

    def test_quoting_dollar_percent_and_quotes(self):
        c.cron_apply({**JOB, "command": 'echo "$HOME" 50% done > logs/x.log'})
        self.assertIn('-c "echo \\"$$HOME\\" 50%% done > logs/x.log"', self.unit())

    def test_php_and_url_types(self):
        c.cron_apply({**JOB, "type": "php", "command": "/public/cron.php", "args": "--quiet"})
        self.assertIn('-c "exec /usr/bin/php8.4 public/cron.php --quiet"', self.unit())
        c.cron_apply({**JOB, "type": "url", "command": "https://klant.nl/cron?key=a&b=1", "timeout": 5})
        svc = self.unit()
        self.assertIn("/usr/bin/curl -sS -f -L --proto =http,https", svc)
        self.assertIn("--max-time 295", svc)
        self.assertIn("'https://klant.nl/cron?key=a&b=1'", svc)
        self.assertIn("%%{http_code}", svc)

    def test_rejects_bad_input(self):
        for bad in ({"type": "php", "command": "../etc/passwd"}, {"type": "url", "command": "ftp://x"},
                    {"schedule": "61 * * * *"}, {"timeout": 7}, {"id": "../x"}, {"command": "  "},
                    {"timezone": "../../etc/passwd"}, {"timezone": "Mars/Base"}):
            with self.assertRaises(ActionError, msg=str(bad)):
                c.cron_apply({**JOB, **bad})

    def test_other_website_may_not_take_over_job(self):
        c.cron_apply(JOB)
        self.site["domain"] = "ander.nl"
        with mock.patch.object(wordpress, "site_info", lambda d: dict(self.site, domain=d)):
            with self.assertRaises(ActionError):
                c.cron_apply({**JOB, "domain": "ander.nl"})

    def test_status_parses_service_and_timer(self):
        c.cron_apply(JOB)
        self.show = ("Id=whp-cron-0a1b2c3d4e.service\nActiveState=activating\nSubState=start\nExecMainStartTimestamp=@1793957400\n\n"
                     "Id=whp-cron-0a1b2c3d4e.timer\nActiveState=active\nNextElapseUSecRealtime=@1793957460\nLastTriggerUSec=@1793957400\n")
        st = c.cron_list({"ids": ["0a1b2c3d4e"]})["jobs"]["0a1b2c3d4e"]
        self.assertTrue(st["running"])
        self.assertEqual(st["running_since"], 1793957400)
        self.assertEqual(st["next_run"], 1793957460)
        self.assertTrue(st["scheduled"])

    def test_run_now_and_already_running(self):
        c.cron_apply(JOB)
        out = c.cron_run({"id": "0a1b2c3d4e"})
        self.assertTrue(out["started"])
        self.assertIn(["systemctl", "start", "--no-block", "whp-cron-0a1b2c3d4e.service"], self.calls)
        self.show = "Id=whp-cron-0a1b2c3d4e.service\nActiveState=activating\n"
        self.assertFalse(c.cron_run({"id": "0a1b2c3d4e"})["started"])
        with self.assertRaises(ActionError):
            c.cron_run({"id": "ffffffffff"})

    def test_runs_since_and_remove(self):
        c.cron_apply(JOB)
        for i, t in enumerate((100.0, 200.0, 300.0)):
            cron_finish.append(c.RUNS_DIR, "0a1b2c3d4e", {"id": "0a1b2c3d4e", "invocation": f"inv{i}", "finished": t, "status": "success"})
        runs = c.cron_runs({"since": 150})["runs"]
        self.assertEqual([r["invocation"] for r in runs], ["inv1", "inv2"])
        c.cron_remove({"id": "0a1b2c3d4e"})
        self.assertFalse((self.root / "systemd/whp-cron-0a1b2c3d4e.service").exists())
        self.assertFalse((self.root / "state/0a1b2c3d4e.json").exists())
        self.assertEqual(c.cron_runs({"since": 0})["runs"], [])

    def test_suspend_and_refresh_after_php_change(self):
        c.cron_apply(JOB)
        self.calls.clear()
        c.cron_suspend({"domain": "klant.nl", "suspended": True})
        self.assertIn(["systemctl", "disable", "--now", "whp-cron-0a1b2c3d4e.timer"], self.calls)
        self.site["php"] = "8.3"
        self.site["slice"] = "whp-sites-s9.slice"
        self.assertEqual(c.refresh_units("klant.nl"), ["0a1b2c3d4e"])
        svc = self.unit()
        self.assertIn("Slice=whp-sites-s9.slice", svc)
        self.assertIn(f"{self.root}/php/8.3:", svc)


class FinishTest(unittest.TestCase):
    def test_record_success_failure_timeout(self):
        show = {"ExecMainStartTimestamp": "@1000", "ExecMainStartTimestampMonotonic": "5000000",
                "ExecMainExitTimestampMonotonic": "7500000"}
        ok = cron_finish.record("0a1b2c3d4e", {"SERVICE_RESULT": "success", "EXIT_CODE": "exited", "EXIT_STATUS": "0",
                                              "INVOCATION_ID": "a" * 32}, show, "done\n", 1003.0)
        self.assertEqual((ok["status"], ok["duration_ms"], ok["exit_status"], ok["started"]), ("success", 2500, 0, 1000))
        bad = cron_finish.record("0a1b2c3d4e", {"SERVICE_RESULT": "exit-code", "EXIT_CODE": "exited", "EXIT_STATUS": "3"}, show, "x", 1003.0)
        self.assertEqual((bad["status"], bad["exit_status"]), ("failed", 3))
        late = cron_finish.record("0a1b2c3d4e", {"SERVICE_RESULT": "timeout", "EXIT_CODE": "killed", "EXIT_STATUS": "TERM"}, show, "", 1003.0)
        self.assertEqual((late["status"], late["signal"]), ("timeout", "TERM"))
        stop = cron_finish.record("0a1b2c3d4e", {"SERVICE_RESULT": "signal"}, show, "", 1003.0, stopped=True)
        self.assertEqual(stop["status"], "stopped")

    def test_output_is_capped_and_history_trimmed(self):
        rec = cron_finish.record("0a1b2c3d4e", {"SERVICE_RESULT": "success"}, {}, "x" * (cron_finish.MAX_OUTPUT + 10), 50.0)
        self.assertTrue(rec["truncated"])
        self.assertEqual(len(rec["output"]), cron_finish.MAX_OUTPUT)
        with tempfile.TemporaryDirectory() as d:
            for i in range(5):
                cron_finish.append(d, "0a1b2c3d4e", {"n": i}, keep=3)
            lines = (Path(d) / "0a1b2c3d4e.jsonl").read_text().splitlines()
            self.assertEqual([json.loads(x)["n"] for x in lines], [2, 3, 4])

    def test_main_validates_arguments(self):
        self.assertEqual(cron_finish.main(["x", "../bad", "/tmp"]), 2)
        self.assertEqual(cron_finish.main(["x", "0a1b2c3d4e", "relative"]), 2)


if __name__ == "__main__":
    unittest.main()
