"""Node.js and Python apps: unit file, environment, Nginx, ports, runtimes and
the Git deploy hook. systemctl, nginx and whp-site are recorded, not run."""

import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import wordpress  # noqa: E402
from whp_agent.actions import webapps as w  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

APP = {"id": "a1b2c3d4e5", "domain": "klant.nl", "name": "Shop API", "runtime": "node", "version": "24",
       "root": "app", "mount": "/", "start": "npm start", "env": {"API_KEY": 'x"y$z', "DEBUG": "1"},
       "memory_mb": 512, "cpu_percent": 100}


class WebAppsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.site_root = root / "vhosts/klant.nl"
        (self.site_root / "app").mkdir(parents=True)
        self.site = {"domain": "klant.nl", "id": "klant_nl", "user": "s_klant_nl", "php": "8.4", "root": str(self.site_root),
                     "docroot": str(self.site_root / "public"), "slice": "whp-sites-s7.slice", "suspended": False}
        self.runtimes = root / "runtimes"
        (self.runtimes / "node/24/bin").mkdir(parents=True)
        (self.runtimes / "node/24/bin/node").write_text("")
        (self.runtimes / "node/24/.whp-version").write_text("v24.11.1\n")
        (self.runtimes / "python/bin").mkdir(parents=True)
        (self.runtimes / "python/bin/python3.13").write_text("")
        self.calls = []

        def run(argv, **kw):
            self.calls.append(list(argv))
            out = ""
            if argv[:2] == ["systemctl", "show"]:
                out = "ActiveState=active\nSubState=running\nMainPID=4321\nNRestarts=2\nActiveEnterTimestamp=@1793957400\n" \
                      "MemoryCurrent=73400320\nCPUUsageNSec=2500000000\nResult=success\nExecMainStatus=0\nUnitFileState=enabled\nTasksCurrent=11\n"
            if argv[:1] == ["journalctl"]:
                out = "-- Boot abc --\n2026-11-06T10:00:00+01:00 whp-webapp-a1b2c3d4e5[1]: Listening on 30000\n"
            return SimpleNamespace(stdout=out, stderr="", returncode=0)

        self.patches = [
            mock.patch.object(w, "run", run),
            mock.patch.object(w, "RUNTIME_DIR", str(self.runtimes)),
            mock.patch.object(w, "STATE_DIR", str(root / "state")),
            mock.patch.object(w, "ETC_DIR", str(root / "etc")),
            mock.patch.object(w, "SYSTEMD_DIR", str(root / "systemd")),
            mock.patch.object(w, "NGINX_SITES", str(root / "nginx")),
            mock.patch.object(wordpress, "site_info", lambda d: dict(self.site) if d == "klant.nl" else (_ for _ in ()).throw(ActionError("not_found", "x"))),
            mock.patch.object(wordpress, "as_user", lambda site, argv, cwd, **kw: self.calls.append(["as_user", *argv])),
        ]
        for p in self.patches:
            p.start()
        self.root = root

    def tearDown(self):
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    def apply(self, **over):
        return w.webapp_apply({**APP, **over})

    # ------------------------------------------------------------ files

    def test_unit_runs_as_site_user_in_jail_with_limits(self):
        out = self.apply()
        self.assertEqual(out["port"], 30000)
        unit = (self.root / "systemd/whp-webapp-a1b2c3d4e5.service").read_text()
        for line in ("User=s_klant_nl", "Group=s_klant_nl", "Slice=whp-sites-s7.slice", "MemoryMax=512M", "MemoryHigh=460M",
                     "CPUQuota=100%", f"WorkingDirectory={self.site_root}/app", "ProtectSystem=strict",
                     f"ReadWritePaths={self.site_root}", "NoNewPrivileges=yes", "SocketBindAllow=tcp:30000",
                     "SocketBindDeny=any", "Environment=PORT=30000 HOST=127.0.0.1 HOSTNAME=127.0.0.1",
                     'ExecStart=/bin/sh -c "exec npm start"', "Restart=on-failure"):
            self.assertIn(line, unit)
        self.assertIn(f"{self.runtimes}/node/24/bin", unit)
        self.assertIn(f"{self.site_root}/app/node_modules/.bin", unit)
        self.assertIn(["systemctl", "restart", "whp-webapp-a1b2c3d4e5.service"], self.calls)
        # Root mount: the whole site is proxied by whp-site, no snippet.
        self.assertIn(["/usr/local/sbin/whp-site", "proxy", "klant.nl", "30000", "--json"], self.calls)
        self.assertFalse((self.root / "nginx/klant.nl/webapp-a1b2c3d4e5.conf").exists())

    def test_env_file_is_root_only_and_quoted(self):
        self.apply()
        f = self.root / "etc/a1b2c3d4e5.env"
        self.assertEqual(oct(f.stat().st_mode & 0o777), "0o600")
        text = f.read_text()
        self.assertIn('API_KEY="x\\"y\\$z"', text)
        self.assertIn('DEBUG="1"', text)
        state = (self.root / "state/a1b2c3d4e5.json").read_text()
        self.assertNotIn("API_KEY", state)  # secrets only in the 0600 file

    def test_start_command_escaping(self):
        self.apply(start='gunicorn -b "127.0.0.1:$PORT" app:app --log-level 50%', runtime="python", version="3.13")
        unit = (self.root / "systemd/whp-webapp-a1b2c3d4e5.service").read_text()
        self.assertIn('ExecStart=/bin/sh -c "exec gunicorn -b \\"127.0.0.1:$$PORT\\" app:app --log-level 50%%"', unit)
        self.assertIn(f"VIRTUAL_ENV={self.site_root}/app/.venv", unit)
        self.assertIn("PYTHONUNBUFFERED=1", unit)

    def test_sub_path_snippet(self):
        self.apply(mount="/api/", strip_prefix=True)
        snippet = (self.root / "nginx/klant.nl/webapp-a1b2c3d4e5.conf").read_text()
        self.assertIn("location ^~ /api/ {", snippet)
        self.assertIn("proxy_pass http://127.0.0.1:30000/;", snippet)
        self.assertIn("location = /api { return 301 /api/$is_args$args; }", snippet)
        self.assertIn("include /etc/nginx/whp/proxy.conf;", snippet)
        self.assertIn(["nginx", "-t"], self.calls)
        self.assertNotIn("proxy", [c[1] for c in self.calls if c[0].endswith("whp-site")])

    def test_moving_from_root_to_sub_path_turns_site_proxy_off(self):
        self.apply()
        self.calls.clear()
        self.apply(mount="/app")
        self.assertIn(["/usr/local/sbin/whp-site", "proxy", "klant.nl", "off", "--json"], self.calls)
        self.assertTrue((self.root / "nginx/klant.nl/webapp-a1b2c3d4e5.conf").exists())

    def test_disabled_app_is_stopped(self):
        self.apply(enabled=False)
        self.assertIn(["systemctl", "disable", "--now", "whp-webapp-a1b2c3d4e5.service"], self.calls)
        self.assertNotIn(["systemctl", "restart", "whp-webapp-a1b2c3d4e5.service"], self.calls)

    # ------------------------------------------------------------ validation

    def test_input_checks(self):
        bad = [
            {"id": "../etc"}, {"mount": "/a b"}, {"mount": "/../x"}, {"mount": "/.well-known"},
            {"root": "../other"}, {"root": "git/x"}, {"env": {"PORT": "80"}}, {"env": {"1BAD": "x"}},
            {"env": {"OK": "two\nlines"}}, {"runtime": "ruby"}, {"version": "18"}, {"start": ""},
            {"memory_mb": 1}, {"domain": "other.nl"},
        ]
        for over in bad:
            with self.subTest(over=over), self.assertRaises(ActionError):
                self.apply(**over)

    def test_runtime_must_be_installed(self):
        with self.assertRaises(ActionError) as ctx:
            self.apply(version="22")
        self.assertEqual(ctx.exception.code, "conflict")

    def test_ports_are_unique_and_kept(self):
        self.assertEqual(self.apply()["port"], 30000)
        self.assertEqual(self.apply(id="b1b2c3d4e5", mount="/api")["port"], 30001)
        self.assertEqual(self.apply(mount="/")["port"], 30000)  # same app keeps its port

    def test_one_app_per_path(self):
        self.apply()
        with self.assertRaises(ActionError) as ctx:
            self.apply(id="b1b2c3d4e5")
        self.assertEqual(ctx.exception.code, "conflict")

    # ------------------------------------------------------------ status, logs, remove

    def test_status_and_logs(self):
        self.apply()
        st = w.webapp_status({"id": "a1b2c3d4e5"})
        self.assertEqual(st["state"], "running")
        self.assertEqual(st["pid"], 4321)
        self.assertEqual(st["restarts"], 2)
        self.assertEqual(st["memory_bytes"], 73400320)
        self.assertEqual(st["cpu_seconds"], 2.5)
        logs = w.webapp_logs({"id": "a1b2c3d4e5", "lines": 50})
        self.assertEqual(len(logs["lines"]), 1)
        self.assertIn("Listening", logs["lines"][0])
        listed = w.webapp_list({"domain": "klant.nl"})["apps"]
        self.assertIn("a1b2c3d4e5", listed)

    def test_remove(self):
        self.apply(mount="/api")
        w.webapp_remove({"id": "a1b2c3d4e5", "remove_deps": True})
        self.assertFalse((self.root / "systemd/whp-webapp-a1b2c3d4e5.service").exists())
        self.assertFalse((self.root / "etc/a1b2c3d4e5.env").exists())
        self.assertFalse((self.root / "nginx/klant.nl/webapp-a1b2c3d4e5.conf").exists())
        self.assertIsNone(w.read_state("a1b2c3d4e5"))

    def test_suspend_stops_and_starts(self):
        self.apply()
        w.webapp_suspend({"domain": "klant.nl", "suspended": True})
        self.assertIn(["systemctl", "disable", "--now", "whp-webapp-a1b2c3d4e5.service"], self.calls)
        w.webapp_suspend({"domain": "klant.nl", "suspended": False})
        self.assertIn(["systemctl", "enable", "--now", "whp-webapp-a1b2c3d4e5.service"], self.calls)

    # ------------------------------------------------------------ runtimes

    def test_runtime_list(self):
        self.apply()
        with mock.patch.object(w, "_node_index", lambda: {"24": {"version": "v24.12.0", "lts": "Krypton"}}), \
                mock.patch.object(w, "installed_version", lambda r, ver: {"node:24": "v24.11.1", "python:3.13": "3.13.9"}.get(f"{r}:{ver}")):
            out = w.runtime_list({})
        node = {r["version"]: r for r in out["node"]}
        self.assertTrue(node["24"]["installed"])
        self.assertTrue(node["24"]["update_available"])
        self.assertEqual(node["24"]["apps"], 1)
        self.assertFalse(node["26"]["installed"])
        py = {r["version"]: r for r in out["python"]}
        self.assertTrue(py["3.13"]["installed"])

    def test_runtime_in_use_cannot_be_removed(self):
        self.apply()
        with self.assertRaises(ActionError) as ctx:
            w.runtime_remove({"runtime": "node", "version": "24"})
        self.assertEqual(ctx.exception.code, "conflict")

    def test_eol(self):
        self.assertTrue(w.is_eol("node", "22", today="2027-05-01"))
        self.assertFalse(w.is_eol("node", "24", today="2027-05-01"))

    # ------------------------------------------------------------ dependencies and Git

    def test_dependency_commands(self):
        d = self.site_root / "app"
        (d / "package.json").write_text("{}")
        (d / "package-lock.json").write_text("{}")
        cmds = w.dependency_commands({**APP, "build": "npm run build"}, str(d))
        self.assertEqual(cmds[0][1][1:], ["ci", "--no-audit", "--no-fund"])
        self.assertTrue(cmds[0][1][0].endswith("/node/24/bin/npm"))
        self.assertEqual(cmds[1][1], ["/bin/sh", "-ec", "npm run build"])
        (d / "requirements.txt").write_text("flask\n")
        py = w.dependency_commands({**APP, "runtime": "python", "version": "3.13"}, str(d))
        self.assertEqual(py[0][0], "uv venv")
        self.assertIn("--allow-existing", py[0][1])
        self.assertEqual(py[1][1][-2:], ["-r", "requirements.txt"])

    def test_install_runs_as_site_user(self):
        d = self.site_root / "app"
        (d / "package.json").write_text("{}")
        self.apply()
        self.calls.clear()
        log = []
        steps = w.install_deps(self.site, w.read_state("a1b2c3d4e5"), str(d), log.append)
        self.assertEqual(steps[0]["step"], "npm install")
        call = next(c for c in self.calls if c[0] == "/usr/bin/systemd-run")
        self.assertIn("--uid=s_klant_nl", call)
        self.assertIn("--slice=whp-sites-s7.slice", call)
        self.assertIn("MemoryMax=2G", call)
        self.assertIn(f"--working-directory={d}", call)

    def test_git_deploy_hook(self):
        (self.site_root / "app/package.json").write_text("{}")
        self.apply()
        self.apply(id="b1b2c3d4e5", root="other", mount="/other")
        release = self.root / "release"
        release.mkdir()
        (release / "package.json").write_text("{}")
        self.calls.clear()
        log = []
        apps = w.prepare_deploy(self.site, "app", str(release), log.append)
        self.assertEqual([a["id"] for a in apps], ["a1b2c3d4e5"])
        call = next(c for c in self.calls if c[0] == "/usr/bin/systemd-run")
        self.assertIn(f"--working-directory={release}", call)
        w.restart_after_deploy(self.site, apps, log.append)
        self.assertIn(["systemctl", "restart", "whp-webapp-a1b2c3d4e5.service"], self.calls)
        self.assertNotIn(["systemctl", "restart", "whp-webapp-b1b2c3d4e5.service"], self.calls)


if __name__ == "__main__":
    unittest.main()
