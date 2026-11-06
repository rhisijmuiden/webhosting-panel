"""Client file manager: the worker's jail, archive safety and the spool handoff.
The worker runs for real here (as the test user instead of the site user)."""

import io
import json
import os
import sys
import tarfile
import tempfile
import time
import unittest
import zipfile
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths, wordpress  # noqa: E402
from whp_agent.actions import files  # noqa: E402
from whp_agent.registry import ActionError, dispatch  # noqa: E402


class FilesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        self.vhosts = base / "vhosts"
        self.root = self.vhosts / "klant.nl"
        for d in ("public", "logs", "tmp", "sessions"):
            (self.root / d).mkdir(parents=True)
        (self.root / "public/index.php").write_text("<?php echo 'hoi';\n")
        (base / "outside").mkdir()
        (base / "outside/secret.txt").write_text("geheim")
        sites = base / "sites"
        sites.mkdir()
        (sites / "klant.nl.env").write_text('SITE_ID="klant_nl"\nSITE_USER="s_klant_nl"\nSITE_PHP="8.4"\nSITE_DOCROOT_REL="public"\n')
        self.spool = base / "spool"
        self.spool.mkdir()
        uid = os.getuid()
        patches = [
            mock.patch.dict(os.environ, {"WHP_VHOSTS": str(self.vhosts)}),
            mock.patch.object(wordpress, "SITES_DB", str(sites)),
            mock.patch.object(files, "SPOOL_DIR", str(self.spool)),
            mock.patch.object(files, "_identity", lambda site: {}),
            mock.patch.object(files, "_panel_uid", lambda: uid),
            mock.patch.object(files.pwd, "getpwnam", lambda n: SimpleNamespace(pw_uid=1000 if n.startswith("s_") else uid, pw_gid=1000)),
            mock.patch.object(files, "PYTHON", sys.executable),
            mock.patch.object(paths, "JOBS_DIR", str(base / "jobs")),
        ]
        for p in patches:
            p.start()
            self.addCleanup(p.stop)
        self.addCleanup(self.tmp.cleanup)

    def call(self, _action, **params):
        return dispatch(_action, {"domain": "klant.nl", **params})

    def spool_in(self, data):
        name = "up-" + os.urandom(16).hex()
        (self.spool / name).write_bytes(data)
        return name

    def wait(self, job):
        for _ in range(100):
            j = jobs.read(job["job"])
            if j["status"] != "running":
                return j
            time.sleep(0.05)
        self.fail("job did not finish")

    def test_list_marks_protected_folders(self):
        data = self.call("files.list", path="")
        names = [e["name"] for e in data["entries"]]
        self.assertEqual(names[:4], ["logs", "public", "sessions", "tmp"])
        self.assertIn("public", data["protected"])
        self.assertEqual(data["docroot"], "public")
        sub = self.call("files.list", path="public")
        self.assertEqual(sub["entries"][0]["name"], "index.php")

    def test_jail_refuses_traversal_and_links_outside(self):
        for bad in ("../outside", "public/../../outside/secret.txt", "/../../etc/passwd"):
            with self.assertRaises(ActionError) as cm:
                self.call("files.list", path=bad) if "secret" not in bad else self.call("files.read", path=bad, spool="rd-" + "0" * 32)
            self.assertIn(cm.exception.code, ("forbidden", "not_found", "invalid_params"))
        # "/etc" is read relative to the site root, never as the real /etc.
        with self.assertRaises(ActionError) as cm:
            self.call("files.list", path="/etc")
        self.assertEqual(cm.exception.code, "not_found")
        os.symlink(str(Path(self.tmp.name) / "outside"), self.root / "public/escape")
        with self.assertRaises(ActionError) as cm:
            self.call("files.list", path="public/escape")
        self.assertEqual(cm.exception.code, "forbidden")
        with self.assertRaises(ActionError):
            self.call("files.read", path="public/escape/secret.txt", spool="rd-" + "1" * 32)
        # The link itself can be removed, its target stays.
        self.call("files.delete", paths=["public/escape"])
        self.assertTrue((Path(self.tmp.name) / "outside/secret.txt").exists())

    def test_read_and_write_with_conflict_check(self):
        name = "rd-" + "a" * 32
        meta = self.call("files.read", path="public/index.php", spool=name)
        self.assertEqual((self.spool / name).read_text(), "<?php echo 'hoi';\n")
        os.chmod(self.root / "public/index.php", 0o640)
        self.call("files.write", path="public/index.php", spool=self.spool_in(b"<?php echo 'nieuw';\n"),
                  expected_mtime=meta["mtime"])
        self.assertEqual((self.root / "public/index.php").read_text(), "<?php echo 'nieuw';\n")
        self.assertEqual(oct(os.stat(self.root / "public/index.php").st_mode & 0o777), "0o640")
        self.assertEqual(list(self.spool.glob("up-*")), [], "uploaded spool file is removed")
        with self.assertRaises(ActionError) as cm:
            self.call("files.write", path="public/index.php", spool=self.spool_in(b"x"), expected_mtime=meta["mtime"])
        self.assertEqual(cm.exception.code, "conflict")
        (self.root / "public/logo.png").write_bytes(b"\x89PNG\r\n\x00\x00")
        with self.assertRaises(ActionError) as cm:
            self.call("files.read", path="public/logo.png", spool="rd-" + "b" * 32)
        self.assertEqual(cm.exception.details["reason"], "binary")
        self.assertFalse((self.spool / ("rd-" + "b" * 32)).exists(), "failed read leaves no spool file")

    def test_spool_must_be_plain_file(self):
        target = Path(self.tmp.name) / "outside/secret.txt"
        name = "up-" + "c" * 32
        os.symlink(target, self.spool / name)
        with self.assertRaises(OSError):
            self.call("files.upload", dir="public", name="x.txt", spool=name)
        with self.assertRaises(ActionError):
            self.call("files.upload", dir="public", name="x.txt", spool="../outside/secret.txt")

    def test_upload_folder_paths_and_overwrite(self):
        self.call("files.upload", dir="public", name="assets/css/site.css", spool=self.spool_in(b"body{}"))
        self.assertEqual((self.root / "public/assets/css/site.css").read_text(), "body{}")
        with self.assertRaises(ActionError) as cm:
            self.call("files.upload", dir="public", name="assets/css/site.css", spool=self.spool_in(b"a{}"))
        self.assertEqual(cm.exception.code, "conflict")
        self.call("files.upload", dir="public", name="assets/css/site.css", overwrite=True, spool=self.spool_in(b"a{}"))
        self.assertEqual((self.root / "public/assets/css/site.css").read_text(), "a{}")
        with self.assertRaises(ActionError):
            self.call("files.upload", dir="public", name="../../escape.txt", spool=self.spool_in(b"x"))

    def test_managed_folders_cannot_be_removed(self):
        for p in ("public", "logs", ""):
            with self.assertRaises(ActionError) as cm:
                self.call("files.delete", paths=[p])
            self.assertEqual(cm.exception.code, "forbidden")
        with self.assertRaises(ActionError):
            self.call("files.rename", path="public", name="www")
        self.call("files.mkdir", dir="public", name="blog")
        self.call("files.mkdir", dir="public/blog", name="readme.txt", type="file")
        self.call("files.rename", path="public/blog", name="nieuws")
        self.call("files.copy", paths=["public/nieuws"], to="")
        self.call("files.move", paths=["public/nieuws/readme.txt"], to="public")
        self.assertTrue((self.root / "nieuws/readme.txt").exists())
        self.assertTrue((self.root / "public/readme.txt").exists())
        with self.assertRaises(ActionError):
            self.call("files.move", paths=["nieuws"], to="nieuws")
        self.call("files.delete", paths=["nieuws", "public/nieuws"])
        self.assertFalse((self.root / "nieuws").exists())

    def test_chmod_refuses_special_bits(self):
        self.call("files.chmod", paths=["public/index.php"], mode="600")
        self.assertEqual(os.stat(self.root / "public/index.php").st_mode & 0o777, 0o600)
        for bad in ("4755", "777", "8", "abc"):
            with self.assertRaises(ActionError):
                self.call("files.chmod", paths=["public/index.php"], mode=bad)

    def test_compress_and_extract_round_trip(self):
        (self.root / "public/a").mkdir()
        (self.root / "public/a/b.txt").write_text("bee")
        job = self.wait(self.call("files.compress", paths=["public/a", "public/index.php"], dir="public", name="pakket"))
        self.assertEqual(job["status"], "succeeded", job)
        self.assertEqual(job["result"]["path"], "public/pakket.zip")
        self.call("files.mkdir", dir="", name="uit")
        job = self.wait(self.call("files.extract", path="public/pakket.zip", to="uit"))
        self.assertEqual(job["status"], "succeeded", job)
        self.assertEqual((self.root / "uit/a/b.txt").read_text(), "bee")
        job = self.wait(self.call("files.extract", path="public/pakket.zip", to="uit"))
        self.assertEqual(job["status"], "failed")
        self.assertEqual(job["error"]["code"], "conflict")
        job = self.wait(self.call("files.compress", paths=["public/a"], dir="", format="tar.gz"))
        self.assertEqual(job["result"]["path"], "a.tar.gz")

    def test_extract_skips_zip_slip_and_links(self):
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as zf:
            zf.writestr("../../evil.php", "x")
            zf.writestr("/abs.php", "x")
            zf.writestr("ok/fine.txt", "ok")
            link = zipfile.ZipInfo("ok/link")
            link.external_attr = (0o120777) << 16
            zf.writestr(link, "/etc/passwd")
        (self.root / "public/slip.zip").write_bytes(buf.getvalue())
        job = self.wait(self.call("files.extract", path="public/slip.zip"))
        self.assertEqual(job["status"], "succeeded", job)
        self.assertTrue((self.root / "public/ok/fine.txt").exists())
        self.assertFalse((self.root / "public/ok/link").exists())
        self.assertFalse((self.vhosts / "evil.php").exists())
        self.assertFalse(Path("/abs.php").exists())
        self.assertTrue(any("unsafe" in line for line in job["log"]))

        tbuf = io.BytesIO()
        with tarfile.open(fileobj=tbuf, mode="w:gz") as tf:
            ti = tarfile.TarInfo("../up.txt")
            ti.size = 1
            tf.addfile(ti, io.BytesIO(b"x"))
            sym = tarfile.TarInfo("sym")
            sym.type = tarfile.SYMTYPE
            sym.linkname = "/etc"
            tf.addfile(sym)
        (self.root / "public/t.tar.gz").write_bytes(tbuf.getvalue())
        job = self.wait(self.call("files.extract", path="public/t.tar.gz"))
        self.assertEqual(job["status"], "succeeded", job)
        self.assertFalse((self.root / "up.txt").exists())
        self.assertFalse((self.root / "public/sym").exists())

    def test_download_folder_as_zip(self):
        name = "dl-" + "d" * 32
        data = self.call("files.download", path="public", spool=name)
        self.assertEqual(data["name"], "public.zip")
        with zipfile.ZipFile(self.spool / name) as zf:
            self.assertIn("public/index.php", zf.namelist())

    def test_search(self):
        (self.root / "public/contact.php").write_text("")
        data = self.call("files.search", query="CONTACT")
        self.assertEqual([r["path"] for r in data["results"]], ["public/contact.php"])


if __name__ == "__main__":
    unittest.main()
