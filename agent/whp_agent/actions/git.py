"""Git deployments for websites (like Plesk's Git extension).

A website can have one or more repositories. Each one is fetched into a bare
repository below the site root and deployed to a folder of the site:

    /var/www/vhosts/<domain>/git/<id>/
        repo.git/        bare clone, fetched from the remote
        deploy_key(.pub) SSH deploy key (ed25519), made per repository
        known_hosts      host keys of the remote (accepted on first use)
        releases/<rel>/  zero-downtime mode: one folder per deployment
        shared/          zero-downtime mode: files kept between releases (.env, storage/)

Modes:
- "direct": the branch is checked out straight into the deploy folder (git's
  work tree), files the repository no longer has are removed, other files stay.
- "releases": every deployment goes into a new release folder, the build steps
  run there, and only when everything succeeded the deploy folder (a symlink)
  is switched to it in one rename. A failed build never reaches visitors, and
  a rollback is a switch back to an earlier release.

Everything that touches the site's files (git, ssh-keygen, composer, npm,
artisan, custom commands, mkdir/ln/mv/rm) runs as the site user through
systemd-run, never as root. The panel is the source of truth for the settings
and passes them with every call; the agent only keeps a small state file per
repository in /var/lib/webhosting-panel/git/<domain>/<id>.json (root owned).
"""

import fcntl
import json
import os
import re
import time

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

STATE_DIR = os.path.join(paths.STATE_DIR, "git")
GIT = os.environ.get("WHP_GIT", "/usr/bin/git")
SSH = "/usr/bin/ssh"
SSH_KEYGEN = "/usr/bin/ssh-keygen"
COMPOSER = os.environ.get("WHP_COMPOSER", "/usr/bin/composer")
NPM = os.environ.get("WHP_NPM", "/usr/bin/npm")
ENV = "/usr/bin/env"
# Remote protocols git may use. Tests add "file".
ALLOWED_PROTOCOLS = os.environ.get("WHP_GIT_PROTOCOLS", "https:ssh")
STEP_TIMEOUT = 1800

_ID = r"[a-z0-9][a-z0-9-]{3,39}"
_HOST = r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,62})(?:\.[A-Za-z0-9-]{1,63})*"
_REPO_PATH = r"[A-Za-z0-9_~][A-Za-z0-9._~/-]{0,255}"
_URL_PATTERNS = [
    re.compile(rf"^https://{_HOST}(?::\d{{1,5}})?/{_REPO_PATH}$"),
    re.compile(rf"^ssh://[A-Za-z0-9._-]{{1,64}}@{_HOST}(?::\d{{1,5}})?/{_REPO_PATH}$"),
    re.compile(rf"^[A-Za-z0-9._-]{{1,64}}@{_HOST}:{_REPO_PATH}$"),
]
_BRANCH = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9._/-]{0,199}$")
_SEGMENT = r"[A-Za-z0-9_.][A-Za-z0-9_.-]{0,99}"
_REL_PATH = re.compile(rf"^{_SEGMENT}(?:/{_SEGMENT}){{0,7}}$")
_RELEASE = re.compile(r"^\d{14}-[0-9a-f]{7,40}$")
_SHA = re.compile(r"^[0-9a-f]{40}$")
# Folders of the site root a deployment may never replace.
_RESERVED = {"git", "logs", "tmp", "sessions", ".ssh", ".config", ".cache"}
STEP_TYPES = ("composer", "npm", "artisan_migrate", "artisan_optimize", "command")
MODES = ("direct", "releases")


# ----------------------------------------------------------------- validation

def _id(params):
    return v.string(params, "id", max_len=40, pattern=_ID)


def _url(params, key="url"):
    url = v.string(params, key, max_len=400)
    if not any(p.fullmatch(url) for p in _URL_PATTERNS) or ".." in url:
        if not (ALLOWED_PROTOCOLS.split(":").count("file") and url.startswith("file:///")):
            raise ActionError("invalid_params", "url must be an https:// or SSH address of a git repository")
    return url


def _branch(params, required=True):
    b = v.string(params, "branch", required=required, max_len=200)
    if b is None:
        return None
    if not _BRANCH.fullmatch(b) or ".." in b or "//" in b or b.endswith((".lock", "/", ".")) or "@{" in b:
        raise ActionError("invalid_params", "branch has an invalid format")
    return b


def rel_path(value, key="path"):
    """A path relative to the site root ("public", "app"), never one of the reserved folders."""
    if not isinstance(value, str):
        raise ActionError("invalid_params", f"{key} must be a string")
    value = value.strip().strip("/")
    if not _REL_PATH.fullmatch(value) or any(s in (".", "..") for s in value.split("/")):
        raise ActionError("invalid_params", f"{key} has an invalid format")
    if value.split("/")[0] in _RESERVED:
        raise ActionError("forbidden", f"{key} may not be inside {value.split('/')[0]}/")
    return value


def _shared(params):
    items = params.get("shared") or []
    if not isinstance(items, list) or len(items) > 30:
        raise ActionError("invalid_params", "shared must be a list of at most 30 paths")
    out = []
    for item in items:
        if not isinstance(item, str):
            raise ActionError("invalid_params", "shared paths must be strings")
        is_dir = item.strip().endswith("/")
        p = item.strip().strip("/")
        if not _REL_PATH.fullmatch(p) or any(s in (".", "..", ".git") for s in p.split("/")):
            raise ActionError("invalid_params", f"Invalid shared path: {item}")
        out.append((p, is_dir))
    return out


def _steps(params):
    steps = params.get("steps") or []
    if not isinstance(steps, list) or len(steps) > 20:
        raise ActionError("invalid_params", "steps must be a list of at most 20 items")
    out = []
    for s in steps:
        if not isinstance(s, dict) or s.get("type") not in STEP_TYPES:
            raise ActionError("invalid_params", f"Unknown deploy step: {s!r:.60}")
        if s["type"] == "command":
            if not params.get("allow_commands"):
                raise ActionError("forbidden", "Custom deploy commands are switched off")
            cmd = s.get("command")
            if not isinstance(cmd, str) or not cmd.strip() or len(cmd) > 4000 or "\x00" in cmd:
                raise ActionError("invalid_params", "command must be a non-empty string of at most 4000 characters")
            out.append({"type": "command", "command": cmd})
        else:
            out.append({"type": s["type"]})
    return out


# ----------------------------------------------------------------- helpers

def _site(params):
    return wordpress.site_info(v.domain(params, "domain"))


def repo_dir(site, rid):
    return os.path.join(site["root"], "git", rid)


def _state_file(site, rid):
    return os.path.join(STATE_DIR, site["domain"], f"{rid}.json")


def read_state(site, rid):
    try:
        with open(_state_file(site, rid)) as fh:
            return json.load(fh)
    except (FileNotFoundError, ValueError):
        return {}


def write_state(site, rid, state):
    d = os.path.join(STATE_DIR, site["domain"])
    os.makedirs(d, mode=0o750, exist_ok=True)
    tmp = _state_file(site, rid) + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(state, fh, indent=1)
    os.replace(tmp, _state_file(site, rid))


class _Lock:
    """One deployment per repository at a time (root owned lock file, never in the site)."""

    def __init__(self, site, rid):
        os.makedirs(os.path.join(STATE_DIR, site["domain"]), mode=0o750, exist_ok=True)
        self.path = os.path.join(STATE_DIR, site["domain"], f"{rid}.lock")
        self.fh = None

    def __enter__(self):
        self.fh = open(self.path, "w")
        try:
            fcntl.flock(self.fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            self.fh.close()
            raise ActionError("conflict", "A deployment of this repository is already running")
        return self

    def __exit__(self, *exc):
        fcntl.flock(self.fh, fcntl.LOCK_UN)
        self.fh.close()


def as_user(site, argv, cwd=None, timeout=300, check=True, input_text=None, env=None):
    """Run argv as the site user; env goes through /usr/bin/env (no shell)."""
    full = [ENV, *[f"{k}={val}" for k, val in (env or {}).items()], *argv] if env else list(argv)
    return wordpress.as_user(site, full, cwd or site["root"], input_text=input_text, timeout=timeout, check=check)


def _git_env(site, rid):
    d = repo_dir(site, rid)
    ssh = (f"{SSH} -i {d}/deploy_key -o IdentitiesOnly=yes -o BatchMode=yes "
           f"-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile={d}/known_hosts -o ConnectTimeout=20")
    return {
        "GIT_SSH_COMMAND": ssh,
        "GIT_TERMINAL_PROMPT": "0",
        "GIT_ALLOW_PROTOCOL": ALLOWED_PROTOCOLS,
        "GIT_CONFIG_NOSYSTEM": "1",
    }


def git(site, rid, args, timeout=600, check=True, env=None, cwd=None):
    d = repo_dir(site, rid)
    return as_user(site, [GIT, "-c", "core.hooksPath=/dev/null", "-c", "advice.detachedHead=false",
                          f"--git-dir={d}/repo.git", *args],
                   cwd=cwd, timeout=timeout, check=check, env={**_git_env(site, rid), **(env or {})})


def _err_text(exc):
    d = exc.details or {}
    text = (d.get("stderr") or d.get("stdout") or exc.message).strip()
    return text[-1500:]


def _friendly(exc):
    """Turn a failed git call into a message a customer understands."""
    text = _err_text(exc)
    low = text.lower()
    if "permission denied (publickey)" in low or "could not read from remote" in low or "authentication failed" in low \
            or "repository not found" in low or "access denied" in low:
        msg = "The server has no access to the repository. Add the deploy key to the repository (read access is enough), or check the address."
        return ActionError("forbidden", msg, {"output": text})
    if "could not resolve host" in low or "connection timed out" in low or "connection refused" in low:
        return ActionError("command_failed", "The git server cannot be reached.", {"output": text})
    if "couldn't find remote ref" in low:
        return ActionError("not_found", "This branch does not exist in the repository.", {"output": text})
    return ActionError("command_failed", text.splitlines()[-1] if text else exc.message, {"output": text})


def ensure_repo(site, rid):
    """Create git/<id>/ and the bare repository (as the site user)."""
    d = repo_dir(site, rid)
    as_user(site, ["/usr/bin/mkdir", "-p", "-m", "0700", d, f"{d}/releases", f"{d}/shared"])
    if not os.path.isfile(os.path.join(d, "repo.git", "HEAD")):
        as_user(site, [GIT, "init", "--quiet", "--bare", f"{d}/repo.git"], env={"GIT_CONFIG_NOSYSTEM": "1"})


def ensure_key(site, rid, rotate=False):
    ensure_repo(site, rid)
    key = os.path.join(repo_dir(site, rid), "deploy_key")
    if rotate:
        as_user(site, ["/usr/bin/rm", "-f", key, f"{key}.pub"])
    if not os.path.isfile(f"{key}.pub"):
        as_user(site, [SSH_KEYGEN, "-q", "-t", "ed25519", "-N", "", "-C", f"deploy@{site['domain']}", "-f", key])
    pub = as_user(site, ["/usr/bin/cat", f"{key}.pub"]).stdout.strip()
    fp = as_user(site, [SSH_KEYGEN, "-l", "-f", f"{key}.pub"], check=False).stdout.strip()
    return {"public_key": pub, "fingerprint": fp.split(" ")[1] if len(fp.split(" ")) > 1 else fp}


def commit_info(site, rid, rev):
    out = git(site, rid, ["log", "-1", "--format=%H%x1f%an%x1f%ae%x1f%ct%x1f%s", rev, "--"]).stdout.strip()
    sha, author, email, ts, subject = (out.split("\x1f") + [""] * 5)[:5]
    return {"commit": sha, "short": sha[:7], "author": author, "email": email,
            "date": int(ts) if ts.isdigit() else None, "message": subject[:300]}


def fetch(site, rid, url, branch, log=lambda line: None):
    ensure_key(site, rid)
    log(f"Fetching {branch} from {url}")
    try:
        git(site, rid, ["fetch", "--quiet", "--prune", "--no-tags", url, f"+refs/heads/{branch}:refs/heads/{branch}"], timeout=900)
    except ActionError as exc:
        raise _friendly(exc)
    return commit_info(site, rid, f"refs/heads/{branch}")


def _target(site, path):
    target = os.path.join(site["root"], path)
    real_root = os.path.realpath(site["root"])
    parent = os.path.realpath(os.path.dirname(target))
    if parent != real_root and not parent.startswith(real_root + os.sep):
        raise ActionError("forbidden", "path leaves the website folder")
    return target


def _php(site):
    return f"/usr/bin/php{site['php']}"


def _tool_env(site):
    tmp = os.path.join(site["root"], "tmp")
    return {"COMPOSER_HOME": f"{tmp}/composer", "COMPOSER_CACHE_DIR": f"{tmp}/composer-cache",
            "npm_config_cache": f"{tmp}/npm-cache", "npm_config_update_notifier": "false",
            "WHP_PHP": _php(site), "WHP_DOMAIN": site["domain"], "CI": "1"}


def step_argv(site, step, workdir):
    """The command line of one deploy step, or None when it does not apply to this repository."""
    t = step["type"]
    if t == "composer":
        if not os.path.isfile(os.path.join(workdir, "composer.json")):
            return None
        return [_php(site), COMPOSER, "install", "--no-dev", "--no-interaction", "--prefer-dist",
                "--optimize-autoloader", "--no-progress"]
    if t == "npm":
        if not os.path.isfile(os.path.join(workdir, "package.json")):
            return None
        return [NPM, "ci" if os.path.isfile(os.path.join(workdir, "package-lock.json")) else "install", "--no-audit", "--no-fund"]
    if t in ("artisan_migrate", "artisan_optimize"):
        if not os.path.isfile(os.path.join(workdir, "artisan")):
            return None
        return [_php(site), "artisan", "migrate" if t == "artisan_migrate" else "optimize", *(["--force"] if t == "artisan_migrate" else [])]
    if t == "command":
        return ["/bin/bash", "-eo", "pipefail", "-c", step["command"]]
    return None


def _npm_build(workdir):
    try:
        with open(os.path.join(workdir, "package.json")) as fh:
            return "build" in (json.load(fh).get("scripts") or {})
    except (OSError, ValueError, AttributeError):
        return False


def run_steps(site, steps, workdir, log):
    done = []
    for step in steps:
        argv = step_argv(site, step, workdir)
        label = step["command"].splitlines()[0][:80] if step["type"] == "command" else step["type"]
        if argv is None:
            log(f"Skipped {label}: not used by this repository")
            done.append({"type": step["type"], "status": "skipped"})
            continue
        commands = [argv]
        if step["type"] == "npm" and _npm_build(workdir):
            commands.append([NPM, "run", "build"])
        started = time.time()
        for cmd in commands:
            log("$ " + (step["command"] if step["type"] == "command" else " ".join(os.path.basename(cmd[0]) if i == 0 else a for i, a in enumerate(cmd))))
            proc = as_user(site, cmd, cwd=workdir, timeout=STEP_TIMEOUT, check=False, env=_tool_env(site))
            for line in ((proc.stdout or "") + (proc.stderr or "")).strip().splitlines()[-40:]:
                log("  " + line)
            if proc.returncode != 0:
                done.append({"type": step["type"], "status": "failed", "exit_code": proc.returncode})
                raise ActionError("command_failed", f"Deploy step {label} failed (exit code {proc.returncode})", {"steps": done})
        done.append({"type": step["type"], "status": "ok", "seconds": round(time.time() - started, 1)})
    return done


def _link_shared(site, rid, release, shared, log):
    d = repo_dir(site, rid)
    for p, is_dir in shared:
        src = os.path.join(release, p)
        dst = os.path.join(d, "shared", p)
        as_user(site, ["/usr/bin/mkdir", "-p", os.path.dirname(dst), os.path.dirname(src)])
        if not os.path.lexists(dst):
            if os.path.lexists(src):
                as_user(site, ["/usr/bin/mv", "-T", src, dst])
                log(f"Shared {p}: kept the version from this release")
            elif is_dir:
                as_user(site, ["/usr/bin/mkdir", "-p", dst])
            else:
                as_user(site, ["/usr/bin/touch", dst])
        as_user(site, ["/usr/bin/rm", "-rf", src])
        as_user(site, ["/usr/bin/ln", "-s", dst, src])


def releases(site, rid):
    d = os.path.join(repo_dir(site, rid), "releases")
    try:
        return sorted((e for e in os.listdir(d) if _RELEASE.fullmatch(e)), reverse=True)
    except OSError:
        return []


def current_release(site, rid, path):
    target = os.path.join(site["root"], path)
    if not os.path.islink(target):
        return None
    name = os.path.basename(os.readlink(target).rstrip("/"))
    return name if _RELEASE.fullmatch(name) else None


def _switch(site, rid, path, release, log):
    """Point the deploy folder at a release in one rename (zero downtime)."""
    target = _target(site, path)
    rel_dir = os.path.join(repo_dir(site, rid), "releases", release)
    as_user(site, ["/usr/bin/mkdir", "-p", os.path.dirname(target)])
    if os.path.lexists(target) and not os.path.islink(target):
        backup = f"{target}.before-git-{time.strftime('%Y%m%d%H%M%S')}"
        as_user(site, ["/usr/bin/mv", "-T", target, backup])
        log(f"Moved the existing folder {path} to {os.path.basename(backup)}")
    tmp = f"{target}.whp-switch"
    as_user(site, ["/usr/bin/ln", "-sfn", rel_dir, tmp])
    as_user(site, ["/usr/bin/mv", "-Tf", tmp, target])
    # PHP caches resolved paths for a while: a graceful reload makes it see the new release now.
    if site.get("id"):
        run(["systemctl", "reload", f"whp-site-{site['id']}.service"], check=False, timeout=60)
    log(f"Switched {path} to release {release}")


def _prune(site, rid, keep, current, log):
    for old in releases(site, rid)[keep:]:
        if old != current:
            as_user(site, ["/usr/bin/rm", "-rf", os.path.join(repo_dir(site, rid), "releases", old)])
            log(f"Removed old release {old}")


def _deploy_params(params):
    mode = params.get("mode", "direct")
    if mode not in MODES:
        raise ActionError("invalid_params", "mode must be direct or releases")
    keep = params.get("keep", 5)
    if not isinstance(keep, int) or isinstance(keep, bool) or not 1 <= keep <= 20:
        raise ActionError("invalid_params", "keep must be 1 to 20")
    commit = params.get("commit")
    if commit is not None and (not isinstance(commit, str) or not _SHA.fullmatch(commit)):
        raise ActionError("invalid_params", "commit must be a full commit hash")
    return {
        "site": _site(params), "id": _id(params), "url": _url(params), "branch": _branch(params),
        "path": rel_path(params.get("path")), "mode": mode, "keep": keep, "commit": commit,
        "shared": _shared(params) if mode == "releases" else [], "steps": _steps(params),
    }


def deploy(p, log):
    site, rid, path = p["site"], p["id"], p["path"]
    started = time.time()
    with _Lock(site, rid):
        info = fetch(site, rid, p["url"], p["branch"], log)
        if p["commit"]:
            info = commit_info(site, rid, p["commit"])
        sha = info["commit"]
        log(f"Deploying {info['short']} {info['message']!r} by {info['author']}")
        result = {"commit": info, "mode": p["mode"], "path": path, "release": None, "steps": []}

        if p["mode"] == "direct":
            target = _target(site, path)
            if os.path.islink(target):
                raise ActionError("conflict", f"{path} is a link to a release; remove the repository or choose zero-downtime mode")
            as_user(site, ["/usr/bin/mkdir", "-p", target])
            try:
                # Like a post-receive hook: files the previous commit had and this one does not are
                # removed, files that were never in the repository (uploads) stay.
                git(site, rid, [f"--work-tree={target}", "checkout", "--force", "--detach", sha])
            except ActionError as exc:
                raise _friendly(exc)
            log(f"Checked out {info['short']} into {path}")
            result["steps"] = run_steps(site, p["steps"], target, log)
        else:
            release = f"{time.strftime('%Y%m%d%H%M%S')}-{sha[:7]}"
            rel_dir = os.path.join(repo_dir(site, rid), "releases", release)
            as_user(site, ["/usr/bin/mkdir", "-p", rel_dir])
            try:
                git(site, rid, [f"--work-tree={rel_dir}", "checkout", "--force", sha, "--", "."],
                    env={"GIT_INDEX_FILE": f"{repo_dir(site, rid)}/release.index"})
                as_user(site, ["/usr/bin/rm", "-f", f"{repo_dir(site, rid)}/release.index"])
                log(f"Release {release} prepared")
                _link_shared(site, rid, rel_dir, p["shared"], log)
                result["steps"] = run_steps(site, p["steps"], rel_dir, log)
            except ActionError as exc:
                as_user(site, ["/usr/bin/rm", "-rf", rel_dir], check=False)
                log("The website was not changed: it still runs the previous release")
                raise exc if exc.code != "command_failed" or exc.details.get("steps") else _friendly(exc)
            _switch(site, rid, path, release, log)
            _prune(site, rid, p["keep"], release, log)
            result["release"] = release

        state = read_state(site, rid)
        state.update({"deployed": info, "deployed_at": int(time.time()), "path": path, "mode": p["mode"],
                      "release": result["release"]})
        write_state(site, rid, state)
        result["seconds"] = round(time.time() - started, 1)
        log(f"Done in {result['seconds']} s")
        return result


# ----------------------------------------------------------------- actions

@action("git.key")
def key(params):
    """The deploy key of a repository (made on first use). rotate=true makes a new one."""
    site, rid = _site(params), _id(params)
    return ensure_key(site, rid, rotate=bool(params.get("rotate")))


@action("git.check")
def check(params):
    """Test access to the remote and list its branches (git ls-remote)."""
    site, rid, url = _site(params), _id(params), _url(params)
    ensure_key(site, rid)
    try:
        proc = git(site, rid, ["ls-remote", "--symref", url, "HEAD", "refs/heads/*"], timeout=60)
    except ActionError as exc:
        raise _friendly(exc)
    branches, default = [], None
    for line in proc.stdout.splitlines():
        if line.startswith("ref: ") and line.endswith("\tHEAD"):
            default = line[5:].split("\t")[0].removeprefix("refs/heads/")
        elif "\trefs/heads/" in line:
            sha, ref = line.split("\t", 1)
            branches.append({"name": ref.removeprefix("refs/heads/"), "commit": sha})
    return {"branches": sorted(branches, key=lambda b: (b["name"] != default, b["name"])), "default": default}


@action("git.fetch")
def fetch_action(params):
    """Fetch the branch and return its newest commit (used to show 'new commits' without deploying)."""
    site, rid = _site(params), _id(params)
    info = fetch(site, rid, _url(params), _branch(params))
    return {"latest": info, "deployed": read_state(site, rid).get("deployed")}


@action("git.deploy")
def deploy_action(params):
    """Job: fetch and deploy. Params: domain, id, url, branch, path, mode (direct|releases),
    keep, shared[] ("storage/", ".env"), steps[{type, command?}], allow_commands, commit?"""
    p = _deploy_params(params)
    return start_job("git.deploy", deploy, p)


@action("git.status")
def status(params):
    site, rid = _site(params), _id(params)
    path = rel_path(params.get("path"))
    state = read_state(site, rid)
    current = current_release(site, rid, path)
    rel = []
    for name in releases(site, rid):
        rel.append({"name": name, "commit": name.split("-", 1)[1], "current": name == current,
                    "created": int(time.mktime(time.strptime(name[:14], "%Y%m%d%H%M%S")))})
    has_key = os.path.isfile(os.path.join(repo_dir(site, rid), "deploy_key.pub"))
    return {"deployed": state.get("deployed"), "deployed_at": state.get("deployed_at"), "current_release": current,
            "releases": rel, "has_key": has_key, "path_is_link": os.path.islink(os.path.join(site["root"], path))}


@action("git.rollback")
def rollback(params):
    """Switch the deploy folder back to an earlier release (zero-downtime mode only)."""
    site, rid = _site(params), _id(params)
    path = rel_path(params.get("path"))
    release = v.string(params, "release", max_len=60)
    if not _RELEASE.fullmatch(release) or release not in releases(site, rid):
        raise ActionError("not_found", "Unknown release")
    with _Lock(site, rid):
        lines = []
        _switch(site, rid, path, release, lines.append)
        state = read_state(site, rid)
        state.update({"release": release, "rolled_back_at": int(time.time())})
        write_state(site, rid, state)
    return {"release": release, "log": lines}


@action("git.remove")
def remove(params):
    """Remove the repository from the website. The deployed files stay: in zero-downtime mode
    the current release is copied into a normal folder first, so the website keeps working."""
    site, rid = _site(params), _id(params)
    path = rel_path(params.get("path")) if params.get("path") else None
    with _Lock(site, rid):
        kept = False
        if path and current_release(site, rid, path):
            target = _target(site, path)
            src = os.readlink(target)
            tmp = f"{target}.whp-copy"
            as_user(site, ["/usr/bin/rm", "-rf", tmp])
            as_user(site, ["/usr/bin/cp", "-a", f"{src}/.", tmp], timeout=1800)
            # Shared files are links into git/<id>/shared/: put the real files in place.
            for p, _is_dir in _shared(params):
                shared = os.path.join(repo_dir(site, rid), "shared", p)
                if os.path.lexists(shared):
                    as_user(site, ["/usr/bin/rm", "-rf", os.path.join(tmp, p)])
                    as_user(site, ["/usr/bin/cp", "-a", "-T", shared, os.path.join(tmp, p)], timeout=1800)
            as_user(site, ["/usr/bin/rm", "-f", target])
            as_user(site, ["/usr/bin/mv", "-T", tmp, target])
            kept = True
        if os.path.lexists(repo_dir(site, rid)):
            as_user(site, ["/usr/bin/rm", "-rf", repo_dir(site, rid)], timeout=600)
    try:
        os.remove(_state_file(site, rid))
    except FileNotFoundError:
        pass
    return {"removed": rid, "files_kept": kept}


@action("git.tools")
def tools(params):
    """Which build tools this server has (the panel hides steps that cannot run)."""
    return {"git": os.path.exists(GIT), "composer": os.path.exists(COMPOSER),
            "npm": os.path.exists(NPM), "node": os.path.exists("/usr/bin/node")}
