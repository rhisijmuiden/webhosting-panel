"""Valkey object cache per website: config and unit files, the small RESP client
(against a fake Valkey on a unix socket), and the WordPress configuration.
systemctl and WP-CLI are recorded, not run."""

import os
import socket
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import wordpress
from whp_agent.actions import objcache as oc
from whp_agent.registry import ActionError

INFO = ("# Server\r\nvalkey_version:8.1.1\r\nuptime_in_seconds:3600\r\n# Memory\r\nused_memory:5242880\r\n"
        "maxmemory:134217728\r\n# Stats\r\nkeyspace_hits:900\r\nkeyspace_misses:100\r\nevicted_keys:3\r\n"
        "# Clients\r\nconnected_clients:2\r\n# Keyspace\r\ndb0:keys=120,expires=10,avg_ttl=0\r\ndb1:keys=30,expires=0,avg_ttl=0\r\n")


class FakeValkey:
    """A unix socket that answers PING, INFO and FLUSHALL like Valkey does."""

    def __init__(self, path):
        os.makedirs(os.path.dirname(path), exist_ok=True)
        self.commands = []
        self.srv = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.srv.bind(path)
        self.srv.listen(5)
        self.thread = threading.Thread(target=self.loop, daemon=True)
        self.thread.start()

    def loop(self):
        while True:
            try:
                conn, _ = self.srv.accept()
            except OSError:
                return
            data = conn.recv(65536).decode()
            parts = [p for p in data.split("\r\n") if p and p[0] not in "*$"]
            self.commands.append(parts)
            cmd = parts[0].upper() if parts else ""
            if cmd == "PING":
                conn.sendall(b"+PONG\r\n")
            elif cmd == "INFO":
                body = INFO.encode()
                conn.sendall(b"$" + str(len(body)).encode() + b"\r\n" + body + b"\r\n")
            elif cmd == "FLUSHALL":
                conn.sendall(b"+OK\r\n")
            else:
                conn.sendall(b"-NOPERM this user has no permissions\r\n")
            conn.close()

    def close(self):
        self.srv.close()


class ObjectCacheTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.root = root
        self.site_root = root / "vhosts/klant.nl"
        (self.site_root / "public").mkdir(parents=True)
        self.site = {"domain": "klant.nl", "id": "klant_nl", "user": "s_klant_nl", "php": "8.4", "root": str(self.site_root),
                     "docroot": str(self.site_root / "public"), "slice": "whp-sites-s7.slice", "suspended": False}
        self.calls = []
        self.active = "active"

        def run(argv, **kw):
            self.calls.append(list(argv))
            out = ""
            if argv[:2] == ["systemctl", "show"]:
                out = f"ActiveState={self.active}\nSubState=running\nUnitFileState=enabled\nMemoryCurrent=9437184\n"
            return SimpleNamespace(stdout=out, stderr="", returncode=0)

        def wp(domain, args, path="", **kw):
            self.calls.append(["wp", path, *args])
            if args[:2] == ["redis", "enable"]:
                d = Path(wordpress.install_dir(self.site, path)) / "wp-content/object-cache.php"
                d.write_text("<?php\n/*\n * Plugin Name: Redis Object Cache Drop-In\n */\n")
            if args[:2] == ["plugin", "install"]:
                (Path(wordpress.install_dir(self.site, path)) / "wp-content/plugins/redis-cache").mkdir(parents=True)
            return SimpleNamespace(stdout="", stderr="", returncode=0)

        self.patches = [
            mock.patch.object(oc, "run", run),
            mock.patch.object(oc, "SYSTEMD_DIR", str(root / "systemd")),
            mock.patch.object(oc, "ETC_DIR", str(root / "etc")),
            mock.patch.object(oc, "STATE_DIR", str(root / "state")),
            mock.patch.object(oc, "RUN_DIR", str(root / "run")),
            mock.patch.object(oc.time, "sleep", lambda s: None),
            mock.patch.object(wordpress, "site_info", lambda d: dict(self.site) if d == "klant.nl" else (_ for _ in ()).throw(ActionError("not_found", "x"))),
            mock.patch.object(wordpress, "wp", wp),
            mock.patch.object(wordpress, "as_user", lambda site, argv, cwd, **kw: self.calls.append(["as_user", *argv])),
        ]
        for p in self.patches:
            p.start()
        self.valkey = FakeValkey(str(root / "run/s_klant_nl/valkey.sock"))

    def tearDown(self):
        self.valkey.close()
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    def make_wp(self, rel="", woo=False):
        d = Path(self.site["docroot"]) / rel
        (d / "wp-includes").mkdir(parents=True)
        (d / "wp-content/plugins").mkdir(parents=True)
        (d / "wp-config.php").write_text("<?php\n")
        (d / "wp-includes/version.php").write_text("<?php\n$wp_version = '6.8.3';\n")
        if woo:
            (d / "wp-content/plugins/woocommerce").mkdir()
        return d

    # ------------------------------------------------------------ files

    def test_own_server_per_site_on_a_unix_socket_in_the_jail(self):
        out = oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 128, "wordpress": False})
        unit = (self.root / "systemd/whp-objcache-s_klant_nl.service").read_text()
        for line in ("User=s_klant_nl", "Group=s_klant_nl", "Slice=whp-sites-s7.slice", "Type=notify",
                     "RuntimeDirectory=whp-objcache/s_klant_nl", "RuntimeDirectoryMode=0700", "MemoryMax=160M",
                     "PrivateNetwork=yes", "RestrictAddressFamilies=AF_UNIX", "ProtectSystem=strict", "NoNewPrivileges=yes",
                     f"ExecStart={oc.VALKEY_SERVER} {self.root}/etc/s_klant_nl.conf"):
            self.assertIn(line, unit.splitlines())
        conf = (self.root / "etc/s_klant_nl.conf").read_text().splitlines()
        for line in ("port 0", f"unixsocket {self.root}/run/s_klant_nl/valkey.sock", "unixsocketperm 600", "maxmemory 128mb",
                     "maxmemory-policy allkeys-lru", 'save ""', "appendonly no", "user default on nopass ~* &* +@all -@admin"):
            self.assertIn(line, conf)
        self.assertEqual(oct(os.stat(self.root / "etc/s_klant_nl.conf").st_mode & 0o777), "0o640")
        self.assertIn(["systemctl", "enable", "whp-objcache-s_klant_nl.service"], self.calls)
        self.assertIn(["systemctl", "restart", "whp-objcache-s_klant_nl.service"], self.calls)
        self.assertEqual(out["state"], "running")
        self.assertEqual(out["stats"]["keys"], 150)
        self.assertEqual(out["stats"]["hit_ratio"], 90.0)
        self.assertEqual(out["stats"]["maxmemory_bytes"], 128 * 1024 * 1024)

    def test_memory_bounds(self):
        with self.assertRaises(ActionError):
            oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 4})
        self.assertEqual(oc.memory_cap(64), 96)
        self.assertEqual(oc.memory_cap(1024), 1280)

    def test_unknown_site_and_bad_user(self):
        with self.assertRaises(ActionError):
            oc.objcache_apply({"domain": "other.nl", "enabled": True, "memory_mb": 64})
        self.site["user"] = "root"
        with self.assertRaises(ActionError):
            oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 64})

    def test_disable_stops_and_keeps_state(self):
        oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 64, "wordpress": False})
        out = oc.objcache_apply({"domain": "klant.nl", "enabled": False})
        self.assertIn(["systemctl", "disable", "--now", "whp-objcache-s_klant_nl.service"], self.calls)
        self.assertFalse(out["enabled"])

    def test_suspended_site_does_not_start(self):
        self.site["suspended"] = True
        oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 64})
        self.assertNotIn(["systemctl", "restart", "whp-objcache-s_klant_nl.service"], self.calls)
        self.assertIn(["systemctl", "disable", "--now", "whp-objcache-s_klant_nl.service"], self.calls)

    # ------------------------------------------------------------ talking to Valkey

    def test_flush_and_status(self):
        oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 64, "wordpress": False})
        out = oc.objcache_flush({"domain": "klant.nl"})
        self.assertTrue(out["flushed"])
        self.assertEqual(out["keys_removed"], 150)
        self.assertIn(["FLUSHALL"], self.valkey.commands)
        listed = oc.objcache_list({})
        self.assertEqual(list(listed["sites"]), ["klant.nl"])

    def test_flush_when_off(self):
        with self.assertRaises(ActionError):
            oc.objcache_flush({"domain": "klant.nl"})

    def test_error_reply_is_an_action_error(self):
        with self.assertRaises(ActionError) as e:
            oc.command("s_klant_nl", "CONFIG", "SET", "maxmemory", "0")
        self.assertIn("NOPERM", e.exception.message)

    def test_parse_info(self):
        info = oc.parse_info(INFO)
        self.assertEqual(info["valkey_version"], "8.1.1")
        self.assertEqual(info["db1"], "keys=30,expires=0,avg_ttl=0")

    # ------------------------------------------------------------ WordPress

    def test_wordpress_configured_with_socket_and_plugin(self):
        self.make_wp(woo=True)
        self.make_wp("blog")
        out = oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 64})
        sock = f"{self.root}/run/s_klant_nl/valkey.sock"
        self.assertIn(["wp", "", "config", "set", "WP_REDIS_PATH", sock, "--type=constant"], self.calls)
        self.assertIn(["wp", "", "config", "set", "WP_REDIS_SCHEME", "unix", "--type=constant"], self.calls)
        self.assertIn(["wp", "", "config", "set", "WP_REDIS_DATABASE", "0", "--type=constant", "--raw"], self.calls)
        self.assertIn(["wp", "blog", "config", "set", "WP_REDIS_DATABASE", "1", "--type=constant", "--raw"], self.calls)
        self.assertIn(["wp", "blog", "config", "set", "WP_REDIS_PREFIX", "blog:", "--type=constant"], self.calls)
        self.assertIn(["wp", "", "plugin", "install", "redis-cache", "--activate"], self.calls)
        self.assertIn(["wp", "", "redis", "enable"], self.calls)
        rows = {r["path"]: r for r in out["wordpress"]}
        self.assertTrue(rows[""]["configured"])
        self.assertTrue(rows[""]["woocommerce"])
        self.assertFalse(rows["blog"]["woocommerce"])

    def test_foreign_dropin_is_left_alone(self):
        d = self.make_wp()
        (d / "wp-content/object-cache.php").write_text("<?php // W3 Total Cache\n")
        out = oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 64})
        self.assertFalse(out["wordpress"][0]["configured"])
        self.assertIn("drop-in", out["wordpress"][0]["error"])
        self.assertFalse(any(c[:3] == ["wp", "", "redis"] for c in self.calls))

    def test_disable_unconfigures_wordpress_before_stopping(self):
        self.make_wp()
        oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 64})
        self.calls.clear()
        oc.objcache_apply({"domain": "klant.nl", "enabled": False})
        disable = self.calls.index(["wp", "", "redis", "disable"])
        stop = self.calls.index(["systemctl", "disable", "--now", "whp-objcache-s_klant_nl.service"])
        self.assertLess(disable, stop)
        self.assertIn(["wp", "", "config", "delete", "WP_REDIS_PATH", "--type=constant"], self.calls)

    def test_remove_deletes_files(self):
        oc.objcache_apply({"domain": "klant.nl", "enabled": True, "memory_mb": 64, "wordpress": False})
        out = oc.objcache_remove({"domain": "klant.nl"})
        self.assertTrue(out["removed"])
        self.assertFalse((self.root / "systemd/whp-objcache-s_klant_nl.service").exists())
        self.assertFalse((self.root / "etc/s_klant_nl.conf").exists())
        self.assertIsNone(oc.read_state("klant.nl"))


if __name__ == "__main__":
    unittest.main()
