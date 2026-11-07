"""WordPress database cleanup (actions/wp_dbclean.py): params, the WP-CLI call, the
snapshot before cleaning and the database restore when the site breaks.
WP-CLI is faked; tests/wp_dbclean_smoke.py runs the PHP script on a real install."""

import json
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.insert(0, str(HERE))

from unittest import mock  # noqa: E402

import test_wordpress_actions as base  # noqa: E402
from whp_agent import wordpress  # noqa: E402
from whp_agent.actions import wp_dbclean  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

PREVIEW = {"prefix": "wp_", "multisite": False, "db_size": 5_000_000, "tables": 12, "keep_revisions": 0,
           "items": {"revisions": {"count": 120, "bytes": 900_000, "examples": [{"label": "Home", "count": 80}]}}}
CLEANED = {"removed": {"revisions": 120, "optimize": 3}, "optimized": ["wp_posts"], "db_size_before": 5_000_000,
           "db_size_after": 4_000_000, "keep_revisions": 0}


class DbCleanTest(unittest.TestCase):
    # The toolkit test's fixture (a site, two installs, faked WP-CLI), without its tests.
    tearDown = base.WordPressTest.tearDown
    wait = base.WordPressTest.wait

    def setUp(self):
        base.WordPressTest.setUp(self)
        self.fake.answers["eval-file -"] = "PHP Notice: something\n\n" + wp_dbclean.MARKER + json.dumps(PREVIEW)
        self.patches.append(mock.patch.object(wp_dbclean, "run", self.fake))
        self.patches[-1].start()

    def evals(self):
        return [c for c in self.fake.wp_calls() if "eval-file" in c["argv"]]

    def test_preview_runs_the_script_as_the_site_user_and_reads_the_marker_line(self):
        out = wp_dbclean.wp_dbclean_preview({"domain": "klant.nl", "keep_revisions": 3})
        self.assertEqual(120, out["items"]["revisions"]["count"])
        self.assertEqual("klant.nl", out["domain"])
        call = self.evals()[0]
        argv = call["argv"]
        self.assertIn("--uid=s_klant_nl", argv)
        self.assertEqual(["eval-file", "-", "preview", json.dumps({"keep_revisions": 3})],
                         argv[argv.index(wordpress.WP_CLI) + 3:argv.index(wordpress.WP_CLI) + 7])
        self.assertIn("--skip-plugins", argv)
        # The script comes in on stdin, not on a command line.
        self.assertIn("whp_dbclean_preview", call["input"])

    def test_params_are_checked(self):
        for bad in [{"keep_revisions": 4}, {"keep_revisions": True}, {"items": ["revisions", "users"]}, {"items": []},
                    {"items": "all"}, {"path": "../x"}]:
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                if "items" in bad:
                    wp_dbclean.wp_dbclean_run({"domain": "klant.nl", **bad})
                else:
                    wp_dbclean.wp_dbclean_preview({"domain": "klant.nl", **bad})
        with self.assertRaises(ActionError):
            wp_dbclean.wp_dbclean_preview({"domain": "onbekend.nl"})

    def test_no_answer_is_an_error(self):
        self.fake.answers["eval-file -"] = "Fatal error: oops"
        with self.assertRaises(ActionError) as e:
            wp_dbclean.wp_dbclean_preview({"domain": "klant.nl"})
        self.assertEqual("command_failed", e.exception.code)

    def test_run_makes_a_snapshot_first_then_cleans_and_purges_the_cache(self):
        self.fake.answers["eval-file -"] = wp_dbclean.MARKER + json.dumps(CLEANED)
        state = self.wait(wp_dbclean.wp_dbclean_run({"domain": "klant.nl", "items": ["optimize", "revisions"]}))
        self.assertEqual("succeeded", state["status"], state.get("error"))
        res = state["result"]
        self.assertEqual(["revisions", "optimize"], res["items"])  # always in a fixed order
        self.assertEqual(120, res["removed"]["revisions"])
        self.assertFalse(res["rolled_back"])
        self.assertTrue(res["snapshot"])
        args = self.fake.wp_args()
        export = next(i for i, a in enumerate(args) if a[:2] == ["db", "export"])
        clean = next(i for i, a in enumerate(args) if a[:1] == ["eval-file"])
        self.assertLess(export, clean)
        self.assertEqual("clean", args[clean][2])
        self.assertEqual({"items": ["revisions", "optimize"], "keep_revisions": 0}, json.loads(args[clean][3]))
        self.assertTrue(any(c["argv"][:3] == [wp_dbclean.toolkit.WHP_SITE, "cache", "klant.nl"] for c in self.fake.calls))
        self.assertFalse(any(a[:2] == ["db", "import"] for a in args))

    def test_default_items_leave_fresh_transients_alone(self):
        self.fake.answers["eval-file -"] = wp_dbclean.MARKER + json.dumps(CLEANED)
        state = self.wait(wp_dbclean.wp_dbclean_run({"domain": "klant.nl"}))
        self.assertNotIn("all_transients", state["result"]["items"])
        self.assertIn("expired_transients", state["result"]["items"])

    def test_the_database_is_put_back_when_the_site_breaks(self):
        self.fake.answers["eval-file -"] = wp_dbclean.MARKER + json.dumps(CLEANED)
        self.fake.health = [200, 500, 200]
        state = self.wait(wp_dbclean.wp_dbclean_run({"domain": "klant.nl", "items": ["all_transients"]}))
        self.assertEqual("failed", state["status"])
        self.assertTrue(state["error"]["details"]["rolled_back"])
        self.assertEqual(200, state["error"]["details"]["health"]["after"])
        imports = [a for a in self.fake.wp_args() if a[:2] == ["db", "import"]]
        self.assertEqual(1, len(imports))
        self.assertTrue(imports[0][2].endswith("database.sql"))


if __name__ == "__main__":
    unittest.main()
