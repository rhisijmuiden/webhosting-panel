import os
import sys
import tempfile
import time
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from whp_agent import diskscan, jobs, paths, wordpress  # noqa: E402
from whp_agent.actions import databases, disk_usage  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402


def write(path, size):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "wb") as fh:
        fh.write(os.urandom(size))


class DiskScanTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = os.path.join(self.tmp.name, "site")
        write(f"{self.root}/public/index.php", 100)
        write(f"{self.root}/public/wp-content/uploads/2026/big.jpg", 300_000)
        write(f"{self.root}/public/wp-content/uploads/2026/small.jpg", 5_000)
        write(f"{self.root}/logs/access.log", 120_000)
        write(f"{self.tmp.name}/outside/secret.bin", 900_000)

    def test_sizes_add_up_and_largest_files_are_listed(self):
        result = diskscan.scan(self.root)
        tree = result["tree"]
        self.assertEqual(tree["f"], 4)
        self.assertGreaterEqual(tree["s"], 425_100)
        names = [c["n"] for c in tree["c"]]
        self.assertEqual(names, ["public", "logs"], "largest folder first")
        public = tree["c"][0]
        self.assertEqual(public["fc"], 1)
        self.assertEqual(result["largest"][0]["path"], "public/wp-content/uploads/2026/big.jpg")
        self.assertEqual(public["c"][0]["c"][0]["c"][0]["top"][0][0], "big.jpg")
        self.assertFalse(result["partial"])

    def test_symlinks_are_not_followed_and_hard_links_count_once(self):
        os.symlink(f"{self.tmp.name}/outside", f"{self.root}/public/link")
        os.link(f"{self.root}/logs/access.log", f"{self.root}/logs/access.log.1")
        tree = diskscan.scan(self.root)["tree"]
        self.assertLess(tree["s"], 900_000, "the folder behind the symlink is not counted")
        logs = next(c for c in tree["c"] if c["n"] == "logs")
        self.assertLess(logs["fs"], 2 * 120_000)
        self.assertEqual(logs["fc"], 2)

    def test_the_tree_is_pruned(self):
        for i in range(70):
            write(f"{self.root}/many/d{i:02d}/f", 4096 * (i + 1))
        with mock.patch.object(diskscan, "MAX_CHILDREN", 10):
            tree = diskscan.scan(self.root)["tree"]
        many = next(c for c in tree["c"] if c["n"] == "many")
        self.assertEqual(len(many["c"]), 10)
        self.assertEqual(many["o"][1], 60)
        self.assertEqual(many["c"][0]["n"], "d69")
        self.assertEqual(many["d"], 70)

        node = {"n": "", "s": 10, "c": [{"n": "a", "s": 6, "c": [{"n": "a1", "s": 5, "c": []}]}, {"n": "b", "s": 4, "c": []}]}
        diskscan.limit_nodes(node, 2)
        self.assertEqual([c["n"] for c in node["c"]], ["a"])
        self.assertEqual(node["o"], [4, 1])

    def test_depth_limit_keeps_sizes(self):
        write(f"{self.root}/" + "/".join(f"l{i}" for i in range(14)) + "/deep.bin", 50_000)
        with mock.patch.object(diskscan, "MAX_DEPTH", 3):
            tree = diskscan.scan(self.root)["tree"]
        node = next(c for c in tree["c"] if c["n"] == "l0")
        self.assertGreaterEqual(node["s"], 50_000)
        while node["c"]:
            node = node["c"][0]
        self.assertTrue(node.get("x"))


class DiskUsageActionsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        sites_db = os.path.join(self.tmp.name, "sites")
        os.makedirs(sites_db)
        with open(os.path.join(sites_db, "klant.nl.env"), "w") as fh:
            fh.write('SITE_USER="s_klant_nl"\nSITE_DOCROOT_REL="public"\n')
        vhosts = os.path.join(self.tmp.name, "vhosts")
        write(f"{vhosts}/klant.nl/public/wp-content/uploads/a.jpg", 200_000)
        write(f"{vhosts}/klant.nl/public/index.php", 10)
        for patcher in [
            mock.patch.object(wordpress, "SITES_DB", sites_db),
            mock.patch.dict(os.environ, {"WHP_VHOSTS": vhosts, "WHP_DISK_USAGE_DIR": os.path.join(self.tmp.name, "cache")}),
            mock.patch.object(paths, "JOBS_DIR", os.path.join(self.tmp.name, "jobs")),
            mock.patch.object(disk_usage, "scanner_argv", lambda root: [sys.executable, disk_usage.SCANNER, root]),
            mock.patch.object(databases, "_sizes", lambda: {"klant_wp": 4_000_000}),
        ]:
            patcher.start()
            self.addCleanup(patcher.stop)

    def wait(self, job_id):
        for _ in range(200):
            job = jobs.read(job_id)
            if job["status"] != "running":
                return job
            time.sleep(0.05)
        self.fail("job did not finish")

    def test_registered(self):
        for name in ["disk.scan", "disk.scan_all", "disk.usage", "disk.summary"]:
            self.assertIn(name, ACTIONS)

    def test_scan_then_drill_down(self):
        self.assertIsNone(disk_usage.disk_usage({"domain": "klant.nl"})["node"])
        job = disk_usage.disk_scan({"domain": "klant.nl"})
        self.assertEqual(self.wait(job["job"])["status"], "succeeded")

        top = disk_usage.disk_usage({"domain": "klant.nl"})
        self.assertGreaterEqual(top["total_bytes"], 200_000)
        self.assertEqual(top["node"]["children"][0]["name"], "public")
        self.assertEqual(top["largest"][0]["path"], "public/wp-content/uploads/a.jpg")

        sub = disk_usage.disk_usage({"domain": "klant.nl", "path": "/public/wp-content/"})
        self.assertTrue(sub["found"])
        self.assertEqual(sub["node"]["path"], "public/wp-content")
        self.assertEqual(sub["node"]["children"][0]["name"], "uploads")
        self.assertNotIn("largest", sub)

        missing = disk_usage.disk_usage({"domain": "klant.nl", "path": "public/nope"})
        self.assertFalse(missing["found"])
        self.assertEqual(missing["node"]["path"], "public")

        summary = disk_usage.disk_summary({})
        self.assertIn("klant.nl", summary["sites"])
        self.assertEqual(summary["databases"], {"klant_wp": 4_000_000})

    def test_scan_all_skips_fresh_results(self):
        job = disk_usage.disk_scan_all({"max_age_hours": 0})
        self.assertEqual(self.wait(job["job"])["result"]["scanned"], ["klant.nl"])
        job = disk_usage.disk_scan_all({})
        self.assertEqual(self.wait(job["job"])["result"]["skipped"], 1)

    def test_rejects_bad_input(self):
        for params in [{"domain": "klant.nl", "path": "../etc"}, {"domain": "klant.nl", "path": "a/../../b"},
                       {"domain": "klant.nl; rm"}, {"domain": "klant.nl", "path": 5}]:
            with self.assertRaises(ActionError):
                disk_usage.disk_usage(params)
        with self.assertRaises(ActionError) as ctx:
            disk_usage.disk_scan({"domain": "onbekend.nl"})
        self.assertEqual(ctx.exception.code, "not_found")


if __name__ == "__main__":
    unittest.main()
