"""Plesk migration: inventory parsing, parameter checks and dump filtering (no source server needed)."""

import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent.actions import migrate  # noqa: E402
from whp_agent.migration import plesk, transfer  # noqa: E402
from whp_agent.migration.ssh import Connection  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

V = "/var/www/vhosts"

ROWS = {
    "clients": [["1", "admin", "admin", "Administrator", "", "admin@host.test", ""],
                ["3", "bakkerij", "client", "Piet Bakker", "Bakkerij Bakker", "piet@bakkerij.test", "0612345678"]],
    "domains": [
        ["10", "Bakkerij.test", "0", "0", "vrt_hst", "0", str(400 * 1024 * 1024), "3", f"{V}/bakkerij.test/httpdocs", "plesk-php82-fpm", "bakkerij", "7"],
        ["11", "api.bakkerij.test", "10", "10", "vrt_hst", "0", "0", "3", f"{V}/bakkerij.test/api.bakkerij.test/public", "plesk-php84-fpm", "bakkerij", "0"],
        ["12", "oud.test", "0", "0", "std_fwd", "16", "0", "1", "", "", "", "0"],
    ],
    "aliases": [["10", "bakkerij-bakker.test", "true", "false"]],
    "databases": [["50", "wp_bakkerij", "mysql", "10", "localhost", "3306"], ["51", "stats", "postgresql", "10", "localhost", "5432"]],
    "db_users": [["50", "wp_bakkerij"], ["50", "wp_ro"]],
    "mail": [
        ["100", "10", "Info", "true", "false", "1073741824", "sym"],
        ["101", "10", "jan", "true", "true", "-1", "crypt"],
        ["102", "10", "factuur", "false", "true", "-1", ""],
    ],
    "mail_redir": [["101", "jan@gmail.test"], ["102", "boekhouder@example.org"]],
    "mail_aliases": [["100", "bestellingen"]],
    "dns": [
        ["7", "A", "bakkerij.test.", "203.0.113.5", ""],
        ["7", "MX", "bakkerij.test.", "mail.bakkerij.test.", "10"],
        ["7", "NS", "bakkerij.test.", "ns1.host.test.", ""],
        ["7", "SRV", "_sip._tcp.bakkerij.test.", "sip.provider.test.", "10 60 5060"],
        ["7", "TXT", "bakkerij.test.", "google-site-verification=abc", ""],
        ["99", "A", "other.test.", "1.2.3.4", ""],
    ],
    "certificates": [["10", "Lets Encrypt bakkerij.test", "cert-AbC123", "cert-CA1"]],
    "ips": [["203.0.113.5"], ["2001:db8::5"]],
}


class InventoryTest(unittest.TestCase):
    def setUp(self):
        self.inv = plesk.build_inventory(ROWS, {"type": "plesk", "version": "18.0.74"}, {"bakkerij.test/info": 230 * 1024},
                                         {f"{V}/bakkerij.test/httpdocs": "wordpress", f"{V}/bakkerij.test/api.bakkerij.test/public": "laravel"})
        self.by = {d["name"]: d for d in self.inv["domains"]}

    def test_domains_sites_and_subscriptions(self):
        main = self.by["bakkerij.test"]
        self.assertEqual(main["kind"], "site")
        self.assertEqual(main["php"], "8.2")
        self.assertEqual(main["size_mb"], 400)
        self.assertEqual(main["web"]["base"], f"{V}/bakkerij.test/httpdocs")
        self.assertEqual(main["web"]["sub"], "")
        self.assertEqual(main["web"]["app"], "wordpress")
        api = self.by["api.bakkerij.test"]
        self.assertTrue(api["is_subdomain"])
        self.assertEqual(api["subscription"], "bakkerij.test")
        self.assertEqual(api["web"]["base"], f"{V}/bakkerij.test/api.bakkerij.test")
        self.assertEqual(api["web"]["sub"], "public")
        self.assertEqual(self.by["oud.test"]["kind"], "forward")
        self.assertFalse(self.by["oud.test"]["active"])
        self.assertEqual([o["login"] for o in self.inv["owners"]], ["admin", "bakkerij"])
        self.assertEqual(main["aliases"], [{"name": "bakkerij-bakker.test", "web": True, "mail": False}])

    def test_databases_and_mail(self):
        main = self.by["bakkerij.test"]
        self.assertEqual(main["databases"][0], {"name": "wp_bakkerij", "type": "mysql", "server": "local", "users": ["wp_bakkerij", "wp_ro"]})
        self.assertTrue(any("postgresql" in n for n in main["notes"]))
        boxes = {b["local"]: b for b in main["mailboxes"]}
        self.assertEqual(boxes["info"]["quota_mb"], 1024)
        self.assertEqual(boxes["info"]["size_mb"], 230)
        self.assertEqual(boxes["info"]["aliases"], ["bestellingen"])
        self.assertEqual(boxes["info"]["password"], "plain")
        self.assertEqual(boxes["jan"]["password"], "hash")
        self.assertEqual(boxes["jan"]["forward"], ["jan@gmail.test"])
        self.assertEqual(main["forwarders"], [{"local": "factuur", "targets": ["boekhouder@example.org"]}])
        self.assertEqual(self.inv["totals"]["mailboxes"], 2)

    def test_dns_and_certificates(self):
        recs = self.by["bakkerij.test"]["dns"]
        self.assertNotIn("NS", [r["type"] for r in recs])
        mx = next(r for r in recs if r["type"] == "MX")
        self.assertEqual((mx["content"], mx["priority"]), ("mail.bakkerij.test", 10))
        srv = next(r for r in recs if r["type"] == "SRV")
        self.assertEqual(srv["srv"], {"priority": 10, "weight": 60, "port": 5060, "target": "sip.provider.test"})
        self.assertTrue(self.by["bakkerij.test"]["ssl"]["lets_encrypt"])
        self.assertEqual(self.inv["server_ips"], ["2001:db8::5", "203.0.113.5"])

    def test_broken_rows_are_ignored(self):
        inv = plesk.build_inventory({"domains": [["1", "x"]], "mail": [["1"]]}, {}, {}, {})
        self.assertEqual(inv["domains"], [])


class HelpersTest(unittest.TestCase):
    def test_php_version(self):
        self.assertEqual(plesk.php_version("plesk-php74-fastcgi"), "7.4")
        self.assertEqual(plesk.php_version("plesk-php85-fpm"), "8.5")
        self.assertEqual(plesk.php_version("module"), "")

    def test_web_paths_reject_odd_roots(self):
        self.assertIsNone(plesk._web_paths("a.test", "/home/a/public_html"))
        self.assertIsNone(plesk._web_paths("a.test", f"{V}/a.test/../../etc"))
        self.assertIsNone(plesk._web_paths("a.test", f"{V}/a.test"))

    def test_tsv(self):
        self.assertEqual(plesk.parse_tsv("a\tNULL\tb\\tc\n\n"), [["a", "", "b\tc"]])

    def test_mail_auth_view(self):
        out = plesk.parse_mail_auth_view(
            "+--------------------+-------+-----------+\n"
            "| address            | flags | password  |\n"
            "+--------------------+-------+-----------+\n"
            "| Info@bakkerij.test |       | Ge|heim 1 |\n"
            "| jan@bakkerij.test  |       |  spatie   |\n"
            "+--------------------+-------+-----------+\n")
        self.assertEqual(out, {"info@bakkerij.test": "Ge|heim 1", "jan@bakkerij.test": " spatie"})

    def test_split_pem(self):
        key = "-----BEGIN PRIVATE KEY-----\nAAA\n-----END PRIVATE KEY-----"
        c1 = "-----BEGIN CERTIFICATE-----\nCCC\n-----END CERTIFICATE-----"
        ca = "-----BEGIN CERTIFICATE-----\nDDD\n-----END CERTIFICATE-----"
        pem = plesk.split_pem(f"{key}\n{c1}\n", f"{ca}\n{c1}\n")
        self.assertEqual(pem["key"], key + "\n")
        self.assertEqual(pem["fullchain"], f"{c1}\n{ca}\n")
        self.assertIsNone(plesk.split_pem(c1))

    def test_sql_literal(self):
        self.assertEqual(plesk._lit("a'b\\"), "'a\\'b\\\\'")

    def test_dump_filter(self):
        line = b"/*!50013 DEFINER=`wp`@`%` SQL SECURITY DEFINER */\n"
        self.assertEqual(transfer.filter_dump_line(line), b"\n")
        self.assertEqual(transfer.filter_dump_line(b"CREATE DEFINER=`a`@`localhost` TRIGGER t"), b"CREATE TRIGGER t")
        self.assertEqual(transfer.filter_dump_line(b"COLLATE=utf8mb4_0900_ai_ci;"), b"COLLATE=utf8mb4_unicode_ci;")
        self.assertEqual(transfer.filter_dump_line(b"INSERT INTO x VALUES ('DEFINER')"), b"INSERT INTO x VALUES ('DEFINER')")


class ParamsTest(unittest.TestCase):
    def test_connection_validation(self):
        c = Connection({"host": "Plesk.Example.test", "port": 2222})
        self.assertEqual((c.host, c.port, c.user, c.target), ("plesk.example.test", 2222, "root", "root@plesk.example.test"))
        self.assertEqual(Connection({"host": "2001:db8::1"}).target, "root@[2001:db8::1]")
        for bad in ({"host": "a b"}, {"host": "-oProxyCommand=x"}, {"host": "a.test", "port": 0}, {"host": "a.test", "user": "root;id"}, {}):
            with self.assertRaises(ActionError):
                Connection(bad)

    def test_transfer_plan(self):
        plan = migrate.parse_transfer({
            "domain": "Bakkerij.test", "files": {"target": "public"},
            "databases": [{"name": "wp_bakkerij", "users": ["wp_bakkerij"]}],
            "mail": {"mailboxes": ["info", "jan"]}, "ssl": True,
        })
        self.assertEqual(plan["domain"], "bakkerij.test")
        self.assertEqual(plan["files"], {"target": "public", "delete": True})
        self.assertEqual(plan["databases"], [{"name": "wp_bakkerij", "target": "wp_bakkerij", "users": ["wp_bakkerij"]}])
        self.assertEqual(plan["mail"], {"mode": "mirror", "mailboxes": ["info", "jan"]})

    def test_transfer_plan_rejects_paths_and_names(self):
        for bad in (
            {"domain": "a.test", "files": {"target": "../etc"}},
            {"domain": "a.test", "files": {"target": "a/b"}},
            {"domain": "a.test", "databases": [{"name": "x; DROP"}]},
            {"domain": "a.test", "databases": [{"name": "ok", "users": ["a'b"]}]},
            {"domain": "a.test", "mail": {"mailboxes": ["../root"]}},
            {"domain": "a.test", "mail": {"mailboxes": ["a"], "mode": "delete"}},
        ):
            with self.assertRaises(ActionError, msg=bad):
                migrate.parse_transfer(bad)

    def test_reserved_names(self):
        with self.assertRaises(ActionError):
            transfer.ensure_db_user("root", "wp", None)
        with self.assertRaises(ActionError):
            transfer.import_database(None, "true", "whp_panel")


if __name__ == "__main__":
    unittest.main()
