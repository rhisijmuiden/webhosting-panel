import gzip
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.append(str(HERE / "stubs"))

from whp_agent import wordpress  # noqa: E402
from whp_agent.actions import sitelogs  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

DOMAIN = "klant.nl"


def access(ip="203.0.113.5", path="/", status=200, ua="Mozilla/5.0", t="07/Nov/2026:01:02:03 +0100", method="GET"):
    return f'{ip} - - [{t}] "{method} {path} HTTP/2.0" {status} 512 "-" "{ua}" rt=0.012 cache=HIT\n'


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.vhosts = root / "vhosts"
        self.logs = self.vhosts / DOMAIN / "logs"
        self.logs.mkdir(parents=True)
        self.fpm = root / "fpm"
        self.fpm.mkdir()
        for p in (
            mock.patch.object(sitelogs, "VHOSTS", str(self.vhosts)),
            mock.patch.object(sitelogs, "FPM_DIR", str(self.fpm)),
            mock.patch.object(wordpress, "site_info", side_effect=self.site_info),
        ):
            p.start()
            self.addCleanup(p.stop)
        self.addCleanup(self.tmp.cleanup)

    @staticmethod
    def site_info(domain):
        if domain != DOMAIN:
            raise ActionError("not_found", f"Unknown website: {domain}")
        return {"domain": domain, "id": "klant_nl", "user": "s-klant"}

    def write(self, name, text, folder=None):
        f = (folder or self.logs) / name
        f.write_text(text)
        return f

    def read(self, **params):
        return sitelogs.sitelog_read({"domain": DOMAIN, **params})


class ParseTest(unittest.TestCase):
    def test_access_line(self):
        e = sitelogs.parse_access(access(path="/wp-login.php?x=1", status=404).rstrip("\n"))
        self.assertEqual((e["ip"], e["method"], e["path"], e["status"], e["bytes"]), ("203.0.113.5", "GET", "/wp-login.php?x=1", 404, 512))
        self.assertEqual(e["time"], "2026-11-07T01:02:03+01:00")
        self.assertEqual((e["duration"], e["cache"], e["agent"], e["referer"]), (0.012, "HIT", "Mozilla/5.0", None))

    def test_access_escaped_quotes_and_garbage(self):
        e = sitelogs.parse_access('1.2.3.4 - - [07/Nov/2026:01:02:03 +0000] "\\x16\\x03" 400 0 "-" "a \\"b\\"" rt=- cache=-')
        self.assertEqual((e["status"], e["method"], e["agent"], e["duration"], e["cache"]), (400, None, 'a "b"', None, None))
        self.assertEqual(sitelogs.parse_access("not a log line"), {"raw": "not a log line"})

    def test_nginx_error(self):
        e = sitelogs.parse_nginx_error(
            '2026/11/07 01:02:03 [error] 123#123: *45 open() "/var/www/x/favicon.ico" failed (2: No such file or directory), '
            'client: 198.51.100.7, server: klant.nl, request: "GET /favicon.ico HTTP/2.0", host: "klant.nl"')
        self.assertEqual((e["level"], e["ip"], e["request"]), ("error", "198.51.100.7", "GET /favicon.ico HTTP/2.0"))
        self.assertTrue(e["message"].startswith("open()"))
        self.assertEqual(e["time"], "2026-11-07T01:02:03")
        self.assertEqual(sitelogs.parse_nginx_error("2026/11/07 01:02:03 [warn] 1#1: an upstream response is buffered")["level"], "warning")

    def test_php_with_stack_trace(self):
        e = sitelogs.parse_php("[07-Nov-2026 00:02:03 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function x() in /var/www/vhosts/klant.nl/public/index.php:3\nStack trace:\n#0 {main}")
        self.assertEqual((e["level"], e["file"], e["line"]), ("error", "/var/www/vhosts/klant.nl/public/index.php", 3))
        self.assertEqual(e["time"], "2026-11-07T00:02:03+00:00")
        self.assertIn("#0 {main}", e["message"])
        w = sitelogs.parse_php("[07-Nov-2026 00:02:03 Europe/Amsterdam] PHP Warning:  Undefined variable $a in /x/a.php on line 9")
        self.assertEqual((w["level"], w["line"]), ("warning", 9))
        self.assertEqual(sitelogs.parse_php("[07-Nov-2026 00:02:03 UTC] PHP Deprecated:  x")["level"], "deprecated")

    def test_fpm(self):
        e = sitelogs.parse_fpm('[07-Nov-2026 01:02:03] WARNING: [pool site-klant_nl] server reached pm.max_children setting (5)')
        self.assertEqual(e["level"], "warning")
        self.assertIn("max_children", e["message"])


class ReadTest(Base):
    def test_newest_lines_oldest_first(self):
        self.write("access.log", "".join(access(path=f"/p{i}") for i in range(50)))
        r = self.read(log="access", lines=5)
        self.assertEqual([e["path"] for e in r["entries"]], [f"/p{i}" for i in range(45, 50)])
        self.assertTrue(r["exists"])
        self.assertEqual(r["cursor"]["offset"], r["size"])

    def test_large_file_reads_across_blocks(self):
        lines = [access(path=f"/{i}" + "x" * 300) for i in range(1000)]
        self.write("access.log", "".join(lines))
        r = self.read(log="access", lines=1000)
        self.assertEqual(len(r["entries"]), 1000)
        self.assertTrue(r["entries"][0]["path"].startswith("/0x"))

    def test_filters(self):
        self.write("access.log", access(status=200) + access(status=404, path="/missing") + access(status=503, ip="198.51.100.1")
                   + access(status=301, method="POST", ua="curl/8"))
        self.assertEqual([e["status"] for e in self.read(log="access", status=["4xx", "5xx"])["entries"]], [404, 503])
        self.assertEqual([e["status"] for e in self.read(log="access", ip="198.51.100.1")["entries"]], [503])
        self.assertEqual([e["status"] for e in self.read(log="access", q="MISSING")["entries"]], [404])
        self.assertEqual(len(self.read(log="access", q="-curl")["entries"]), 3)
        self.assertEqual([e["status"] for e in self.read(log="access", method="post")["entries"]], [301])
        self.assertTrue(self.read(log="access", q="x")["filtered"])
        with self.assertRaises(ActionError):
            self.read(log="access", status=["6xx"])

    def test_php_entries_keep_their_stack_trace_and_filter_on_level(self):
        self.write("php-error.log",
                   "[07-Nov-2026 00:00:01 UTC] PHP Warning:  a in /x.php on line 1\n"
                   "[07-Nov-2026 00:00:02 UTC] PHP Fatal error:  Uncaught Exception: boom in /y.php:2\nStack trace:\n#0 {main}\n  thrown in /y.php on line 2\n"
                   "[07-Nov-2026 00:00:03 UTC] PHP Notice:  c in /z.php on line 3\n")
        r = self.read(log="php")
        self.assertEqual([e["level"] for e in r["entries"]], ["warning", "error", "notice"])
        self.assertIn("thrown in /y.php", r["entries"][1]["message"])
        self.assertEqual([e["level"] for e in self.read(log="php", levels=["error"])["entries"]], ["error"])
        self.assertEqual(len(self.read(log="php", q="stack")["entries"]), 1)

    def test_live_tail_and_rotation(self):
        f = self.write("access.log", access(path="/a"))
        cursor = self.read(log="access")["cursor"]
        with open(f, "a") as fh:
            fh.write(access(path="/b") + access(path="/c") + '203.0.113.5 - - [half')
        r = self.read(log="access", after=cursor)
        self.assertEqual([e["path"] for e in r["entries"]], ["/b", "/c"])
        self.assertFalse(r["rotated"])
        again = self.read(log="access", after=r["cursor"])
        self.assertEqual(again["entries"], [])
        os.rename(f, self.logs / "access.log.1")
        self.write("access.log", access(path="/new"))
        r = self.read(log="access", after=again["cursor"])
        self.assertTrue(r["rotated"])
        self.assertEqual([e["path"] for e in r["entries"]], ["/new"])

    def test_missing_log(self):
        r = self.read(log="error")
        self.assertEqual((r["exists"], r["entries"]), (False, []))
        self.assertEqual(self.read(log="error", after={"inode": 0, "offset": 0})["cursor"], {"inode": 0, "offset": 0})

    def test_rotated_and_compressed_copies(self):
        self.write("access.log", access(path="/now"))
        self.write("access.log.1", access(path="/yesterday"))
        with gzip.open(self.logs / "access.log.2.gz", "wt") as gz:
            gz.write("".join(access(path=f"/old{i}", status=500 if i % 2 else 200) for i in range(10)))
        self.write("error.log.1", "x")
        files = sitelogs.sitelog_files({"domain": DOMAIN})["logs"]
        self.assertEqual([f["name"] for f in files["access"]], ["access.log", "access.log.1", "access.log.2.gz"])
        self.assertTrue(files["access"][2]["compressed"])
        self.assertEqual([f["name"] for f in files["error"]], ["error.log.1"])
        r = self.read(log="access", file="access.log.2.gz", lines=3, status=["5xx"])
        self.assertEqual([e["path"] for e in r["entries"]], ["/old5", "/old7", "/old9"])
        self.assertEqual(self.read(log="access", file="access.log.1")["entries"][0]["path"], "/yesterday")
        with self.assertRaises(ActionError):
            self.read(log="access", file="access.log.1", after={"inode": 1, "offset": 0})

    def test_fpm_log(self):
        self.write("klant_nl.log", "[07-Nov-2026 01:02:03] NOTICE: ready to handle connections\n", folder=self.fpm)
        r = self.read(log="fpm")
        self.assertEqual(r["entries"][0]["level"], "notice")
        self.assertEqual(sitelogs.sitelog_files({"domain": DOMAIN})["logs"]["fpm"][0]["name"], "klant_nl.log")


class SafetyTest(Base):
    def test_symlinked_log_is_refused(self):
        secret = Path(self.tmp.name) / "shadow"
        secret.write_text("root:secret\n")
        os.symlink(secret, self.logs / "access.log")
        with self.assertRaises(ActionError) as e:
            self.read(log="access")
        self.assertEqual(e.exception.code, "forbidden")
        self.assertEqual(sitelogs.sitelog_files({"domain": DOMAIN})["logs"]["access"], [])

    def test_symlinked_logs_folder_is_refused(self):
        other = Path(self.tmp.name) / "etc"
        other.mkdir()
        (other / "access.log").write_text(access())
        os.rename(self.logs, Path(self.tmp.name) / "old-logs")
        os.symlink(other, self.logs)
        with self.assertRaises(ActionError) as e:
            self.read(log="access")
        self.assertEqual(e.exception.code, "forbidden")

    def test_hard_link_is_refused(self):
        secret = Path(self.tmp.name) / "secret"
        secret.write_text("x\n")
        os.link(secret, self.logs / "error.log")
        with self.assertRaises(ActionError):
            self.read(log="error")

    def test_fifo_does_not_hang(self):
        os.mkfifo(self.logs / "php-error.log")
        with self.assertRaises(ActionError):
            self.read(log="php")

    def test_bad_params(self):
        for params in ({"log": "auth"}, {"log": "access", "file": "../../etc/passwd"}, {"log": "access", "file": "error.log.1"},
                       {"log": "access", "lines": 5000}, {"log": "access", "after": {"inode": "1", "offset": 0}}):
            with self.assertRaises(ActionError, msg=params):
                self.read(**params)
        with self.assertRaises(ActionError) as e:
            sitelogs.sitelog_read({"domain": "other.nl", "log": "access"})
        self.assertEqual(e.exception.code, "not_found")


if __name__ == "__main__":
    unittest.main()
