import os
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from whp_agent.actions import domain_aliases, sites  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402


class DomainAliasActionsTest(unittest.TestCase):
    def setUp(self):
        patcher = mock.patch.object(sites, "run")
        self.run = patcher.start()
        self.addCleanup(patcher.stop)
        self.run.return_value = mock.Mock(stdout='{"domain": "a.nl", "domain_aliases": []}')

    def argv(self):
        return self.run.call_args[0][0]

    def test_registered(self):
        for name in ["site.alias_list", "site.alias_add", "site.alias_mode", "site.alias_ssl", "site.alias_remove"]:
            self.assertIn(name, ACTIONS)

    def test_add_defaults(self):
        domain_aliases.alias_add({"domain": "A.nl", "alias": "B.be"})
        self.assertEqual(self.argv(), [sites.WHP_SITE, "alias", "add", "a.nl", "b.be", "--json"])

    def test_add_options(self):
        domain_aliases.alias_add({"domain": "a.nl", "alias": "b.be", "mode": "redirect", "www": False, "ssl": False})
        self.assertEqual(self.argv()[4:], ["b.be", "--redirect", "--no-www", "--no-ssl", "--json"])

    def test_mode_and_remove(self):
        domain_aliases.alias_mode({"domain": "a.nl", "alias": "b.be", "mode": "serve"})
        self.assertEqual(self.argv(), [sites.WHP_SITE, "alias", "mode", "a.nl", "b.be", "serve", "--json"])
        domain_aliases.alias_remove({"domain": "a.nl", "alias": "b.be", "keep_cert": True})
        self.assertEqual(self.argv(), [sites.WHP_SITE, "alias", "remove", "a.nl", "b.be", "--keep-cert", "--json"])

    def test_rejects_bad_input(self):
        for params in [{"domain": "a.nl", "alias": "a.nl"}, {"domain": "a.nl", "alias": "www.a.nl"},
                       {"domain": "a.nl", "alias": "b.be; reboot"}, {"domain": "a.nl", "alias": "b.be", "mode": "x"}]:
            with self.assertRaises(ActionError):
                domain_aliases.alias_add(params)


if __name__ == "__main__":
    unittest.main()
