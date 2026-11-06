"""Git deployments with real git: a local repository plays the remote, and the
site-user wrapper (systemd-run) is replaced by running the command directly.
Checks direct and zero-downtime deploys, shared files, build steps, a failed
build that must not reach visitors, rollback, removal and the input checks."""

import os
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths, wordpress  # noqa: E402
from whp_agent.actions import git as g  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

GIT = subprocess.run(["which", "git"], capture_output=True, text=True).stdout.strip() or "/usr/bin/git"
KEYGEN = subprocess.run(["which", "ssh-keygen"], capture_output=True, text=True).stdout.strip()


def sh(*argv, cwd=None):
    return subprocess.run(list(argv), cwd=cwd, check=True, capture_output=True, text=True).stdout


class GitDeployTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.site_root = root / "vhosts/klant.nl"
        (self.site_root / "public").mkdir(parents=True)
        (self.site_root / "public/index.html").write_text("placeholder")
        self.site = {"domain": "klant.nl", "id": "klant_nl", "user": "s_klant_nl", "php": "8.4",
                     "root": str(self.site_root), "docroot": str(self.site_root / "public")}
        self.calls = []
        self.systemctl = []

        def as_user(site, argv, cwd, input_text=None, timeout=300, check=True):
            self.calls.append(list(argv))
            argv = [GIT if a == "/usr/bin/git" else a for a in argv]
            if argv[0] == "fake-keygen":  # no openssh-client on this machine: write a key-shaped file
                if "-l" in argv:
                    return SimpleNamespace(stdout="256 SHA256:fake deploy (ED25519)", stderr="", returncode=0)
                key = argv[argv.index("-f") + 1]
                Path(key).write_text("private")
                os.chmod(key, 0o600)
                Path(key + ".pub").write_text(f"ssh-ed25519 AAAA{os.urandom(8).hex()} deploy@klant.nl\n")
                return SimpleNamespace(stdout="", stderr="", returncode=0)
            env = dict(os.environ, HOME=site["root"], GIT_CONFIG_GLOBAL="/dev/null")
            proc = subprocess.run(argv, cwd=cwd, capture_output=True, text=True, input=input_text, env=env)
            if check and proc.returncode != 0:
                raise ActionError("command_failed", f"{argv[0]} failed", {"stderr": proc.stderr, "stdout": proc.stdout})
            return proc

        def run(argv, **kw):
            self.systemctl.append(list(argv))
            return SimpleNamespace(stdout="", stderr="", returncode=0)

        # The "remote": a normal repository with two commits on main.
        self.remote = root / "remote"
        self.remote.mkdir()
        sh(GIT, "init", "-q", "-b", "main", cwd=self.remote)
        sh(GIT, "config", "user.email", "dev@klant.nl", cwd=self.remote)
        sh(GIT, "config", "user.name", "Dev", cwd=self.remote)
        self.commit({"index.php": "<?php echo 1;", "old.txt": "old", "storage/app/.gitkeep": ""}, "First")
        self.url = f"file://{self.remote}"

        self.patches = [
            mock.patch.object(wordpress, "site_info", lambda d: dict(self.site) if d == "klant.nl" else (_ for _ in ()).throw(ActionError("not_found", "x"))),
            mock.patch.object(wordpress, "as_user", as_user),
            mock.patch.object(g, "run", run),
            mock.patch.object(g, "GIT", "/usr/bin/git"),
            mock.patch.object(g, "SSH_KEYGEN", KEYGEN or "fake-keygen"),
            mock.patch.object(g, "ALLOWED_PROTOCOLS", "file"),
            mock.patch.object(g, "STATE_DIR", str(root / "state/git")),
            mock.patch.object(paths, "JOBS_DIR", str(root / "jobs")),
        ]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in reversed(self.patches):
            p.stop()
        self.tmp.cleanup()

    def commit(self, files, message, remove=()):
        for name, content in files.items():
            f = self.remote / name
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_text(content)
        for name in remove:
            sh(GIT, "rm", "-q", name, cwd=self.remote)
        sh(GIT, "add", "-A", cwd=self.remote)
        sh(GIT, "commit", "-q", "-m", message, cwd=self.remote)
        return sh(GIT, "rev-parse", "HEAD", cwd=self.remote).strip()

    def params(self, **kw):
        return {"domain": "klant.nl", "id": "repo-0001", "url": self.url, "branch": "main", "path": "public", **kw}

    def deploy(self, **kw):
        job = g.deploy_action(self.params(**kw))
        for _ in range(400):
            state = jobs.read(job["job"])
            if state["status"] != "running":
                return state
            time.sleep(0.05)
        raise AssertionError("deploy did not finish")

    # --- direct mode

    def test_direct_deploy_checks_out_into_the_folder_and_removes_deleted_files(self):
        state = self.deploy()
        self.assertEqual("succeeded", state["status"], state)
        pub = self.site_root / "public"
        self.assertEqual("<?php echo 1;", (pub / "index.php").read_text())
        self.assertEqual("placeholder", (pub / "index.html").read_text())  # never in git: stays
        self.assertFalse((pub / ".git").exists())
        self.assertEqual("First", state["result"]["commit"]["message"])

        (pub / "upload.jpg").write_text("from a visitor")
        sha = self.commit({"index.php": "<?php echo 2;"}, "Second", remove=["old.txt"])
        state = self.deploy()
        self.assertEqual("succeeded", state["status"], state)
        self.assertEqual(sha, state["result"]["commit"]["commit"])
        self.assertEqual("<?php echo 2;", (pub / "index.php").read_text())
        self.assertFalse((pub / "old.txt").exists())
        self.assertTrue((pub / "upload.jpg").exists())

        status = g.status(self.params())
        self.assertEqual(sha, status["deployed"]["commit"])
        self.assertTrue(status["has_key"])

    def test_deploy_key_is_made_once_as_the_site_user_and_can_be_rotated(self):
        first = g.key(self.params())
        self.assertTrue(first["public_key"].startswith("ssh-ed25519 "))
        self.assertIn("deploy@klant.nl", first["public_key"])
        self.assertEqual(first, g.key(self.params()))
        self.assertNotEqual(first["public_key"], g.key(self.params(rotate=True))["public_key"])
        self.assertEqual(0o600, (self.site_root / "git/repo-0001/deploy_key").stat().st_mode & 0o777)

    def test_check_lists_branches_with_the_default_first(self):
        sh(GIT, "branch", "develop", cwd=self.remote)
        out = g.check(self.params())
        self.assertEqual("main", out["default"])
        self.assertEqual(["main", "develop"], [b["name"] for b in out["branches"]])

    def test_unknown_branch_gives_a_clear_error(self):
        state = self.deploy(branch="bestaat-niet")
        self.assertEqual("failed", state["status"])
        self.assertEqual("not_found", state["error"]["code"])

    def test_git_commands_never_run_hooks_and_get_the_deploy_key(self):
        self.deploy()
        fetches = [c for c in self.calls if "fetch" in c]
        self.assertTrue(fetches)
        env_args = [a for a in fetches[0] if "=" in a and a.split("=")[0].isupper()]
        ssh = next(a for a in env_args if a.startswith("GIT_SSH_COMMAND="))
        self.assertIn("git/repo-0001/deploy_key", ssh)
        self.assertIn("StrictHostKeyChecking=accept-new", ssh)
        self.assertIn("core.hooksPath=/dev/null", fetches[0])
        self.assertIn("GIT_ALLOW_PROTOCOL=file", env_args)

    # --- zero downtime

    def test_releases_mode_switches_a_symlink_keeps_shared_files_and_prunes(self):
        kw = {"mode": "releases", "keep": 2, "shared": ["storage/", ".env"]}
        state = self.deploy(**kw)
        self.assertEqual("succeeded", state["status"], state)
        pub = self.site_root / "public"
        self.assertTrue(pub.is_symlink())
        self.assertEqual("<?php echo 1;", (pub / "index.php").read_text())
        backups = list(self.site_root.glob("public.before-git-*"))
        self.assertEqual(1, len(backups))  # the old folder is kept, not deleted
        self.assertTrue((pub / ".env").is_symlink())
        (self.site_root / "git/repo-0001/shared/.env").write_text("APP_KEY=x")
        (pub / "storage/app/file.txt").write_text("kept")
        self.assertEqual([["systemctl", "reload", "whp-site-klant_nl.service"]], self.systemctl)

        for n in (2, 3):
            time.sleep(1.05)  # release names have second precision
            self.commit({"index.php": f"<?php echo {n};"}, f"Commit {n}")
            self.assertEqual("succeeded", self.deploy(**kw)["status"])
        self.assertEqual("<?php echo 3;", (pub / "index.php").read_text())
        self.assertEqual("APP_KEY=x", (pub / ".env").read_text())
        self.assertEqual("kept", (pub / "storage/app/file.txt").read_text())
        status = g.status(self.params())
        self.assertEqual(2, len(status["releases"]))
        self.assertTrue(status["releases"][0]["current"])

        # Rollback to the previous release.
        previous = status["releases"][1]["name"]
        g.rollback(self.params(release=previous))
        self.assertEqual("<?php echo 2;", (pub / "index.php").read_text())
        self.assertEqual(previous, g.status(self.params())["current_release"])
        with self.assertRaises(ActionError):
            g.rollback(self.params(release="20200101000000-abcdef0"))

        # Removing keeps a normal folder with the real shared files.
        out = g.remove(self.params(shared=["storage/", ".env"]))
        self.assertTrue(out["files_kept"])
        self.assertFalse(pub.is_symlink())
        self.assertEqual("<?php echo 2;", (pub / "index.php").read_text())
        self.assertFalse((pub / ".env").is_symlink())
        self.assertEqual("APP_KEY=x", (pub / ".env").read_text())
        self.assertEqual("kept", (pub / "storage/app/file.txt").read_text())
        self.assertFalse((self.site_root / "git/repo-0001").exists())

    def test_a_failed_build_step_never_reaches_the_website(self):
        kw = {"mode": "releases", "allow_commands": True}
        self.assertEqual("succeeded", self.deploy(**kw, steps=[{"type": "command", "command": "echo ok > built.txt"}])["status"])
        pub = self.site_root / "public"
        self.assertEqual("ok\n", (pub / "built.txt").read_text())
        current = g.status(self.params())["current_release"]

        time.sleep(1.05)
        self.commit({"index.php": "<?php broken"}, "Broken")
        state = self.deploy(**kw, steps=[{"type": "command", "command": "echo building; exit 3"}])
        self.assertEqual("failed", state["status"])
        self.assertEqual("command_failed", state["error"]["code"])
        self.assertIn("  building", state["log"])
        self.assertEqual("<?php echo 1;", (pub / "index.php").read_text())
        self.assertEqual(current, g.status(self.params())["current_release"])
        self.assertEqual(1, len(g.status(self.params())["releases"]))  # the failed release is cleaned up

    def test_steps_that_do_not_apply_are_skipped(self):
        state = self.deploy(steps=[{"type": "composer"}, {"type": "npm"}, {"type": "artisan_migrate"}])
        self.assertEqual("succeeded", state["status"], state)
        self.assertEqual(["skipped"] * 3, [s["status"] for s in state["result"]["steps"]])

    def test_step_command_lines(self):
        work = self.site_root / "public"
        (work / "composer.json").write_text("{}")
        (work / "artisan").write_text("")
        (work / "package.json").write_text('{"scripts": {"build": "vite build"}}')
        (work / "package-lock.json").write_text("{}")
        self.assertEqual(["/usr/bin/php8.4", g.COMPOSER, "install", "--no-dev", "--no-interaction", "--prefer-dist",
                          "--optimize-autoloader", "--no-progress"], g.step_argv(self.site, {"type": "composer"}, str(work)))
        self.assertEqual([g.NPM, "ci", "--no-audit", "--no-fund"], g.step_argv(self.site, {"type": "npm"}, str(work)))
        self.assertEqual(["/usr/bin/php8.4", "artisan", "migrate", "--force"], g.step_argv(self.site, {"type": "artisan_migrate"}, str(work)))
        self.assertTrue(g._npm_build(str(work)))

    # --- input checks

    def test_input_is_checked(self):
        with mock.patch.object(g, "ALLOWED_PROTOCOLS", "https:ssh"):
            for url in ["https://github.com/rhis/site.git", "git@github.com:rhis/site.git", "ssh://git@gitlab.example.nl:2222/a/b.git"]:
                self.assertEqual(url, g._url({"url": url}))
            for bad in ["file:///etc", "ext::sh -c id", "-oProxyCommand=x", "https://user:pw@github.com/a/b",
                        "git@github.com:-u/x", "https://github.com/../x", "/srv/repo"]:
                with self.subTest(url=bad), self.assertRaises(ActionError):
                    g._url({"url": bad})
        for bad in ["-x", "a..b", "a//b", "x.lock", "a b", "@{1}"]:
            with self.subTest(branch=bad), self.assertRaises(ActionError):
                g._branch({"branch": bad})
        for bad in ["", "/", "../x", "logs", "git/x", "tmp", "a/../../b", ".ssh"]:
            with self.subTest(path=bad), self.assertRaises(ActionError):
                g.rel_path(bad)
        self.assertEqual("app", g.rel_path("/app/"))
        with self.assertRaises(ActionError) as e:
            g._steps({"steps": [{"type": "command", "command": "id"}]})
        self.assertEqual("forbidden", e.exception.code)
        with self.assertRaises(ActionError):
            g._steps({"steps": [{"type": "rm -rf"}]})
        with self.assertRaises(ActionError):
            g._shared({"shared": ["../etc/passwd"]})

    def test_one_deployment_at_a_time(self):
        with g._Lock(self.site, "repo-0001"):
            with self.assertRaises(ActionError) as e:
                g._Lock(self.site, "repo-0001").__enter__()
            self.assertEqual("conflict", e.exception.code)

    def test_friendly_errors(self):
        e = g._friendly(ActionError("command_failed", "x", {"stderr": "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository."}))
        self.assertEqual("forbidden", e.code)
        self.assertIn("deploy key", e.message)


if __name__ == "__main__":
    unittest.main()
