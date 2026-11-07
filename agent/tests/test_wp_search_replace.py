"""WordPress search & replace and change domain: helpers, the WP-CLI calls and the
apply/rollback flow. WP-CLI is faked; the real run is in tests/smoke.d/wp-search-replace.sh."""

import json
import os
import re
import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths, wordpress  # noqa: E402
from whp_agent.actions import sites  # noqa: E402
from whp_agent.actions import wordpress as wpa  # noqa: E402
from whp_agent.actions import wp_search_replace as sr  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

REPORT = "Table\tColumn\tReplacements\tType\nwp_options\toption_value\t2\tPHP\nwp_posts\tpost_content\t5\tSQL\n" \
         "Success: 7 replacements to be made.\n"
LOG = ("wp_options.option_value:1\n< https://oud.nl\n> https://klant.nl\n"
       "wp_posts.post_content:12\n< <a href=\"https://oud.nl/contact\">\n> <a href=\"https://klant.nl/contact\">\n")


def make_wp(d):
    (d / "wp-includes").mkdir(parents=True)
    (d / "wp-content/plugins").mkdir(parents=True)
    (d / "wp-content/themes").mkdir(parents=True)
    (d / "wp-config.php").write_text("<?php\n")
    (d / "wp-includes/version.php").write_text("<?php\n$wp_version = '6.7.1';\n")


class FakeRun:
    def __init__(self):
        self.calls = []
        self.home = "https://oud.nl"
        self.constants = {}
        self.multisite = False
        self.health = []
        self.fail_replace = False
        self.aliases = [{"domain": "oud.nl", "mode": "serve", "www": True, "ssl": "active", "names": ["oud.nl", "www.oud.nl"]},
                        {"domain": "weg.nl", "mode": "redirect", "www": False, "ssl": "active", "names": ["weg.nl"]}]

    def __call__(self, argv, timeout=60, check=True, input_text=None, cwd=None, env=None):
        argv = list(argv)
        self.calls.append(argv)
        out, code = "", 0
        if argv[0] == "curl":
            out = str(self.health.pop(0) if self.health else 200)
        elif argv[0].endswith("whp-site") and argv[1:3] == ["alias", "list"]:
            out = json.dumps({"domain": argv[3], "domain_aliases": self.aliases})
        elif wordpress.WP_CLI in argv:
            out, code = self.wp(argv[argv.index(wordpress.WP_CLI) + 3:])
        if check and code:
            raise ActionError("command_failed", "failed")
        return SimpleNamespace(stdout=out, stderr="" if not code else "Error: boom", returncode=code)

    def wp(self, args):
        if args[:2] == ["option", "get"]:
            return self.home, 0
        if args[:2] == ["config", "get"]:
            return (self.constants[args[2]], 0) if args[2] in self.constants else ("", 1)
        if args[:2] == ["config", "set"]:
            self.constants[args[2]] = args[3]
            return "", 0
        if args[:2] == ["core", "is-installed"]:
            return "", 0 if self.multisite else 1
        if args[0] == "search-replace":
            if self.fail_replace and "--dry-run" not in args:
                return "", 1
            for a in args:
                if a.startswith("--log="):
                    Path(a[6:]).write_text(LOG)
            return REPORT, 0
        return "", 0

    def wp_args(self):
        return [c[c.index(wordpress.WP_CLI) + 3:] for c in self.calls if wordpress.WP_CLI in c]

    def replaces(self):
        return [a for a in self.wp_args() if a[0] == "search-replace"]


class HelperTest(unittest.TestCase):
    def test_domain_patterns_match_whole_hosts_only(self):
        pats = sr.domain_patterns(["www.oud.nl"], "https://klant.nl")
        plain, escaped = pats[0][0], pats[1][0]
        for text, hit in [("http://oud.nl/x", True), ("https://www.oud.nl", True), ("HTTPS://OUD.NL", True),
                          ("https://oud.nl.au/", False), ("https://oud.nlx.com", False), ("https://oud.nl-shop.com", False),
                          ("https://nieuw-oud.nl", False), ("https://oud.nl:8080", True), ("mail@oud.nl", False)]:
            with self.subTest(text=text):
                self.assertEqual(hit, bool(re.search(plain, text, re.I)))
        self.assertTrue(re.search(escaped, r'{"u":"https:\/\/www.oud.nl\/wp-content"}', re.I))
        self.assertFalse(re.search(escaped, r'"https:\/\/oud.nl.au"', re.I))
        # preg_replace replacement: backslashes doubled.
        self.assertEqual(r"https:\\/\\/klant.nl", pats[1][1])

    def test_rewrite_url_keeps_the_path(self):
        self.assertEqual("https://klant.nl/blog", sr.rewrite_url("http://www.oud.nl/blog", ["oud.nl"], "https://klant.nl"))
        self.assertEqual("https://oud.nl.au", sr.rewrite_url("https://oud.nl.au", ["oud.nl"], "https://klant.nl"))

    def test_parse_report_tab_and_ascii(self):
        self.assertEqual({"total": 7, "tables": [
            {"table": "wp_options", "column": "option_value", "count": 2, "type": "PHP"},
            {"table": "wp_posts", "column": "post_content", "count": 5, "type": "SQL"}]}, sr.parse_report(REPORT))
        ascii_ = "+----+\n| Table | Column | Replacements | Type |\n| wp_posts | guid | 3 | SQL |\n"
        self.assertEqual(3, sr.parse_report(ascii_)["total"])
        self.assertEqual(4, sr.parse_report("Success: Made 4 replacements.")["total"])

    def test_parse_log_samples_and_limit(self):
        samples, more = sr.parse_log(LOG)
        self.assertFalse(more)
        self.assertEqual({"table": "wp_posts", "column": "post_content", "row": "12",
                          "old": '<a href="https://oud.nl/contact">', "new": '<a href="https://klant.nl/contact">'}, samples[1])
        samples, more = sr.parse_log(LOG, limit=1)
        self.assertEqual(1, len(samples))
        self.assertTrue(more)

    def test_params_are_checked(self):
        base = {"domain": "klant.nl", "mode": "text"}
        for bad in [{"search": "ab", "replace": "x"}, {"search": "--url=x", "replace": "y"}, {"search": "abc", "replace": "-x"},
                    {"search": "abc", "replace": "abc"}, {"search": "a\x00bc", "replace": "x"}, {"search": "x" * 1001, "replace": ""},
                    {"search": 5, "replace": ""}]:
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                sr._common({**base, **bad})
        job = sr._common({**base, "search": "oud bedrijf", "replace": ""})
        self.assertEqual(["search-replace", "oud bedrijf", "", "--all-tables-with-prefix", "--report-changed-only", "--skip-columns=guid"],
                         sr._argvs(job, {})[0])
        job = sr._common({**base, "search": "abc", "replace": "def", "tables": "all", "skip_guid": False})
        self.assertEqual(["search-replace", "abc", "def", "--all-tables", "--report-changed-only"], sr._argvs(job, {})[0])


class ActionTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        (root / "sites").mkdir()
        (root / "sites/klant.nl.env").write_text(
            "SITE_DOMAIN=klant.nl\nSITE_ID=klant_nl\nSITE_USER=s_klant_nl\nSITE_PHP=8.4\n"
            "SITE_DOCROOT_REL=public\nSITE_SSL=active\nSITE_ALIASES=www.klant.nl\n")
        make_wp(root / "vhosts/klant.nl/public")
        (root / "vhosts/klant.nl/tmp").mkdir()
        self.fake = FakeRun()
        self.patches = [
            mock.patch.object(wordpress, "SITES_DB", str(root / "sites")),
            mock.patch.dict(os.environ, {"WHP_VHOSTS": str(root / "vhosts")}),
            mock.patch.object(wordpress, "run", self.fake),
            mock.patch.object(wordpress, "ensure_cli", lambda: wordpress.WP_CLI),
            mock.patch.object(wpa, "run", self.fake),
            mock.patch.object(sr, "run", self.fake),
            mock.patch.object(sites, "run", self.fake),
            mock.patch.object(wpa, "STATE", root / "state/wordpress"),
            mock.patch.object(wpa, "SNAPSHOTS", root / "state/wordpress/snapshots"),
            mock.patch.object(paths, "JOBS_DIR", str(root / "jobs")),
            mock.patch("shutil.chown"),
        ]
        for p in self.patches:
            p.start()
        self.tmpdir = root / "vhosts/klant.nl/tmp"

    def tearDown(self):
        for p in reversed(self.patches):
            p.stop()
        self.tmp.cleanup()

    def wait(self, job):
        for _ in range(200):
            state = jobs.read(job["job"])
            if state["status"] != "running":
                return state
            time.sleep(0.05)
        raise AssertionError("job did not finish")

    def test_addresses_lists_served_hosts_not_redirects(self):
        out = sr.wp_sr_addresses({"domain": "klant.nl"})
        self.assertEqual("https://oud.nl", out["home"])
        self.assertFalse(out["multisite"])
        hosts = {h["host"]: h["ssl"] for h in out["hosts"]}
        self.assertEqual({"klant.nl": True, "www.klant.nl": True, "oud.nl": True, "www.oud.nl": True}, hosts)

    def test_text_preview_is_a_dry_run_with_samples_and_no_log_left(self):
        state = self.wait(sr.wp_sr_preview({"domain": "klant.nl", "mode": "text", "search": "Oud Bedrijf BV", "replace": "Klant BV"}))
        self.assertEqual("succeeded", state["status"], state)
        r = state["result"]
        self.assertEqual(7, r["total"])
        self.assertEqual("wp_posts", r["tables"][0]["table"])
        self.assertEqual(2, len(r["samples"]))
        self.assertTrue(r["samples_truncated"])  # 7 changes, 2 samples
        argv = self.fake.replaces()[0]
        self.assertIn("--dry-run", argv)
        self.assertTrue(any(a.startswith("--log=") for a in argv))
        self.assertEqual([], list(self.tmpdir.iterdir()))
        self.assertFalse(any(a[:2] == ["db", "export"] for a in self.fake.wp_args()))

    def test_domain_change_apply_snapshot_constants_and_flush(self):
        self.fake.constants = {"WP_HOME": "https://oud.nl", "COOKIE_DOMAIN": ".www.oud.nl"}
        state = self.wait(sr.wp_sr_apply({"domain": "klant.nl", "mode": "domain", "host": "klant.nl", "scheme": "https"}))
        self.assertEqual("succeeded", state["status"], state)
        r = state["result"]
        self.assertEqual("https://klant.nl", r["new_home"])
        self.assertEqual(14, r["total"])  # two passes: plain and JSON-escaped
        self.assertIsNotNone(r["snapshot"])
        self.assertEqual({"WP_HOME": "https://klant.nl", "COOKIE_DOMAIN": ".klant.nl"}, self.fake.constants)
        args = self.fake.wp_args()
        export = next(i for i, a in enumerate(args) if a[:2] == ["db", "export"])
        first = next(i for i, a in enumerate(args) if a[0] == "search-replace")
        self.assertLess(export, first)
        for a in self.fake.replaces():
            self.assertIn("--regex", a)
            self.assertNotIn("--dry-run", a)
            self.assertEqual("https://klant.nl" if "\\" not in a[2] else r"https:\\/\\/klant.nl", a[2])
        self.assertTrue(any(a[:2] == ["cache", "flush"] for a in args))
        self.assertTrue(any(c[:4] == [wpa.WHP_SITE, "cache", "klant.nl", "purge"] for c in self.fake.calls))

    def test_broken_site_is_put_back(self):
        self.fake.constants = {"WP_HOME": "https://oud.nl"}
        self.fake.health = [200, 500]
        state = self.wait(sr.wp_sr_apply({"domain": "klant.nl", "mode": "domain", "host": "klant.nl"}))
        self.assertEqual("failed", state["status"])
        self.assertTrue(state["error"]["details"]["rolled_back"])
        self.assertTrue(any(a[:2] == ["db", "import"] for a in self.fake.wp_args()))
        self.assertEqual({"WP_HOME": "https://oud.nl"}, self.fake.constants)

    def test_failed_replace_restores_the_snapshot(self):
        self.fake.fail_replace = True
        state = self.wait(sr.wp_sr_apply({"domain": "klant.nl", "mode": "text", "search": "abc", "replace": "def"}))
        self.assertEqual("failed", state["status"])
        self.assertTrue(any(a[:2] == ["db", "import"] for a in self.fake.wp_args()))

    def test_domain_change_refusals(self):
        cases = [
            ({"host": "weg.nl"}, "forbidden"),          # redirecting alias: not served by the site
            ({"host": "vreemd.nl"}, "forbidden"),
            ({"host": "oud.nl"}, "invalid_params"),     # already the address
        ]
        for extra, code in cases:
            with self.subTest(extra=extra):
                state = self.wait(sr.wp_sr_preview({"domain": "klant.nl", "mode": "domain", **extra}))
                self.assertEqual(code, state["error"]["code"], state)
        self.fake.aliases[0]["ssl"] = "pending"
        self.fake.home = "https://klant.nl"
        state = self.wait(sr.wp_sr_preview({"domain": "klant.nl", "mode": "domain", "host": "oud.nl", "scheme": "https"}))
        self.assertEqual("conflict", state["error"]["code"])
        state = self.wait(sr.wp_sr_preview({"domain": "klant.nl", "mode": "domain", "host": "oud.nl", "scheme": "http"}))
        self.assertEqual("succeeded", state["status"], state)
        self.fake.multisite = True
        state = self.wait(sr.wp_sr_preview({"domain": "klant.nl", "mode": "domain", "host": "oud.nl", "scheme": "http"}))
        self.assertEqual("conflict", state["error"]["code"])

    def test_http_to_https_on_the_same_host(self):
        self.fake.home = "http://klant.nl"
        state = self.wait(sr.wp_sr_preview({"domain": "klant.nl", "mode": "domain", "host": "klant.nl", "scheme": "https"}))
        self.assertEqual("succeeded", state["status"], state)
        self.assertEqual("https://klant.nl", state["result"]["new_home"])

    def test_one_run_at_a_time_per_install(self):
        key = sr._claim({"domain": "klant.nl", "path": ""})
        try:
            state = self.wait(sr.wp_sr_preview({"domain": "klant.nl", "mode": "text", "search": "abc", "replace": "x"}))
            self.assertEqual("conflict", state["error"]["code"])
        finally:
            sr._release(key)


if __name__ == "__main__":
    unittest.main()
