import json
import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from whp_agent.actions import load_all  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402

load_all()


class SiteLimitsTest(unittest.TestCase):
    def run_site(self, fn, params):
        from whp_agent.actions import sites
        with mock.patch.object(sites, "run") as run:
            run.return_value = mock.Mock(stdout='{"domain": "a.nl"}')
            fn(params)
            return run.call_args[0][0]

    def test_registered(self):
        for name in ["site.owner", "customer.limits", "site.php_settings", "db.limits", "traffic.report",
                     "php.extensions.list", "php.extensions.install", "php.extensions.toggle"]:
            self.assertIn(name, ACTIONS)

    def test_add_with_owner(self):
        from whp_agent.actions import sites
        argv = self.run_site(sites.site_add, {"domain": "a.nl", "customer_id": 12, "php_workers": 8})
        self.assertIn("--owner", argv)
        self.assertEqual(argv[argv.index("--owner") + 1], "c12")
        self.assertEqual(argv[argv.index("--workers") + 1], "8")

    def test_owner_and_reset(self):
        from whp_agent.actions import sites
        self.assertEqual(self.run_site(sites.site_owner, {"domain": "a.nl", "customer_id": 3})[1:4], ["owner", "a.nl", "c3"])
        self.assertEqual(self.run_site(sites.site_owner, {"domain": "a.nl", "customer_id": None})[1:4], ["owner", "a.nl", "-"])

    def test_customer_limits(self):
        from whp_agent.actions import sites
        argv = self.run_site(sites.customer_limits, {"customer_id": 7, "cpu_percent": 200, "memory_mb": 2048})
        self.assertEqual(argv[1:3], ["slice", "c7"])
        self.assertEqual(argv[argv.index("--cpu") + 1], "200%")
        self.assertEqual(argv[argv.index("--memory") + 1], "2048")
        self.assertEqual(argv[argv.index("--io") + 1], "")
        with self.assertRaises(ActionError):
            sites.customer_limits({"customer_id": "7; reboot"})

    def test_php_settings(self):
        from whp_agent.actions import sites
        argv = self.run_site(sites.site_php_settings, {"domain": "a.nl", "settings": {
            "memory_limit": "512M", "max_execution_time": 60, "display_errors": False}, "php_workers": 12})
        self.assertIn("--reset", argv)
        self.assertIn("memory_limit=512M", argv)
        self.assertIn("max_execution_time=60", argv)
        self.assertIn("display_errors=off", argv)
        for bad in ({"memory_limit": "1G; rm"}, {"disable_functions": ""}, {"upload_max_filesize": "-1"}):
            with self.assertRaises(ActionError):
                sites.site_php_settings({"domain": "a.nl", "settings": bad})


class DbLimitsTest(unittest.TestCase):
    def test_alter_users_of_site(self):
        from whp_agent import paths
        from whp_agent.actions import databases
        with tempfile.TemporaryDirectory() as tmp, mock.patch.object(paths, "STATE_DIR", tmp), \
                mock.patch.object(databases, "sql") as sql:
            with open(os.path.join(tmp, "databases.json"), "w") as fh:
                json.dump({"a_nl_wp_1": {"domain": "a.nl", "user": "a_nl_wp_1"},
                           "b_nl_db_2": {"domain": "b.nl", "user": "b_nl_db_2"}}, fh)
            out = databases.db_limits({"domain": "a.nl", "max_connections": 20})
            self.assertEqual(out["users"], ["a_nl_wp_1"])
            self.assertIn("ALTER USER 'a_nl_wp_1'@'localhost' WITH MAX_USER_CONNECTIONS 20", sql.call_args[0][0])
            self.assertNotIn("b_nl", sql.call_args[0][0])
            databases.db_limits({"domain": "a.nl", "max_connections": None})
            self.assertIn("MAX_USER_CONNECTIONS 0", sql.call_args[0][0])


class TrafficTest(unittest.TestCase):
    def test_counts_and_follows_rotation(self):
        from whp_agent import paths
        from whp_agent.actions import traffic
        with tempfile.TemporaryDirectory() as tmp, mock.patch.object(paths, "STATE_DIR", tmp):
            log = os.path.join(tmp, "whp-traffic.log")
            with open(log, "w") as fh:
                fh.write("a.nl 1000 200\nb.nl 50 50\ngarbage\na.nl 10 0\nb.nl 5")  # last line incomplete
            state = traffic.collect(log, now=1_793_000_000)
            month = sorted(state["months"])[-1]
            self.assertEqual(state["months"][month], {"a.nl": 1210, "b.nl": 100})
            with open(log, "a") as fh:
                fh.write(" 5\n")
            # Rotate: the rest of the old file is read from .1, then the new file from 0.
            os.rename(log, log + ".1")
            with open(log, "w") as fh:
                fh.write("a.nl 1 1\n")
            state = traffic.collect(log, now=1_793_000_000)
            self.assertEqual(state["months"][month], {"a.nl": 1212, "b.nl": 110})
            state = traffic.collect(log, now=1_793_000_000)
            self.assertEqual(state["months"][month]["a.nl"], 1212)


class StatsNestedTest(unittest.TestCase):
    def test_customer_slices(self):
        from whp_agent.actions import stats
        with tempfile.TemporaryDirectory() as tmp:
            site = os.path.join(tmp, "whp-sites-c4.slice", "whp-site-shop_nl.service")
            os.makedirs(site)
            with open(os.path.join(site, "memory.current"), "w") as fh:
                fh.write("1024\n")
            with mock.patch.object(stats, "CGROUP_ROOT", tmp):
                self.assertEqual(stats._slices()["shop_nl"]["memory"], 1024)


class WordPressSliceTest(unittest.TestCase):
    def test_slice_of_owner(self):
        from whp_agent import wordpress
        self.assertEqual(wordpress.site_slice("c12"), "whp-sites-c12.slice")
        self.assertEqual(wordpress.site_slice(""), "whp-sites.slice")
        self.assertEqual(wordpress.site_slice("../x"), "whp-sites.slice")


if __name__ == "__main__":
    unittest.main()
