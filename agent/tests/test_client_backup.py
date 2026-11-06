"""client_backup.* end to end with a fake rclone (a local folder as the remote)
and site_worker.py run as the current user instead of the site user.

Databases are not exercised here (no MariaDB in the test environment); the
dump filter and the database mapping are tested on their own.
"""

import io
import json
import os
import sys
import tempfile
import textwrap
import time
import unittest
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths  # noqa: E402
from whp_agent.actions import client_backup as cb  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

FAKE_RCLONE = textwrap.dedent('''\
    #!{python}
    import json, os, shutil, sys
    ROOT = {root!r}
    args = sys.argv[1:]
    flags_with_value = {{"--config", "--contimeout", "--timeout", "--low-level-retries", "--retries"}}
    rest = []
    i = 0
    while i < len(args):
        if args[i] in flags_with_value:
            i += 2
            continue
        if args[i].startswith("--"):
            i += 1
            continue
        rest.append(args[i]); i += 1
    cmd, rest = rest[0], rest[1:]
    def local(p):
        assert p.startswith("remote:"), p
        return os.path.join(ROOT, p[len("remote:"):])
    if cmd == "obscure":
        print(sys.stdin.read().strip()[::-1])
    elif cmd == "mkdir":
        os.makedirs(local(rest[0]), exist_ok=True)
    elif cmd == "lsjson":
        d = local(rest[0])
        if not os.path.isdir(d):
            sys.stderr.write("ERROR : error listing: directory not found\\n"); sys.exit(3)
        print(json.dumps([{{"Name": n, "Size": os.path.getsize(os.path.join(d, n))}} for n in sorted(os.listdir(d))
                          if os.path.isfile(os.path.join(d, n))]))
    elif cmd == "rcat":
        with open(local(rest[0]), "wb") as fh:
            shutil.copyfileobj(sys.stdin.buffer, fh)
    elif cmd == "copyto":
        shutil.copyfile(local(rest[0]), rest[1])
    elif cmd == "deletefile":
        os.unlink(local(rest[0]))
    else:
        sys.exit(2)
''')


def wait(job):
    for _ in range(600):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.05)
    raise AssertionError("job did not finish")


class ClientBackupTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.site = root / "www" / "klant.nl"
        (self.site / "public" / "img").mkdir(parents=True)
        (self.site / "tmp").mkdir()
        (self.site / "public" / "index.php").write_text("<?php echo 'v1';\n")
        (self.site / "public" / "img" / "logo.svg").write_text("<svg/>\n")
        (self.site / "tmp" / "cache.bin").write_text("skip me\n")
        os.symlink("index.php", self.site / "public" / "home.php")
        self.mail = root / "vmail" / "klant.nl" / "info" / "Maildir" / "cur"
        self.mail.mkdir(parents=True)
        (self.mail / "1700000000.M1.web01:2,S").write_text("Subject: hi\n\nhello\n")
        self.remote = root / "remote"
        self.remote.mkdir()
        rclone = root / "rclone"
        rclone.write_text(FAKE_RCLONE.format(python=sys.executable, root=str(self.remote)))
        rclone.chmod(0o755)

        self._saved = (cb.RCLONE, cb.STATE, cb.UPLOAD_DIR, cb.VMAIL, cb.SECRETS_TMP, cb.site_info,
                       cb.worker_cmd, paths.JOBS_DIR, cb.dbs.owned_by)
        cb.RCLONE = str(rclone)
        cb.STATE = root / "state"
        cb.UPLOAD_DIR = str(root / "uploads")
        os.makedirs(cb.UPLOAD_DIR)
        cb.VMAIL = str(root / "vmail")
        cb.SECRETS_TMP = str(root / "secrets")
        os.makedirs(cb.SECRETS_TMP)
        paths.JOBS_DIR = str(root / "jobs")
        cb.site_info = lambda d: {"domain": d, "user": "nobody", "root": str(root / "www" / d)}
        cb.worker_cmd = lambda user, args: [sys.executable, "-I", cb.WORKER, *args]
        cb.dbs.owned_by = lambda d: []
        os.environ["WHP_CLIENT_BACKUP_ALLOW_PRIVATE"] = "yes"
        self.target = {"type": "ftps", "host": "127.0.0.1", "user": "klant", "password": "geheim", "path": "backups"}

    def tearDown(self):
        (cb.RCLONE, cb.STATE, cb.UPLOAD_DIR, cb.VMAIL, cb.SECRETS_TMP, cb.site_info,
         cb.worker_cmd, paths.JOBS_DIR, cb.dbs.owned_by) = self._saved
        os.environ.pop("WHP_CLIENT_BACKUP_ALLOW_PRIVATE", None)
        self.tmp.cleanup()

    def run_backup(self, kind="full", parts=("files", "mail")):
        state = wait(cb.client_backup_run({"domain": "klant.nl", "target": self.target, "target_id": 7,
                                           "kind": kind, "parts": list(parts), "keep_full": 2}))
        self.assertEqual(state["status"], "succeeded", state)
        return state["result"]

    def remote_files(self):
        return sorted(os.listdir(self.remote / "backups" / "klant.nl"))

    def test_full_backup_is_a_zip_with_manifest_and_without_excluded_folders(self):
        res = self.run_backup()
        self.assertEqual(res["kind"], "full")
        self.assertRegex(res["file"], r"^klant\.nl-\d{8}-\d{6}-full\.zip$")
        with zipfile.ZipFile(self.remote / "backups" / "klant.nl" / res["file"]) as zf:
            names = zf.namelist()
            manifest = json.loads(zf.read("whp-backup.json"))
            self.assertEqual(zf.read("files/public/index.php"), b"<?php echo 'v1';\n")
        self.assertIn("files/public/img/logo.svg", names)
        self.assertIn("files/public/home.php", names)
        self.assertIn("mail/info/Maildir/cur/1700000000.M1.web01:2,S", names)
        self.assertNotIn("files/tmp/cache.bin", names)
        self.assertEqual(manifest["domain"], "klant.nl")
        self.assertEqual(manifest["parts"], ["files", "mail"])

        listed = cb.client_backup_list({"domain": "klant.nl", "target": self.target})["backups"]
        self.assertEqual([b["file"] for b in listed], [res["file"]])

    def test_incremental_holds_only_changes_and_restore_mirrors_that_moment(self):
        full = self.run_backup()
        time.sleep(1.1)  # new file name (second resolution) and a new mtime
        (self.site / "public" / "index.php").write_text("<?php echo 'v2';\n")
        (self.site / "public" / "new.txt").write_text("new\n")
        (self.site / "public" / "img" / "logo.svg").unlink()
        incr = self.run_backup("incremental")
        self.assertEqual(incr["kind"], "incremental")
        self.assertEqual(incr["base"], full["file"])
        with zipfile.ZipFile(self.remote / "backups" / "klant.nl" / incr["file"]) as zf:
            names = set(zf.namelist())
            manifest = json.loads(zf.read("whp-backup.json"))
        self.assertIn("files/public/index.php", names)
        self.assertIn("files/public/new.txt", names)
        self.assertNotIn("files/public/img/logo.svg", names)
        self.assertEqual(manifest["sources"]["files"]["deleted"], ["public/img/logo.svg"])

        # Break the site, then restore the incremental: v2 + new.txt, no logo, no junk.
        (self.site / "public" / "index.php").write_text("hacked\n")
        (self.site / "public" / "evil.php").write_text("<?php system($_GET['c']);\n")
        state = wait(cb.client_backup_restore({"domain": "klant.nl", "target": self.target, "file": incr["file"],
                                               "parts": ["files"]}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertEqual((self.site / "public" / "index.php").read_text(), "<?php echo 'v2';\n")
        self.assertTrue((self.site / "public" / "new.txt").exists())
        self.assertFalse((self.site / "public" / "evil.php").exists())
        self.assertFalse((self.site / "public" / "img" / "logo.svg").exists())
        self.assertTrue((self.site / "tmp" / "cache.bin").exists(), "excluded folders are left alone")

        # And the full one: v1 and the logo again, new.txt gone.
        state = wait(cb.client_backup_restore({"domain": "klant.nl", "target": self.target, "file": full["file"],
                                               "parts": ["files"]}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertEqual((self.site / "public" / "index.php").read_text(), "<?php echo 'v1';\n")
        self.assertTrue((self.site / "public" / "img" / "logo.svg").exists())
        self.assertFalse((self.site / "public" / "new.txt").exists())
        self.assertTrue((self.site / "public" / "home.php").is_symlink())

    def test_mail_restore_adds_missing_messages_and_keeps_new_ones(self):
        full = self.run_backup()
        msg = self.mail / "1700000000.M1.web01:2,S"
        msg.unlink()
        (self.mail / "1800000000.M2.web01:2,").write_text("new mail\n")
        state = wait(cb.client_backup_restore({"domain": "klant.nl", "target": self.target, "file": full["file"],
                                               "parts": ["mail"]}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertTrue(msg.exists())
        self.assertTrue((self.mail / "1800000000.M2.web01:2,").exists())
        # A message whose flags changed is not put back twice.
        msg.rename(self.mail / "1700000000.M1.web01:2,RS")
        wait(cb.client_backup_restore({"domain": "klant.nl", "target": self.target, "file": full["file"], "parts": ["mail"]}))
        self.assertEqual(len([p for p in os.listdir(self.mail) if p.startswith("1700000000")]), 1)

    def test_retention_keeps_the_newest_full_backups_and_their_incrementals(self):
        first = self.run_backup()
        time.sleep(1.1)
        self.run_backup("incremental")
        time.sleep(1.1)
        self.run_backup()
        time.sleep(1.1)
        third = self.run_backup()
        files = self.remote_files()
        self.assertNotIn(first["file"], files)
        self.assertFalse(any("-incr-" in f for f in files), files)
        self.assertIn(third["file"], files)
        self.assertEqual(len(files), 2)

    def test_uploaded_backup_is_inspected_and_restored_then_removed(self):
        full = self.run_backup()
        upload = "0f0e0d0c-0b0a-4000-8000-000000000001"
        dest = Path(cb.UPLOAD_DIR) / f"{upload}.zip"
        dest.write_bytes((self.remote / "backups" / "klant.nl" / full["file"]).read_bytes())
        info = cb.client_backup_inspect_upload({"upload": upload})
        self.assertEqual(info["domain"], "klant.nl")
        self.assertEqual(info["kind"], "full")
        self.assertIn("files", info["contains"])

        (self.site / "public" / "index.php").write_text("broken\n")
        state = wait(cb.client_backup_restore_upload({"domain": "klant.nl", "upload": upload, "parts": ["files"]}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertEqual((self.site / "public" / "index.php").read_text(), "<?php echo 'v1';\n")
        self.assertFalse(dest.exists())

    def test_safety_backup_before_restore(self):
        full = self.run_backup()
        time.sleep(1.1)
        state = wait(cb.client_backup_restore({"domain": "klant.nl", "target": self.target, "file": full["file"],
                                               "parts": ["files"], "safety_target": self.target, "safety_target_id": 7}))
        self.assertEqual(state["status"], "succeeded", state)
        self.assertIsNotNone(state["result"]["safety_backup"])
        self.assertIn(state["result"]["safety_backup"], self.remote_files())

    def _zip(self, entries, manifest=True):
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
            for name, data in entries.items():
                zf.writestr(name, data)
            if manifest:
                zf.writestr("whp-backup.json", json.dumps({"format": "whp-site-backup", "kind": "full", "domain": "klant.nl"}))
        path = Path(cb.UPLOAD_DIR) / "check.zip"
        path.write_bytes(buf.getvalue())
        return str(path)

    def test_unsafe_archives_are_refused_before_extracting(self):
        for entries, manifest, message in [
            ({"files/../../etc/passwd": "x"}, True, "Unsafe path"),
            ({"/etc/passwd": "x"}, True, "Unexpected entry"),
            ({"other/x": "x"}, True, "Unexpected entry"),
            ({"databases/../mysql.sql": "x"}, True, "Unsafe path"),
            ({"databases/x;drop.sql": "x"}, True, "Unexpected database"),
            ({"files/a.txt": "x"}, False, "not a backup made by the panel"),
            ({"files/zero.bin": b"\0" * (64 * 1024 ** 2)}, True, "compression ratio"),
        ]:
            with self.subTest(message=message):
                with self.assertRaises(ActionError) as ctx:
                    cb.open_archive(self._zip(entries, manifest))
                self.assertIn(message, ctx.exception.message)
        path = self._zip({"files/a.txt": "x" * 100})
        with self.assertRaises(ActionError) as ctx:
            cb.open_archive(path, limit=10)
        self.assertIn("more than the allowed", ctx.exception.message)

    def test_symlinks_leaving_the_site_are_not_restored(self):
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as zf:
            zi = zipfile.ZipInfo("files/public/passwd")
            zi.external_attr = (0o120777) << 16
            zf.writestr(zi, "/etc/passwd")
            zf.writestr("files/public/ok.txt", "ok\n")
            zf.writestr("whp-backup.json", json.dumps({"format": "whp-site-backup", "kind": "full", "domain": "klant.nl"}))
        upload = "0f0e0d0c-0b0a-4000-8000-000000000002"
        (Path(cb.UPLOAD_DIR) / f"{upload}.zip").write_bytes(buf.getvalue())
        state = wait(cb.client_backup_restore_upload({"domain": "klant.nl", "upload": upload, "parts": ["files"]}))
        self.assertEqual(state["status"], "failed")
        self.assertFalse((self.site / "public" / "passwd").is_symlink())

    def test_targets_are_validated(self):
        with self.assertRaises(ActionError):
            cb.parse_target({"target": {**self.target, "type": "ftp"}})  # plain FTP needs explicit consent
        cb.parse_target({"target": {**self.target, "type": "ftp", "allow_plain_ftp": True}})
        with self.assertRaises(ActionError):
            cb.parse_target({"target": {**self.target, "path": "../../etc"}})
        with self.assertRaises(ActionError):
            cb.parse_target({"target": {**self.target, "type": "sftp", "password": None}})
        os.environ.pop("WHP_CLIENT_BACKUP_ALLOW_PRIVATE")
        with self.assertRaises(ActionError) as ctx:
            cb.resolve_host("127.0.0.1")
        self.assertEqual(ctx.exception.code, "forbidden")
        with self.assertRaises(ActionError):
            cb.resolve_host("10.1.2.3")
        with self.assertRaises(ActionError):
            cb.resolve_host("169.254.169.254")

    def test_file_names_must_belong_to_the_domain(self):
        with self.assertRaises(ActionError):
            cb._file({"file": "ander.nl-20261106-020000-full.zip"}, "klant.nl")
        with self.assertRaises(ActionError):
            cb._file({"file": "../klant.nl-20261106-020000-full.zip"}, "klant.nl")
        meta = cb.parse_name("klant.nl", "klant.nl-20261107-020000-incr-20261106-020000.zip")
        self.assertEqual(meta["base"], "klant.nl-20261106-020000-full.zip")

    def test_definer_is_stripped_from_dumps(self):
        line = b"/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */\n"
        self.assertNotIn(b"root", cb.strip_definer(line))
        line = b"CREATE DEFINER='admin'@'%' TRIGGER t BEFORE INSERT ON x FOR EACH ROW SET @a=1;\n"
        self.assertEqual(cb.strip_definer(line), b"CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW SET @a=1;\n")

    def test_database_plan_only_targets_the_sites_own_databases(self):
        plan, skipped = cb._db_plan({"database_files": ["shop", "mysql"]}, [{"name": "shop", "user": "shop"}])
        self.assertEqual(plan, [("shop", "shop", "shop")])
        self.assertEqual(skipped, ["mysql"])
        # One dump onto the only database of the site (for example on a new server).
        plan, _ = cb._db_plan({"database_files": ["old_wp"]}, [{"name": "new_wp_1a2b", "user": "new_wp_1a2b"}])
        self.assertEqual(plan, [("old_wp", "new_wp_1a2b", "new_wp_1a2b")])


if __name__ == "__main__":
    unittest.main()
