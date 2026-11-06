"""Vulnerability matching (Wordfence feed + WPVulnerability API), malware reports and posture."""

import io
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import vulndb  # noqa: E402
from whp_agent.actions import sitescan  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

FEED = {
    "a1": {
        "id": "a1", "title": "Contact Form 7 <= 5.8.3 - Unauthenticated Arbitrary File Upload",
        "software": [{"type": "plugin", "name": "Contact Form 7", "slug": "contact-form-7",
                      "affected_versions": {"* - 5.8.3": {"from_version": "*", "from_inclusive": True, "to_version": "5.8.3", "to_inclusive": True}},
                      "patched": True, "patched_versions": ["5.8.4"], "remediation": "Update to version 5.8.4, or a newer patched version"}],
        "informational": False, "description": "The plugin allows file uploads.", "references": ["https://example.test/a1"],
        "cwe": {"id": 434, "name": "Unrestricted Upload"}, "cvss": {"vector": "CVSS:3.1/AV:N", "score": 9.8, "rating": "Critical"},
        "cve": "CVE-2023-6449", "cve_link": "https://www.cve.org/CVERecord?id=CVE-2023-6449", "researchers": [],
        "published": "2023-12-01T00:00:00+00:00", "updated": "2023-12-02T00:00:00+00:00",
        "copyrights": {"message": "x", "defiant": {"notice": "Copyright 2012-2026 Defiant Inc.", "license": "Defiant", "license_url": "u"}},
    },
    "b2": {
        "id": "b2", "title": "Old theme XSS", "informational": False, "description": "",
        "software": [{"type": "theme", "name": "Oud", "slug": "oud",
                      "affected_versions": {"1.0 - 2.0": {"from_version": "1.0", "from_inclusive": True, "to_version": "2.0", "to_inclusive": False}},
                      "patched": False, "patched_versions": [], "remediation": None}],
        "cvss": {"score": 6.1, "rating": "Medium"}, "references": [], "cve": None,
    },
    "c3": {"id": "c3", "title": "Informational", "informational": True, "software": []},
}


class VersionTest(unittest.TestCase):
    def test_php_version_compare(self):
        vc = vulndb.version_compare
        self.assertEqual(vc("5.8.3", "5.8.4"), -1)
        self.assertEqual(vc("5.10", "5.9"), 1)
        self.assertEqual(vc("1.0", "1.0.0"), -1)  # PHP semantics; ranges normalise first
        self.assertEqual(vc("1.0-beta", "1.0"), -1)
        self.assertEqual(vc("1.0RC1", "1.0beta2"), 1)
        self.assertEqual(vc("2.0.1", "2.0.1"), 0)

    def test_in_range(self):
        r = {"from": "1.0", "from_inclusive": True, "to": "2.0", "to_inclusive": False}
        self.assertTrue(vulndb.in_range("1.0.0", r))
        self.assertTrue(vulndb.in_range("1.9.9", r))
        self.assertFalse(vulndb.in_range("2.0", r))
        self.assertFalse(vulndb.in_range("0.9", r))
        self.assertTrue(vulndb.in_range("0.1", {"from": "*", "to": "5.8.3", "to_inclusive": True}))
        self.assertFalse(vulndb.in_range("", {"from": "*", "to": "*"}))

    def test_severity(self):
        self.assertEqual(vulndb.severity(9.8), "critical")
        self.assertEqual(vulndb.severity(None, "h"), "high")
        self.assertEqual(vulndb.severity("5.0"), "medium")
        self.assertEqual(vulndb.severity(None), "unknown")


class StreamTest(unittest.TestCase):
    def test_iter_object_reads_in_small_chunks(self):
        text = json.dumps(FEED, indent=1)
        fh = io.StringIO(text)
        got = dict(vulndb.iter_object(type("F", (), {"read": lambda self, n: fh.read(7)})()))
        self.assertEqual(got, FEED)

    def test_cut_off_feed_is_an_error(self):
        with self.assertRaises(ActionError):
            list(vulndb.iter_object(io.StringIO(json.dumps(FEED)[:-40])))


class WordfenceTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.db = os.path.join(self.tmp.name, "wf.sqlite")
        p = mock.patch.object(vulndb, "DB_PATH", self.db)
        p.start()
        self.addCleanup(p.stop)
        self.addCleanup(self.tmp.cleanup)
        n = vulndb.import_wordfence(io.BytesIO(json.dumps(FEED).encode()), db_path=self.db)
        self.assertEqual(n, 2)

    def test_status(self):
        st = vulndb.wordfence_status()
        self.assertTrue(st["ready"])
        self.assertEqual(st["records"], 2)

    def test_match_returns_vulnerable_items_with_fix(self):
        res = sitescan.vuln_match({"source": "wordfence", "items": [
            {"type": "plugin", "slug": "contact-form-7", "version": "5.8.1"},
            {"type": "plugin", "slug": "contact-form-7", "version": "5.9"},
            {"type": "theme", "slug": "oud", "version": "1.5"},
            {"type": "plugin", "slug": "akismet", "version": "5.3"},
        ]})
        self.assertEqual(len(res["matches"]), 2)
        cf7 = res["matches"][0]
        self.assertEqual(cf7["version"], "5.8.1")
        hit = cf7["vulnerabilities"][0]
        self.assertEqual(hit["severity"], "critical")
        self.assertEqual(hit["fixed_in"], "5.8.4")
        self.assertEqual(hit["cve"], "CVE-2023-6449")
        self.assertIn("Defiant", hit["copyright"])
        self.assertTrue(hit["references"][0].startswith("https://www.cve.org"))
        oud = res["matches"][1]["vulnerabilities"][0]
        self.assertIsNone(oud["fixed_in"])
        self.assertEqual(oud["severity"], "medium")

    def test_invalid_items_are_refused(self):
        for bad in ({"type": "plugin", "slug": "../x", "version": "1"}, {"type": "x", "slug": "a", "version": "1"},
                    {"type": "plugin", "slug": "a", "version": "1; rm"}):
            with self.assertRaises(ActionError):
                sitescan.vuln_match({"source": "wordfence", "items": [bad]})

    def test_missing_database(self):
        os.unlink(self.db)
        with self.assertRaises(ActionError) as cm:
            vulndb.match([{"type": "plugin", "slug": "a", "version": "1"}], "wordfence")
        self.assertEqual(cm.exception.code, "not_found")

    def test_bad_api_key(self):
        with self.assertRaises(ActionError):
            vulndb.sync_wordfence("x")


class Resp(io.BytesIO):
    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False


WPV_PLUGIN = {"error": 0, "data": {"name": "Elementor", "plugin": "elementor", "vulnerability": [
    {"uuid": "u1", "name": "Elementor < 3.18.2 - Authenticated RCE",
     "operator": {"min_version": None, "min_operator": None, "max_version": "3.18.2", "max_operator": "lt", "unfixed": "0", "closed": "0"},
     "source": [{"id": "CVE-2023-48777", "name": "CVE-2023-48777", "link": "https://cve.example/48777", "description": "RCE via upload", "date": "2024-01-01"}],
     "impact": {"cvss": {"version": "3.1", "score": "9.9", "severity": "c"}}},
    {"uuid": "u2", "name": "Elementor 2.x XSS",
     "operator": {"min_version": "2.0", "min_operator": "ge", "max_version": "2.9", "max_operator": "le", "unfixed": "1", "closed": "0"},
     "source": [], "impact": {}},
]}}


class WpVulnerabilityTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        p = mock.patch.object(vulndb, "CACHE_DIR", self.tmp.name)
        p.start()
        self.addCleanup(p.stop)
        self.urls = []

        def fake_open(url, headers=None, timeout=30):
            self.urls.append(url)
            if "/core/" in url:
                return Resp(json.dumps({"error": 0, "data": {"vulnerability": [{"uuid": "c1", "name": "WP core issue", "operator": {}, "source": [], "impact": {"cvss": {"score": "4.3"}}}]}}).encode())
            if "/plugin/elementor/" in url:
                return Resp(json.dumps(WPV_PLUGIN).encode())
            return Resp(json.dumps({"error": 0, "data": {"vulnerability": None}}).encode())

        p2 = mock.patch.object(vulndb, "_open", side_effect=fake_open)
        p2.start()
        self.addCleanup(p2.stop)

    def test_match_and_cache(self):
        items = [{"type": "plugin", "slug": "elementor", "version": "3.18.0"},
                 {"type": "core", "slug": "wordpress", "version": "6.4.1"},
                 {"type": "theme", "slug": "astra", "version": "4.0"}]
        res = vulndb.match(items, "wpvulnerability")
        self.assertEqual([m["slug"] for m in res["matches"]], ["elementor", "wordpress"])
        el = res["matches"][0]["vulnerabilities"]
        self.assertEqual(len(el), 1)
        self.assertEqual(el[0]["fixed_in"], "3.18.2")
        self.assertEqual(el[0]["cve"], "CVE-2023-48777")
        self.assertEqual(el[0]["severity"], "critical")
        self.assertEqual(res["matches"][1]["vulnerabilities"][0]["severity"], "medium")
        self.assertIn("https://www.wpvulnerability.net/core/6.4.1/", self.urls)
        n = len(self.urls)
        vulndb.match(items, "wpvulnerability")
        self.assertEqual(len(self.urls), n, "second run is served from the cache")

    def test_unfixed_range_has_no_fix(self):
        res = vulndb.match([{"type": "plugin", "slug": "elementor", "version": "2.5"}], "wpvulnerability")
        hits = {h["id"]: h for h in res["matches"][0]["vulnerabilities"]}
        self.assertEqual(set(hits), {"u1", "u2"})
        hit = hits["u2"]
        self.assertIsNone(hit["fixed_in"])
        self.assertTrue(hit["unfixed"])


class MalwareTest(unittest.TestCase):
    def test_report_strips_the_vhost_root(self):
        with tempfile.TemporaryDirectory() as d:
            report = {"domain": "klant.nl", "scanned_at": "2026-11-06T03:40:00Z", "infected": 1, "findings": [
                {"engine": "yara", "file": "/var/www/vhosts/klant.nl/public/wp-content/uploads/x.php", "signature": "ObfuscatedPhp"}]}
            Path(d, "klant.nl.json").write_text(json.dumps(report))
            with mock.patch.object(sitescan, "MALWARE_DIR", d), mock.patch.object(sitescan, "VHOSTS", "/var/www/vhosts"):
                r = sitescan.malware_report({"domain": "klant.nl"})
                self.assertEqual(r["findings"][0]["file"], "public/wp-content/uploads/x.php")
                self.assertEqual(r["infected"], 1)
                self.assertIsNone(sitescan.malware_report({"domain": "ander.nl"})["scanned_at"])


if __name__ == "__main__":
    unittest.main()
