"""End to end check of Git deployments on a real install (run by smoke.sh as root).

A local repository plays GitHub (file:// is allowed only here). Deploys in
zero-downtime mode into a site, as the site user through systemd-run, checks
that Nginx serves the new release, that a failing build keeps the old one live,
then rolls back and removes the repository.

Usage: python3 tests/git_smoke.py site.test
"""

import os
import shutil
import subprocess
import sys
import time

os.environ["WHP_GIT_PROTOCOLS"] = "file"
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import jobs, wordpress  # noqa: E402
from whp_agent.actions import git as g  # noqa: E402

domain = sys.argv[1]
site = wordpress.site_info(domain)
remote = "/srv/whp-git-smoke"


def step(name, ok, detail=""):
    print(("ok   " if ok else "FAIL ") + name + (f"  {detail}" if detail and not ok else ""))
    if not ok:
        sys.exit(1)


def sh(*argv, cwd=remote):
    # The remote belongs to the site user (git refuses repositories of other users), root commits into it.
    argv = ["git", "-c", "safe.directory=*", *argv[1:]] if argv[0] == "git" else list(argv)
    subprocess.run(argv, cwd=cwd, check=True, capture_output=True, text=True)


def commit(body, message):
    with open(os.path.join(remote, "index.php"), "w") as fh:
        fh.write(f"<?php echo '{body}';\n")
    sh("git", "add", "-A")
    sh("git", "-c", "user.name=Smoke", "-c", "user.email=smoke@example.test", "commit", "-q", "-m", message)


def own():
    subprocess.run(["chown", "-R", f"{site['user']}:{site['user']}", remote], check=True)


def deploy(**kw):
    job = g.deploy_action({"domain": domain, "id": "smoke-0001", "url": f"file://{remote}", "branch": "main",
                           "path": "public", "mode": "releases", "shared": [".env"], **kw})
    for _ in range(600):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.5)
    raise SystemExit("deploy did not finish")


def page():
    return subprocess.run(["curl", "-s", "-H", f"Host: {domain}", "http://127.0.0.1/"],
                          capture_output=True, text=True, timeout=30).stdout


shutil.rmtree(remote, ignore_errors=True)
os.makedirs(remote)
sh("git", "init", "-q", "-b", "main")
commit("git-release-1", "First")
own()

key = g.key({"domain": domain, "id": "smoke-0001"})
step("deploy key made as the site user", key["public_key"].startswith("ssh-ed25519 ")
     and os.stat(os.path.join(site["root"], "git/smoke-0001/deploy_key")).st_uid == os.stat(site["root"]).st_uid)

state = deploy(steps=[{"type": "command", "command": "id -un > whoami.txt"}], allow_commands=True)
step("first deploy", state["status"] == "succeeded", str(state.get("error")) + "\n".join(state["log"][-10:]))
step("docroot is a link to the release", os.path.islink(site["docroot"]))
probe = subprocess.run(["runuser", "-u", "www-data", "--", "test", "-r", os.path.join(site["docroot"], "index.php")])
step("nginx user can read the release", probe.returncode == 0)
step("nginx serves release 1", "git-release-1" in page(), page()[:200])
with open(os.path.join(site["docroot"], "whoami.txt")) as fh:
    step("build steps run as the site user", fh.read().strip() == site["user"])

time.sleep(1.1)
commit("git-release-2", "Second")
own()
step("second deploy", deploy()["status"] == "succeeded")
step("nginx serves release 2 straight away", "git-release-2" in page(), page()[:200])

time.sleep(1.1)
commit("git-release-3-broken", "Broken")
own()
state = deploy(steps=[{"type": "command", "command": "exit 1"}], allow_commands=True)
step("failed build is reported", state["status"] == "failed")
step("failed build never goes live", "git-release-2" in page(), page()[:200])

status = g.status({"domain": domain, "id": "smoke-0001", "path": "public"})
previous = [r["name"] for r in status["releases"] if not r["current"]][0]
g.rollback({"domain": domain, "id": "smoke-0001", "path": "public", "release": previous})
step("rollback to release 1", "git-release-1" in page(), page()[:200])

out = g.remove({"domain": domain, "id": "smoke-0001", "path": "public", "shared": [".env"]})
step("remove keeps the files as a normal folder", out["files_kept"] and not os.path.islink(site["docroot"]) and "git-release-1" in page())
step("repository folder removed", not os.path.exists(os.path.join(site["root"], "git/smoke-0001")))
shutil.rmtree(remote, ignore_errors=True)

# Put the site back the way it was: later checks (second install run) expect its own index.php.
import glob  # noqa: E402

before = sorted(glob.glob(site["docroot"] + ".before-git-*"))
step("the original folder was kept aside", len(before) == 1)
shutil.rmtree(site["docroot"])
os.rename(before[0], site["docroot"])
