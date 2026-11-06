import json
import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from whp_agent import validate as v  # noqa: E402
from whp_agent.actions import load_all  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402
from whp_agent.server import handle_line  # noqa: E402

load_all()


class ValidateTest(unittest.TestCase):
    def test_domain(self):
        self.assertEqual(v.domain({"d": "Example.NL."}, "d"), "example.nl")
        self.assertEqual(v.domain({"d": "bücher.de"}, "d"), "xn--bcher-kva.de")
        for bad in ["-a.nl", "a..nl", "a.nl;rm -rf", "localhost", "a b.nl", 5]:
            with self.assertRaises(ActionError):
                v.domain({"d": bad}, "d")

    def test_ip(self):
        self.assertEqual(v.ip_or_network({"a": "1.2.3.4"}, "a"), "1.2.3.4/32")
        self.assertEqual(v.ip_or_network({"a": "10.0.0.0/8"}, "a"), "10.0.0.0/8")
        with self.assertRaises(ActionError):
            v.ip_or_network({"a": "1.2.3.4; nft flush"}, "a")

    def test_choice_and_integer(self):
        with self.assertRaises(ActionError):
            v.choice({"x": "9.9"}, "x", ["8.4"])
        with self.assertRaises(ActionError):
            v.integer({"x": True}, "x")


class ProtocolTest(unittest.TestCase):
    def test_ping(self):
        res = handle_line(json.dumps({"id": "1", "action": "ping"}).encode())
        self.assertTrue(res["ok"])
        self.assertEqual(res["id"], "1")

    def test_unknown_action(self):
        res = handle_line(b'{"id":"2","action":"rm"}')
        self.assertFalse(res["ok"])
        self.assertEqual(res["error"]["code"], "unknown_action")

    def test_bad_json(self):
        res = handle_line(b"nope")
        self.assertEqual(res["error"]["code"], "invalid_params")

    def test_registered_actions(self):
        for name in ["system.info", "service.list", "firewall.add", "crowdsec.ban", "panel.update", "site.add"]:
            self.assertIn(name, ACTIONS)


class FirewallTest(unittest.TestCase):
    def test_add_renders_set_elements(self):
        from whp_agent.actions import firewall

        with tempfile.TemporaryDirectory() as tmp:
            with mock.patch.object(firewall, "RULES_FILE", os.path.join(tmp, "fw.json")), \
                 mock.patch.object(firewall, "NFT_FILE", os.path.join(tmp, "whp-panel.nft")), \
                 mock.patch.object(firewall.paths, "CONFIG_DIR", tmp), \
                 mock.patch.object(firewall.paths, "NFT_DIR", tmp), \
                 mock.patch.object(firewall, "run") as run:
                firewall.firewall_add({"type": "open_port", "protocol": "tcp", "port": 3306, "comment": "db"})
                firewall.firewall_add({"type": "block_ip", "address": "2001:db8::/32"})
                with self.assertRaises(ActionError):
                    firewall.firewall_add({"type": "open_port", "protocol": "tcp", "port": 3306})
                content = open(os.path.join(tmp, "whp-panel.nft")).read()
                self.assertIn("add element inet whp tcp_open { 3306 }", content)
                self.assertIn("add element inet whp blocked_v6 { 2001:db8::/32 }", content)
                self.assertTrue(run.called)


class SitesTest(unittest.TestCase):
    def test_site_add_builds_argv(self):
        from whp_agent.actions import sites

        with mock.patch.object(sites, "run") as run:
            run.return_value = mock.Mock(stdout='{"domain": "a.nl", "ssl": "pending"}')
            out = sites.site_add({"domain": "A.nl", "php": "8.5", "profile": "laravel", "cache": False})
            argv = run.call_args[0][0]
            self.assertEqual(argv[:3], [sites.WHP_SITE, "add", "a.nl"])
            self.assertIn("--no-cache", argv)
            self.assertNotIn("--no-ssl", argv)
            self.assertEqual(argv[-1], "--json")
            self.assertEqual(out["ssl"], "pending")

    def test_suspend_and_unsuspend(self):
        from whp_agent.actions import sites

        with mock.patch.object(sites, "run") as run:
            run.return_value = mock.Mock(stdout='{"domain": "a.nl", "suspended": true}')
            sites.site_suspend({"domain": "A.nl"})
            self.assertEqual(run.call_args[0][0], [sites.WHP_SITE, "suspend", "a.nl", "--json"])
            sites.site_unsuspend({"domain": "a.nl"})
            self.assertEqual(run.call_args[0][0], [sites.WHP_SITE, "unsuspend", "a.nl", "--json"])
            with self.assertRaises(ActionError):
                sites.site_suspend({"domain": "a.nl; reboot"})

    def test_docroot_traversal(self):
        from whp_agent.actions import sites

        with self.assertRaises(ActionError):
            sites.site_add({"domain": "a.nl", "docroot": "../../etc"})


class StatsTest(unittest.TestCase):
    def test_sample_reads_slices(self):
        from whp_agent.actions import stats

        with tempfile.TemporaryDirectory() as tmp:
            site = os.path.join(tmp, "whp-site-example_nl.service")
            os.makedirs(site)
            with open(os.path.join(site, "cpu.stat"), "w") as fh:
                fh.write("usage_usec 1000000\nuser_usec 1\n")
            with open(os.path.join(site, "memory.current"), "w") as fh:
                fh.write("52428800\n")
            with open(os.path.join(site, "io.stat"), "w") as fh:
                fh.write("8:0 rbytes=4096 wbytes=8192 rios=1 wios=2\n")
            with mock.patch.object(stats, "CGROUP_ROOT", tmp):
                stats._previous.clear()
                first = stats.sample({})
                self.assertTrue(first["first_sample"])
                self.assertEqual(first["sites"][0]["site_id"], "example_nl")
                self.assertEqual(first["sites"][0]["memory_bytes"], 52428800)
                second = stats.sample({})
                self.assertFalse(second["first_sample"])
                self.assertIn("net_rx_bps", second)


if __name__ == "__main__":
    unittest.main()
