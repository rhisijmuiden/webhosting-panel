import gzip
import io
import os
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from whp_agent.actions import load_all  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402

load_all()
from whp_agent.actions import server  # noqa: E402

SIMULATION = """Reading package lists...
Inst libssl3t64 [3.5.1-1] (3.5.1-1+deb13u1 Debian-Security:13/stable-security [amd64])
Inst nginx [1.26.3-3] (1.26.3-3+deb13u1 Debian:13.2/stable [amd64])
Inst linux-image-6.12.48+deb13-amd64 (6.12.48-1 Debian-Security:13/stable-security [amd64])
Conf libssl3t64 (3.5.1-1+deb13u1 Debian-Security:13/stable-security [amd64])
"""

CHANGELOG = """openssl (3.5.1-1+deb13u1) trixie-security; urgency=medium

  * Fix CVE-2026-1111 and CVE-2026-2222.
  * Also CVE-2026-1111 again.

 -- Debian <x@debian.org>  Mon, 02 Nov 2026 10:00:00 +0000

openssl (3.5.1-1) unstable; urgency=medium

  * Old fix CVE-2025-9999.
"""


class ServerActionsTest(unittest.TestCase):
    def test_registered_and_no_shutdown(self):
        for name in ["server.status", "server.updates", "server.security_scan", "server.upgrade", "server.apt_status",
                     "server.auto_updates", "server.reboot", "server.reboot_cancel", "server.health",
                     "server.db_optimize", "server.cleanup", "server.php_versions", "server.php_install", "server.php_remove"]:
            self.assertIn(name, ACTIONS)
        # Reinder: rebooting yes, switching the server off never.
        for name in ACTIONS:
            self.assertNotRegex(name, r"shutdown|poweroff|halt|power_off")

    def test_parse_simulation(self):
        pkgs = server.parse_simulation(SIMULATION)
        self.assertEqual([p["name"] for p in pkgs], ["libssl3t64", "nginx", "linux-image-6.12.48+deb13-amd64"])
        self.assertTrue(pkgs[0]["security"])
        self.assertFalse(pkgs[1]["security"])
        self.assertEqual(pkgs[0]["current"], "3.5.1-1")
        self.assertEqual(pkgs[0]["candidate"], "3.5.1-1+deb13u1")
        self.assertTrue(pkgs[2]["new"] and pkgs[2]["kernel"])

    def test_changelog_cves_stop_at_installed(self):
        self.assertEqual(server.changelog_cves(CHANGELOG, "3.5.1-1"), ["CVE-2026-1111", "CVE-2026-2222"])

    def test_deb_changelog(self):
        with tempfile.TemporaryDirectory() as d:
            tar_path = os.path.join(d, "data.tar")
            with tarfile.open(tar_path, "w") as tar:
                data = gzip.compress(CHANGELOG.encode())
                info = tarfile.TarInfo("./usr/share/doc/openssl/changelog.Debian.gz")
                info.size = len(data)
                tar.addfile(info, io.BytesIO(data))
            with mock.patch.object(subprocess, "Popen") as popen:
                popen.return_value = mock.Mock(stdout=open(tar_path, "rb"))
                text = server.deb_changelog("/x.deb", "openssl")
            self.assertIn("CVE-2026-2222", text)

    def test_cvss_cache_and_rate_limit(self):
        with tempfile.TemporaryDirectory() as d, \
                mock.patch.object(server, "CVSS_CACHE", os.path.join(d, "c.json")), \
                mock.patch.object(server, "SERVER_DIR", d), mock.patch.object(server, "APT_LOG_DIR", d), \
                mock.patch.object(server, "_nvd_score", side_effect=[9.8, None]) as nvd:
            sleeps = []
            scores = server.cvss_scores(["CVE-1", "CVE-2"], sleep=sleeps.append)
            self.assertEqual(scores, {"CVE-1": 9.8, "CVE-2": None})
            self.assertEqual(sleeps, [6.5])
            # Cached: no new lookups (the unknown one is retried only after a day).
            self.assertEqual(server.cvss_scores(["CVE-1", "CVE-2"], sleep=sleeps.append), scores)
            self.assertEqual(nvd.call_count, 2)

    def test_security_scan_marks_critical(self):
        pkgs = server.parse_simulation(SIMULATION)
        with mock.patch.object(server, "pending_updates", return_value=pkgs), \
                mock.patch.object(server, "run"), \
                mock.patch.object(server, "_deb_path", return_value="/x.deb"), \
                mock.patch.object(server, "deb_changelog", return_value=CHANGELOG), \
                mock.patch.object(server, "cvss_scores", return_value={"CVE-2026-1111": 9.8, "CVE-2026-2222": 5.0}):
            res = server.security_scan({"threshold": 9.0}, lambda s: None)
        self.assertTrue(res["critical"])
        self.assertEqual(res["max_score"], 9.8)
        self.assertEqual(len(res["packages"]), 2)  # only the security ones

    def test_upgrade_validation_and_conflict(self):
        with self.assertRaises(ActionError):
            server.upgrade({"mode": "dist; reboot"})
        with mock.patch.object(server, "_running_apt_units", return_value=["whp-apt-x.service"]):
            with self.assertRaises(ActionError) as ctx:
                server.upgrade({"mode": "security"})
            self.assertEqual(ctx.exception.code, "conflict")

    def test_start_unit_quotes_every_argument(self):
        with tempfile.TemporaryDirectory() as d, mock.patch.object(server, "APT_LOG_DIR", d), \
                mock.patch.object(server, "SERVER_DIR", d), mock.patch.object(server, "run") as run:
            server.start_unit("a" * 32, [["apt-get", "install", "x'; rm -rf /"]])
            argv = run.call_args[0][0]
        self.assertEqual(argv[0], "systemd-run")
        self.assertIn("whp-apt-" + "a" * 32, argv)
        self.assertEqual(argv[-1], "'apt-get' 'install' 'x'\\''; rm -rf /'")

    def test_start_unit_runs_commands_in_order(self):
        # Run the generated bash script for real (without systemd-run).
        with tempfile.TemporaryDirectory() as d, mock.patch.object(server, "APT_LOG_DIR", d), \
                mock.patch.object(server, "SERVER_DIR", d), mock.patch.object(server, "run") as run:
            server.start_unit("b" * 32, [["echo", "one two"], ["false"], ["echo", "never"]])
            argv = run.call_args[0][0]
            script_at = argv.index("/bin/bash")
            exitfile = [a for a in argv if a.startswith("--setenv=WHP_EXIT=")][0].split("=", 2)[2]
            out = subprocess.run(argv[script_at:], capture_output=True, text=True, env={"WHP_EXIT": exitfile, "PATH": os.environ["PATH"]})
            self.assertIn("one two", out.stdout)
            self.assertNotIn("never", out.stdout)
            with open(exitfile) as fh:
                self.assertEqual(fh.read().strip(), "1")

    def test_unit_status(self):
        with tempfile.TemporaryDirectory() as d, mock.patch.object(server, "APT_LOG_DIR", d), \
                mock.patch.object(server, "run") as run:
            run.return_value = mock.Mock(stdout="inactive\n")
            rid = "c" * 32
            with open(os.path.join(d, rid + ".log"), "w") as fh:
                fh.write("line 1\nline 2\n")
            self.assertEqual(server.unit_status(rid)["status"], "failed")
            with open(os.path.join(d, rid + ".exit"), "w") as fh:
                fh.write("0\n")
            st = server.unit_status(rid)
            self.assertEqual((st["status"], st["log"]), ("succeeded", ["line 1", "line 2"]))
        with self.assertRaises(ActionError):
            server.apt_status({"run": "../../etc/passwd"})

    def test_reboot_schedules_timer(self):
        with tempfile.TemporaryDirectory() as d, mock.patch.object(server, "SERVER_DIR", d), \
                mock.patch.object(server, "APT_LOG_DIR", d), \
                mock.patch.object(server, "REBOOT_MARKER", os.path.join(d, "reboot.json")), \
                mock.patch.object(server, "_running_apt_units", return_value=[]), \
                mock.patch.object(server, "run") as run:
            run.return_value = mock.Mock(stdout="inactive\n")
            res = server.reboot({"delay": 60, "reason": "maintenance"})
            argv = run.call_args[0][0]
            self.assertTrue(res["scheduled"])
            self.assertEqual(argv[:3], ["systemd-run", "--unit", "whp-reboot"])
            self.assertIn("--on-active=60s", argv)
            self.assertEqual(argv[-2:], ["systemctl", "reboot"])
            self.assertTrue(os.path.exists(os.path.join(d, "reboot.json")))
            run.return_value = mock.Mock(stdout="active\n")
            with self.assertRaises(ActionError):
                server.reboot({})
        with self.assertRaises(ActionError):
            server.reboot({"delay": 1})

    def test_reboot_reasons_kernel(self):
        with mock.patch.object(server, "kernels", return_value=("6.12.40", "6.12.48", [])), \
                mock.patch.object(server, "stale_services", return_value=["nginx.service"]), \
                mock.patch.object(server, "REBOOT_FILE", "/nonexistent"):
            kinds = [r["kind"] for r in server.reboot_reasons()]
        self.assertEqual(kinds, ["kernel", "libraries"])

    def test_health(self):
        def fake_run(argv, **kw):
            if argv[0] == "curl":
                code = "502" if "down.nl" in argv[-1] else "200"
                return mock.Mock(stdout=f"{code} 0.120", returncode=0)
            return mock.Mock(stdout="ActiveState=active\nSubState=running\nLoadState=loaded\n")
        with mock.patch.object(server, "run", side_effect=fake_run), mock.patch.object(server.svc, "run", side_effect=fake_run), \
                mock.patch.object(server.svc, "known_services", return_value={"nginx": "Nginx"}):
            res = server.health({"sites": [{"domain": "up.nl", "ssl": True}, {"domain": "down.nl", "ssl": False}]})
        self.assertEqual(res["sites_down"], ["down.nl"])
        self.assertEqual(res["services_down"], [])
        self.assertEqual(res["sites"][0]["ms"], 120)
        with self.assertRaises(ActionError):
            server.health({"sites": [{"domain": "bad domain"}]})

    def test_fragmented_tables(self):
        out = "shop\twp_posts\tInnoDB\t1000000000\t200000000\nshop\tsmall\tInnoDB\t1000\t60000000\nshop\tbig\tInnoDB\t9000000000\t60000000\n"
        with mock.patch.object(server, "_sql", return_value=out):
            tables = server.fragmented_tables(50, 0.1)
        self.assertEqual([t["table"] for t in tables], ["wp_posts", "small"])
        self.assertEqual(server._ident("we`ird"), "`we``ird`")

    def test_prune_tree_does_not_follow_symlinks(self):
        with tempfile.TemporaryDirectory() as d:
            outside = os.path.join(d, "outside")
            os.mkdir(outside)
            victim = os.path.join(outside, "keep.txt")
            open(victim, "w").close()
            root = os.path.join(d, "site", "tmp")
            os.makedirs(os.path.join(root, "sub"))
            old = os.path.join(root, "sub", "old.tmp")
            new = os.path.join(root, "new.tmp")
            for f in (old, new):
                open(f, "w").close()
            past = time.time() - 10 * 86400
            os.utime(old, (past, past))
            os.utime(victim, (past, past))
            os.symlink(outside, os.path.join(root, "link"))
            os.symlink(victim, os.path.join(root, "filelink"))
            self.assertEqual(server._prune_tree(root, 7 * 86400, dry_run=True)[0], 1)
            self.assertTrue(os.path.exists(old))
            count, _ = server._prune_tree(root, 7 * 86400)
            self.assertEqual(count, 1)
            self.assertFalse(os.path.exists(old))
            self.assertTrue(os.path.exists(new))
            self.assertTrue(os.path.exists(victim))

    def test_cleanup_validation(self):
        with self.assertRaises(ActionError):
            server.cleanup_action({"journal_days": -1})

    def test_php_remove_guards(self):
        with tempfile.TemporaryDirectory() as d:
            sites = os.path.join(d, "sites")
            os.mkdir(sites)
            with open(os.path.join(sites, "a.nl.env"), "w") as fh:
                fh.write("SITE_DOMAIN=a.nl\nSITE_PHP=8.3\n")
            facts = {"supported": ["8.3", "8.4", "8.5"], "panel": "8.4", "installed": ["8.3", "8.4"], "configured": ["8.3", "8.4"]}
            with mock.patch.object(server, "SITES_DB", sites), mock.patch.object(server, "php_facts", return_value=facts), \
                    mock.patch.object(server, "_running_apt_units", return_value=[]):
                with self.assertRaises(ActionError) as ctx:
                    server.php_remove({"version": "8.4"})
                self.assertEqual(ctx.exception.code, "forbidden")
                with self.assertRaises(ActionError) as ctx:
                    server.php_remove({"version": "8.3"})
                self.assertEqual(ctx.exception.code, "conflict")
                with self.assertRaises(ActionError):
                    server.php_remove({"version": "8.3; rm"})
                with self.assertRaises(ActionError) as ctx:
                    server.php_install({"version": "7.4"})
                self.assertEqual(ctx.exception.code, "invalid_params")

    def test_php_facts_reads_installer(self):
        with tempfile.TemporaryDirectory() as d:
            sh = os.path.join(d, "install.sh")
            with open(sh, "w") as fh:
                fh.write("SUPPORTED_PHP=(8.3 8.4 8.5)\nPANEL_PHP=8.4\n")
            env = os.path.join(d, "installer.env")
            with open(env, "w") as fh:
                fh.write("WHP_PHP=8.3,8.4\n")
            with mock.patch.object(server, "INSTALL_SH", sh), mock.patch.object(server, "INSTALLER_ENV", env):
                facts = server.php_facts()
                server._env_set(env, "WHP_PHP", "8.4")
                self.assertEqual(server._env_get(env, "WHP_PHP"), "8.4")
        self.assertEqual(facts["supported"], ["8.3", "8.4", "8.5"])
        self.assertEqual(facts["panel"], "8.4")
        self.assertEqual(facts["configured"], ["8.3", "8.4"])

    def test_auto_updates_file(self):
        with tempfile.TemporaryDirectory() as d:
            conf = os.path.join(d, "21whp")
            with mock.patch.object(server, "WINDOW_CONF", conf):
                server.auto_updates({"mode": "window"})
                with open(conf) as fh:
                    self.assertIn('Unattended-Upgrade "0"', fh.read())
                server.auto_updates({"mode": "daily"})
                self.assertFalse(os.path.exists(conf))


if __name__ == "__main__":
    unittest.main()
