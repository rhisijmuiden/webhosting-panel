"""WordPress plugin sets (wp_sets.py): validation and applying a set with WP-CLI mocked."""

import sys
import unittest
from pathlib import Path
from subprocess import CompletedProcess

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import wp_sets  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


class FakeWp:
    def __init__(self, fail=(), inactive="twentytwentythree\ntwentytwentyfour\n"):
        self.calls = []
        self.fail = set(fail)
        self.inactive = inactive

    def __call__(self, args, timeout=300, input_text=None):
        self.calls.append(list(args))
        if len(args) > 2 and args[2] in self.fail:
            raise ActionError("command_failed", "systemd-run exited with code 1",
                              {"stderr": "Warning: Plugin 'nope' not found.\n", "stdout": ""})
        if args[:2] == ["theme", "list"]:
            return CompletedProcess(args, 0, self.inactive, "")
        return CompletedProcess(args, 0, "", "")


def spec(**over):
    s = {"name": "RHIS standard",
         "plugins": [{"source": "wordfence", "activate": True},
                     {"source": "https://downloads.example.com/pro/pro-forms.zip?key=abc", "activate": False}],
         "themes": [{"source": "generatepress", "activate": True}],
         "remove_default_content": True, "remove_inactive_themes": True}
    s.update(over)
    return s


class ValidateTest(unittest.TestCase):
    def test_empty_is_none(self):
        self.assertIsNone(wp_sets.validate(None))
        self.assertIsNone(wp_sets.validate({}))
        self.assertIsNone(wp_sets.validate({"name": "x", "plugins": [], "themes": []}))

    def test_clean_set(self):
        out = wp_sets.validate(spec(plugins=[{"source": "wordfence", "activate": 1}, {"source": "wordfence"}]))
        self.assertEqual(out["plugins"], [{"source": "wordfence", "activate": True}])
        self.assertEqual(out["themes"][0], {"source": "generatepress", "activate": True})
        self.assertTrue(out["remove_default_content"])

    def test_refuses_bad_sources(self):
        for bad in ["--exec=evil", "-x", "Wordfence", "http://example.com/a.zip", "https://example.com/a.php",
                    "https://example.com/a b.zip", "../../etc", "", 5]:
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                wp_sets.validate(spec(plugins=[{"source": bad}]))

    def test_one_active_theme(self):
        with self.assertRaises(ActionError):
            wp_sets.validate(spec(themes=[{"source": "astra", "activate": True}, {"source": "kadence", "activate": True}]))

    def test_limits(self):
        with self.assertRaises(ActionError):
            wp_sets.validate(spec(plugins=[{"source": f"p{i}"} for i in range(wp_sets.MAX_PLUGINS + 1)]))

    def test_label(self):
        self.assertEqual(wp_sets.label("https://x.example/a/pro-forms.zip?key=1"), "pro-forms")
        self.assertEqual(wp_sets.label("wordfence"), "wordfence")


class ApplyTest(unittest.TestCase):
    def test_applies_everything_in_order(self):
        wp = FakeWp()
        lines = []
        report = wp_sets.apply(wp, wp_sets.validate(spec()), "nl", lines.append)
        self.assertEqual(wp.calls[0], ["theme", "install", "generatepress", "--activate"])
        self.assertEqual(wp.calls[1], ["plugin", "install", "wordfence", "--activate"])
        self.assertEqual(wp.calls[2], ["plugin", "install", "https://downloads.example.com/pro/pro-forms.zip?key=abc"])
        self.assertIn(["language", "plugin", "install", "--all", "nl_NL"], wp.calls)
        self.assertIn(["language", "theme", "install", "--all", "nl_NL"], wp.calls)
        self.assertIn(["post", "delete", "1", "2", "--force"], wp.calls)
        self.assertEqual(wp.calls[-1], ["theme", "delete", "twentytwentythree", "twentytwentyfour"])
        self.assertEqual(report["failed"], 0)
        self.assertEqual([i["name"] for i in report["items"]], ["generatepress", "wordfence", "pro-forms"])
        self.assertTrue(report["items"][1]["activated"])
        self.assertFalse(report["items"][2]["activated"])
        self.assertEqual(report["removed_themes"], ["twentytwentythree", "twentytwentyfour"])

    def test_english_has_no_translation_step(self):
        wp = FakeWp()
        wp_sets.apply(wp, wp_sets.validate(spec()), "en", lambda _l: None)
        self.assertFalse(any(c[0] == "language" for c in wp.calls))

    def test_a_failing_plugin_is_reported_and_the_rest_continues(self):
        wp = FakeWp(fail={"nope"})
        s = wp_sets.validate(spec(plugins=[{"source": "nope", "activate": True}, {"source": "wordfence"}]))
        report = wp_sets.apply(wp, s, "en", lambda _l: None)
        self.assertEqual(report["failed"], 1)
        bad = report["items"][1]
        self.assertFalse(bad["ok"])
        self.assertFalse(bad["activated"])
        self.assertEqual(bad["error"], "Plugin 'nope' not found.")
        self.assertIn(["plugin", "install", "wordfence"], wp.calls)

    def test_odd_theme_names_are_not_deleted(self):
        wp = FakeWp(inactive="twentytwentyfour\n--all\nWarning: something\n")
        wp_sets.apply(wp, wp_sets.validate(spec()), "en", lambda _l: None)
        self.assertEqual(wp.calls[-1], ["theme", "delete", "twentytwentyfour"])


if __name__ == "__main__":
    unittest.main()
