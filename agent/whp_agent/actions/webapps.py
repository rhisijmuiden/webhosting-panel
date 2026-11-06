"""Node.js and Python apps next to PHP (panel: Node.js & Python).

A website can run long-lived apps (Express, Next.js, Nuxt, Fastify, Django,
Flask, FastAPI ...). Each app is a systemd service that runs as the site's own
Linux user, in the subscription's resource jail (slice) with its own memory and
CPU caps, listens on 127.0.0.1:<port> and is reached through Nginx:

- mounted on "/": the whole site is the app (`whp-site proxy <domain> <port>`),
- mounted on "/api" (any sub path): a location snippet in
  /etc/nginx/whp/sites/<domain>/webapp-<id>.conf, PHP keeps serving the rest.

Runtimes are native builds below /opt/whp-runtimes, one folder per version:

    node/<major>/bin/node        official nodejs.org build (SHASUMS256 checked)
    python/bin/python3.<minor>   python-build-standalone through uv

Files per app (all root owned, the panel is the source of truth and sends the
full settings with every webapp.apply):

    /etc/systemd/system/whp-webapp-<id>.service
    /etc/webhosting-panel/webapps/<id>.env     environment (0600, read by systemd)
    /var/lib/webhosting-panel/webapps/<id>.json state (port, runtime, folder ...)

Dependencies (npm ci / uv pip install) and the build command run as the site
user through systemd-run, never as root. Git deployments call prepare_deploy()
before a release goes live and restart_after_deploy() after it.
"""

import fcntl
import json
import os
import re
import shutil
import tempfile
import time
import urllib.request

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

RUNTIME_DIR = os.environ.get("WHP_RUNTIME_DIR", "/opt/whp-runtimes")
UV = os.environ.get("WHP_UV", "/usr/local/bin/uv")
SYSTEMD_DIR = os.environ.get("WHP_SYSTEMD_DIR", "/etc/systemd/system")
ETC_DIR = os.path.join(paths.CONFIG_DIR, "webapps")
STATE_DIR = os.path.join(paths.STATE_DIR, "webapps")
NGINX_SITES = os.environ.get("WHP_NGINX_SITES", "/etc/nginx/whp/sites")
WHP_SITE = "/usr/local/sbin/whp-site"
NODE_DIST = "https://nodejs.org/dist"
PORT_MIN, PORT_MAX = 30000, 39999
STEP_TIMEOUT = 1800

# Versions offered, with their end of life (upstream support ends). After that
# date a version is no longer offered for new apps; running apps keep working.
NODE_VERSIONS = {"22": "2027-04-30", "24": "2028-04-30", "26": "2029-04-30"}
PYTHON_VERSIONS = {"3.11": "2027-10-31", "3.12": "2028-10-31", "3.13": "2029-10-31", "3.14": "2030-10-31"}
RUNTIMES = ("node", "python")

_ID = r"[a-f0-9]{10}"
_SEG = r"[A-Za-z0-9._~-]{1,64}"
_MOUNT = re.compile(rf"^/(?:{_SEG}(?:/{_SEG}){{0,5}})?$")
_ENV_KEY = re.compile(r"^[A-Za-z_][A-Za-z0-9_]{0,63}$")
# Set by the panel itself; an app cannot override them.
RESERVED_ENV = {"PORT", "HOST", "HOSTNAME"}


# ----------------------------------------------------------------- runtimes

def node_dir(major):
    return os.path.join(RUNTIME_DIR, "node", major)


def python_bin(minor):
    return os.path.join(RUNTIME_DIR, "python", "bin", f"python{minor}")


def runtime_bin_dir(runtime, version):
    return os.path.join(node_dir(version), "bin") if runtime == "node" else os.path.join(RUNTIME_DIR, "python", "bin")


def is_installed(runtime, version):
    if runtime == "node":
        return os.path.isfile(os.path.join(node_dir(version), "bin", "node"))
    return os.path.isfile(python_bin(version))


def installed_version(runtime, version):
    """The full version on disk: "v24.11.1" / "3.13.9", or None."""
    if not is_installed(runtime, version):
        return None
    if runtime == "node":
        try:
            with open(os.path.join(node_dir(version), ".whp-version")) as fh:
                return fh.read().strip() or None
        except OSError:
            pass
        proc = run([os.path.join(node_dir(version), "bin", "node"), "--version"], timeout=10, check=False)
        return proc.stdout.strip() or None
    proc = run([python_bin(version), "--version"], timeout=10, check=False)
    out = (proc.stdout or proc.stderr or "").strip()
    return out.split()[-1] if out else None


def _versions(runtime):
    return NODE_VERSIONS if runtime == "node" else PYTHON_VERSIONS


def is_eol(runtime, version, today=None):
    eol = _versions(runtime).get(version)
    return bool(eol) and (today or time.strftime("%Y-%m-%d")) > eol


def _node_index(max_age=6 * 3600):
    """nodejs.org/dist/index.json (newest release per major), cached for a few hours."""
    cache = os.path.join(STATE_DIR, "node-index.json")
    try:
        if time.time() - os.path.getmtime(cache) < max_age:
            with open(cache) as fh:
                return json.load(fh)
    except (OSError, ValueError):
        pass
    try:
        req = urllib.request.Request(f"{NODE_DIST}/index.json", headers={"User-Agent": "webhosting-panel-agent"})
        with urllib.request.urlopen(req, timeout=10) as resp:
            rows = json.loads(resp.read(5_000_000))
        latest = {}
        for r in rows:
            major = r.get("version", "").lstrip("v").split(".")[0]
            if major in NODE_VERSIONS and major not in latest:
                latest[major] = {"version": r["version"], "lts": r.get("lts") or None, "date": r.get("date")}
        os.makedirs(STATE_DIR, mode=0o750, exist_ok=True)
        with open(cache + ".tmp", "w") as fh:
            json.dump(latest, fh)
        os.replace(cache + ".tmp", cache)
        return latest
    except (OSError, ValueError, KeyError):
        try:
            with open(cache) as fh:
                return json.load(fh)
        except (OSError, ValueError):
            return {}


def _vkey(text):
    return tuple(int(x) for x in re.findall(r"\d+", text or "")[:3])


@action("runtime.list")
def runtime_list(params):
    """Every offered Node.js and Python version: installed, full version, end of life, apps using it."""
    usage = {}
    for st in all_states():
        k = f"{st.get('runtime')}:{st.get('version')}"
        usage[k] = usage.get(k, 0) + 1
    latest = _node_index() if params.get("check_latest", True) else {}
    out = {"node": [], "python": [], "uv": os.path.isfile(UV)}
    for runtime in RUNTIMES:
        for ver, eol in _versions(runtime).items():
            full = installed_version(runtime, ver)
            row = {"version": ver, "installed": full is not None, "full_version": full, "eol_date": eol,
                   "eol": is_eol(runtime, ver), "apps": usage.get(f"{runtime}:{ver}", 0)}
            if runtime == "node":
                info = latest.get(ver) or {}
                row["lts"] = info.get("lts")
                row["latest"] = info.get("version")
                row["update_available"] = bool(full and info.get("version") and _vkey(info["version"]) > _vkey(full))
            out[runtime].append(row)
    return out


def _runtime_params(params):
    runtime = v.choice(params, "runtime", RUNTIMES)
    version = v.choice(params, "version", list(_versions(runtime)))
    return runtime, version


def _download(url, dest, limit=200_000_000):
    req = urllib.request.Request(url, headers={"User-Agent": "webhosting-panel-agent"})
    try:
        with urllib.request.urlopen(req, timeout=120) as resp, open(dest, "wb") as fh:
            total = 0
            while chunk := resp.read(1 << 20):
                total += len(chunk)
                if total > limit:
                    raise ActionError("command_failed", f"Download too large: {url}")
                fh.write(chunk)
    except OSError as exc:
        raise ActionError("command_failed", f"Download failed ({url}): {exc}")


def _node_arch():
    m = os.uname().machine
    return {"x86_64": "x64", "aarch64": "arm64", "arm64": "arm64"}.get(m) or "unsupported"


def install_node(major, log):
    import hashlib

    arch = _node_arch()
    if arch == "unsupported":
        raise ActionError("command_failed", "Node.js has no build for this CPU")
    tmp = tempfile.mkdtemp(prefix="whp-node-")
    try:
        sums = os.path.join(tmp, "SHASUMS256.txt")
        _download(f"{NODE_DIST}/latest-v{major}.x/SHASUMS256.txt", sums, 1_000_000)
        with open(sums) as fh:
            text = fh.read()
        m = re.search(rf"^([0-9a-f]{{64}})\s+(node-(v{major}\.\d+\.\d+)-linux-{arch}\.tar\.xz)$", text, re.M)
        if not m:
            raise ActionError("command_failed", f"No Node.js {major} build for linux-{arch} found")
        digest, name, version = m.groups()
        log(f"Downloading Node.js {version} ({arch})")
        archive = os.path.join(tmp, name)
        _download(f"{NODE_DIST}/{version}/{name}", archive)
        h = hashlib.sha256()
        with open(archive, "rb") as fh:
            for chunk in iter(lambda: fh.read(1 << 20), b""):
                h.update(chunk)
        if h.hexdigest() != digest:
            raise ActionError("command_failed", "The Node.js download does not match its published checksum")
        log("Checksum OK, unpacking")
        new = os.path.join(RUNTIME_DIR, "node", f".{major}.new")
        shutil.rmtree(new, ignore_errors=True)
        os.makedirs(new, mode=0o755)
        run(["tar", "-xJf", archive, "-C", new, "--strip-components=1", "--no-same-owner"], timeout=300)
        with open(os.path.join(new, ".whp-version"), "w") as fh:
            fh.write(version + "\n")
        run(["chmod", "-R", "a+rX,go-w", new], timeout=120)
        final = node_dir(major)
        old = f"{final}.old"
        shutil.rmtree(old, ignore_errors=True)
        if os.path.exists(final):
            os.rename(final, old)
        os.rename(new, final)
        shutil.rmtree(old, ignore_errors=True)
        log(f"Node.js {version} installed in {final}")
        return version
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


def _uv_env():
    return {"UV_PYTHON_INSTALL_DIR": os.path.join(RUNTIME_DIR, "python"),
            "UV_PYTHON_BIN_DIR": os.path.join(RUNTIME_DIR, "python", "bin"),
            "UV_CACHE_DIR": os.path.join(RUNTIME_DIR, "cache", "uv"), "UV_NO_PROGRESS": "1"}


def install_python(minor, log, reinstall=False):
    if not os.path.isfile(UV):
        raise ActionError("command_failed", "uv is not installed (it comes with the panel update)")
    log(f"Installing Python {minor} with uv")
    argv = [UV, "python", "install", minor, *(["--reinstall"] if reinstall else [])]
    proc = run(argv, timeout=900, check=False, env=_uv_env())
    for line in ((proc.stdout or "") + (proc.stderr or "")).strip().splitlines()[-10:]:
        log("  " + line)
    if proc.returncode != 0 or not os.path.isfile(python_bin(minor)):
        raise ActionError("command_failed", f"Python {minor} could not be installed")
    run(["chmod", "-R", "a+rX,go-w", os.path.join(RUNTIME_DIR, "python")], timeout=120)
    full = installed_version("python", minor)
    log(f"Python {full} installed")
    return full


@action("runtime.install")
def runtime_install(params):
    """Job: install (or update to the newest patch release) one Node.js or Python version."""
    runtime, version = _runtime_params(params)
    if is_eol(runtime, version) and not is_installed(runtime, version):
        raise ActionError("forbidden", f"{runtime} {version} has reached its end of life")

    def work(p, log):
        os.makedirs(os.path.join(RUNTIME_DIR, runtime), mode=0o755, exist_ok=True)
        was = is_installed(runtime, version)
        full = install_node(version, log) if runtime == "node" else install_python(version, log, reinstall=was)
        restarted = []
        if was:
            # Running apps keep the old files open until they restart: restart them on the new build.
            for st in all_states():
                if st.get("runtime") == runtime and st.get("version") == version and st.get("enabled"):
                    run(["systemctl", "try-restart", unit_name(st["id"])], timeout=60, check=False)
                    restarted.append(st["id"])
            if restarted:
                log(f"Restarted {len(restarted)} app(s) on the new version")
        return {"runtime": runtime, "version": version, "full_version": full, "restarted": restarted}

    return start_job("runtime.install", work, params)


@action("runtime.remove")
def runtime_remove(params):
    runtime, version = _runtime_params(params)
    users = [st["id"] for st in all_states() if st.get("runtime") == runtime and st.get("version") == version]
    if users:
        raise ActionError("conflict", f"{len(users)} app(s) still use {runtime} {version}")
    if runtime == "node":
        shutil.rmtree(node_dir(version), ignore_errors=True)
    elif os.path.isfile(UV):
        run([UV, "python", "uninstall", version], timeout=300, check=False, env=_uv_env())
        if os.path.lexists(python_bin(version)):
            os.unlink(python_bin(version))
    return {"runtime": runtime, "version": version, "removed": True}


# ----------------------------------------------------------------- app state

def unit_name(app_id):
    return f"whp-webapp-{app_id}.service"


def _state_file(app_id):
    return os.path.join(STATE_DIR, f"{app_id}.json")


def read_state(app_id):
    try:
        with open(_state_file(app_id)) as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return None


def write_state(st):
    os.makedirs(STATE_DIR, mode=0o750, exist_ok=True)
    tmp = _state_file(st["id"]) + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(st, fh, indent=1)
    os.replace(tmp, _state_file(st["id"]))


def all_states():
    try:
        names = sorted(os.listdir(STATE_DIR))
    except OSError:
        return []
    out = []
    for n in names:
        if re.fullmatch(rf"{_ID}\.json", n):
            st = read_state(n[:-5])
            if st:
                out.append(st)
    return out


class _PortLock:
    def __enter__(self):
        os.makedirs(STATE_DIR, mode=0o750, exist_ok=True)
        self.fh = open(os.path.join(STATE_DIR, ".ports.lock"), "w")
        fcntl.flock(self.fh, fcntl.LOCK_EX)
        return self

    def __exit__(self, *exc):
        fcntl.flock(self.fh, fcntl.LOCK_UN)
        self.fh.close()


def allocate_port(app_id):
    """The app's own port, or the lowest free one in 30000-39999 (kept for the app's lifetime)."""
    taken = {}
    for st in all_states():
        if st.get("port"):
            taken[int(st["port"])] = st["id"]
    for port, owner in taken.items():
        if owner == app_id:
            return port
    for port in range(PORT_MIN, PORT_MAX + 1):
        if port not in taken:
            return port
    raise ActionError("conflict", "No free port left for apps")


# ----------------------------------------------------------------- validation

def _app_id(params):
    return v.string(params, "id", max_len=10, pattern=_ID)


def _mount(params):
    m = v.string(params, "mount", required=False, default="/", max_len=200) or "/"
    m = "/" + m.strip().strip("/") if m.strip() not in ("", "/") else "/"
    if not _MOUNT.fullmatch(m) or any(s in (".", "..") for s in m.split("/")[1:]):
        raise ActionError("invalid_params", "mount must be / or a path like /api")
    if m.split("/")[1:2] and m.split("/")[1] in (".well-known", "whp-suspended.html"):
        raise ActionError("forbidden", "This path is used by the server")
    return m


def _env(params):
    env = params.get("env") or {}
    if not isinstance(env, dict) or len(env) > 100:
        raise ActionError("invalid_params", "env must be an object with at most 100 variables")
    out = {}
    for key, value in env.items():
        if not isinstance(key, str) or not _ENV_KEY.fullmatch(key):
            raise ActionError("invalid_params", f"Invalid variable name: {str(key)[:40]}")
        if key.upper() in RESERVED_ENV:
            raise ActionError("invalid_params", f"{key} is set by the panel")
        if not isinstance(value, str) or len(value) > 8192 or any(c in value for c in "\x00\n\r"):
            raise ActionError("invalid_params", f"{key}: the value must be one line of at most 8192 characters")
        out[key] = value
    return out


def _command(params, key, required):
    cmd = v.string(params, key, required=required, max_len=1000)
    if cmd is None or not cmd.strip():
        if required:
            raise ActionError("invalid_params", f"{key} may not be empty")
        return None
    if "\r" in cmd:
        raise ActionError("invalid_params", f"{key} contains invalid characters")
    return cmd.strip()


def _app_params(params):
    from .git import rel_path  # same rules as a Git deploy folder

    runtime, version = _runtime_params(params)
    return {
        "id": _app_id(params),
        "domain": v.domain(params, "domain"),
        "name": (v.string(params, "name", required=False, default="", max_len=80) or "").strip(),
        "runtime": runtime,
        "version": version,
        "root": rel_path(params.get("root")),
        "mount": _mount(params),
        "strip_prefix": bool(v.boolean(params, "strip_prefix", required=False, default=False)),
        "start": _command(params, "start", True),
        "build": _command(params, "build", False),
        "env": _env(params),
        "memory_mb": v.integer(params, "memory_mb", required=False, minimum=64, maximum=1_000_000),
        "cpu_percent": v.integer(params, "cpu_percent", required=False, minimum=10, maximum=10_000),
        "tasks": v.integer(params, "tasks", required=False, default=512, minimum=16, maximum=100_000),
        "enabled": bool(v.boolean(params, "enabled", required=False, default=True)),
        "deps_on_deploy": bool(v.boolean(params, "deps_on_deploy", required=False, default=True)),
    }


def _site_for(domain):
    site = wordpress.site_info(domain)
    if not re.fullmatch(r"s_[a-z0-9_]{1,30}", site.get("user") or ""):
        raise ActionError("internal", f"Website {domain} has no valid system user")
    return site


def app_dir(site, root):
    return os.path.join(site["root"], root)


# ----------------------------------------------------------------- files

def _unit_quote(text):
    """One argument for ExecStart: systemd unquotes it, so escape quotes, backslashes, % and $."""
    return '"' + text.replace("\\", "\\\\").replace('"', '\\"').replace("%", "%%").replace("$", "$$") + '"'


def _unit_env(text):
    """One Environment= assignment (no $ expansion there, only specifiers)."""
    return '"' + text.replace("\\", "\\\\").replace('"', '\\"').replace("%", "%%") + '"'


def _env_quote(value):
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"').replace("$", "\\$").replace("`", "\\`") + '"'


def base_env(site, app, port):
    """Variables every app gets; the app's own variables come on top (EnvironmentFile wins)."""
    d = app_dir(site, app["root"])
    bins = runtime_bin_dir(app["runtime"], app["version"])
    if app["runtime"] == "node":
        path = f"{d}/node_modules/.bin:{bins}:/usr/local/bin:/usr/bin:/bin"
        extra = {"NODE_ENV": "production", "npm_config_cache": f"{site['root']}/tmp/npm-cache",
                 "npm_config_update_notifier": "false"}
    else:
        path = f"{d}/.venv/bin:{bins}:/usr/local/bin:/usr/bin:/bin"
        extra = {"VIRTUAL_ENV": f"{d}/.venv", "PYTHONUNBUFFERED": "1", "PYTHONDONTWRITEBYTECODE": "1"}
    return {"PATH": path, "HOME": site["root"], "TMPDIR": f"{site['root']}/tmp", "LANG": "C.UTF-8", **extra}


def render_unit(site, app, port):
    d = app_dir(site, app["root"])
    label = "Node.js" if app["runtime"] == "node" else "Python"
    env_lines = "".join(f"Environment={_unit_env(f'{k}={val}')}\n" for k, val in base_env(site, app, port).items())
    limits = ""
    if app.get("memory_mb"):
        mem = int(app["memory_mb"])
        # Reclaim (slow down) at 90 %, stop the process only at the hard cap.
        limits += f"MemoryHigh={max(32, mem * 9 // 10)}M\nMemoryMax={mem}M\nMemorySwapMax=0\n"
    if app.get("cpu_percent"):
        limits += f"CPUQuota={int(app['cpu_percent'])}%\n"
    name = (app.get("name") or app["id"]).replace("\n", " ").replace("%", "%%")
    return (
        f"# Managed by whp-agent (Node.js & Python apps). Changes are overwritten.\n"
        "[Unit]\n"
        f"Description={label} app {name} on {site['domain']}\n"
        "After=network-online.target mariadb.service valkey-server.service\n"
        "Wants=network-online.target\n"
        "StartLimitIntervalSec=300\nStartLimitBurst=10\n\n"
        "[Service]\n"
        "Type=simple\n"
        f"User={site['user']}\nGroup={site['user']}\n"
        f"Slice={site.get('slice') or 'whp-sites.slice'}\n"
        f"WorkingDirectory={d}\n"
        f"{env_lines}"
        f"Environment=PORT={port} HOST=127.0.0.1 HOSTNAME=127.0.0.1\n"
        f"EnvironmentFile=-{os.path.join(ETC_DIR, app['id'] + '.env')}\n"
        f"ExecStart=/bin/sh -c {_unit_quote('exec ' + app['start'])}\n"
        "Restart=on-failure\nRestartSec=3s\n"
        "TimeoutStartSec=60s\nTimeoutStopSec=20s\nKillMode=control-group\n"
        f"{limits}"
        f"TasksMax={int(app.get('tasks') or 512)}\n"
        "Nice=5\n"
        f"SyslogIdentifier=whp-webapp-{app['id']}\n"
        "UMask=0022\n"
        "NoNewPrivileges=yes\n"
        "PrivateTmp=yes\nPrivateDevices=yes\n"
        "ProtectSystem=strict\n"
        f"ReadWritePaths={site['root']}\n"
        "ProtectHome=yes\n"
        "ProtectKernelTunables=yes\nProtectKernelModules=yes\nProtectKernelLogs=yes\n"
        "ProtectControlGroups=yes\nProtectClock=yes\nProtectHostname=yes\nProtectProc=invisible\n"
        "RestrictSUIDSGID=yes\nRestrictRealtime=yes\nRestrictNamespaces=yes\nLockPersonality=yes\n"
        "RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX\n"
        # The app may listen on its own port only (never squat another customer's).
        f"SocketBindAllow=tcp:{port}\nSocketBindDeny=any\n"
        "\n[Install]\nWantedBy=multi-user.target\n"
    )


def render_env_file(app):
    lines = ["# Managed by whp-agent: environment variables of this app (panel: Node.js & Python)."]
    lines += [f"{k}={_env_quote(val)}" for k, val in sorted(app["env"].items())]
    return "\n".join(lines) + "\n"


def render_snippet(app, port):
    """Nginx locations for an app on a sub path; the rest of the site stays PHP."""
    m = app["mount"]
    upstream = f"http://127.0.0.1:{port}" + ("/" if app.get("strip_prefix") else "")
    return (
        f"# Managed by whp-agent (Node.js & Python apps): {(app.get('name') or app['id'])[:80]}\n"
        f"location = {m} {{ return 301 {m}/$is_args$args; }}\n"
        f"location ^~ {m}/ {{\n"
        f"    proxy_pass {upstream};\n"
        "    include /etc/nginx/whp/proxy.conf;\n"
        f"    error_page 502 503 504 @whp_webapp_{app['id']};\n"
        "}\n"
        f"location @whp_webapp_{app['id']} {{\n"
        "    root /etc/nginx/whp/pages;\n"
        "    try_files /app-down.html =502;\n"
        "}\n"
    )


def _write(path, text, mode):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = f"{path}.whp-new"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, mode)
    with os.fdopen(fd, "w") as fh:
        fh.write(text)
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def _snippet_path(domain, app_id):
    return os.path.join(NGINX_SITES, domain, f"webapp-{app_id}.conf")


def _reload_nginx():
    run(["nginx", "-t"], timeout=60)
    run(["systemctl", "reload", "nginx"], timeout=60)


def _site_proxy(domain, port):
    """Serve the whole site from the app (port) or from PHP again (None)."""
    run([WHP_SITE, "proxy", domain, str(port) if port else "off", "--json"], timeout=180)


def _root_app(domain, exclude=None):
    for st in all_states():
        if st.get("domain") == domain and st.get("mount") == "/" and st["id"] != exclude:
            return st
    return None


def apply_nginx(site, app, port, old):
    domain = site["domain"]
    snippet = _snippet_path(domain, app["id"])
    if app["mount"] == "/":
        if os.path.exists(snippet):
            os.unlink(snippet)
        if not old or old.get("mount") != "/" or old.get("port") != port or old.get("domain") != domain:
            _site_proxy(domain, port)
        else:
            _reload_nginx()
        return
    os.makedirs(os.path.dirname(snippet), mode=0o755, exist_ok=True)
    previous = None
    if os.path.exists(snippet):
        with open(snippet) as fh:
            previous = fh.read()
    _write(snippet, render_snippet(app, port), 0o644)
    try:
        if old and old.get("mount") == "/" and old.get("domain") == domain:
            _site_proxy(domain, None)  # also tests and reloads nginx
        else:
            _reload_nginx()
    except ActionError:
        if previous is None:
            os.unlink(snippet)
        else:
            _write(snippet, previous, 0o644)
        raise


def remove_nginx(st):
    snippet = _snippet_path(st["domain"], st["id"])
    if os.path.exists(snippet):
        os.unlink(snippet)
        _reload_nginx()
    if st.get("mount") == "/":
        try:
            _site_proxy(st["domain"], None)
        except ActionError:
            pass  # the website itself may already be gone


# ----------------------------------------------------------------- actions

@action("webapp.apply")
def webapp_apply(params):
    """Create or update an app: unit, environment, Nginx. Starts or restarts it when enabled."""
    app = _app_params(params)
    site = _site_for(app["domain"])
    if not is_installed(app["runtime"], app["version"]):
        label = "Node.js" if app["runtime"] == "node" else "Python"
        raise ActionError("conflict", f"{label} {app['version']} is not installed on this server")
    if app["mount"] == "/" and _root_app(app["domain"], exclude=app["id"]):
        raise ActionError("conflict", "Another app already serves the whole website")
    for st in all_states():
        if st["id"] != app["id"] and st.get("domain") == app["domain"] and st.get("mount") == app["mount"]:
            raise ActionError("conflict", f"Another app already uses {app['mount']}")
    d = app_dir(site, app["root"])
    real_root = os.path.realpath(site["root"])
    if os.path.lexists(d) and not os.path.realpath(d).startswith(real_root + os.sep):
        raise ActionError("forbidden", "The app folder leaves the website folder")
    if not os.path.isdir(d):
        wordpress.as_user(site, ["/usr/bin/mkdir", "-p", d], site["root"])

    old = read_state(app["id"])
    with _PortLock():
        port = allocate_port(app["id"])
        st = {**app, "port": port, "user": site["user"], "updated_at": int(time.time())}
        st.pop("env", None)
        write_state(st)
    _write(os.path.join(ETC_DIR, f"{app['id']}.env"), render_env_file(app), 0o600)
    _write(os.path.join(SYSTEMD_DIR, unit_name(app["id"])), render_unit(site, app, port), 0o644)
    run(["systemctl", "daemon-reload"], timeout=60)
    try:
        apply_nginx(site, app, port, old)
    except ActionError:
        if old:
            write_state(old)
        else:
            run(["systemctl", "stop", unit_name(app["id"])], timeout=60, check=False)
            for f in (_state_file(app["id"]), os.path.join(SYSTEMD_DIR, unit_name(app["id"])),
                      os.path.join(ETC_DIR, f"{app['id']}.env")):
                if os.path.exists(f):
                    os.unlink(f)
        raise
    unit = unit_name(app["id"])
    if app["enabled"] and not site.get("suspended"):
        run(["systemctl", "enable", unit], timeout=60, check=False)
        run(["systemctl", "restart", unit], timeout=90, check=False)
    else:
        run(["systemctl", "disable", "--now", unit], timeout=60, check=False)
    return {"id": app["id"], "port": port, "unit": unit, "status": unit_status(app["id"])}


_SHOW = ("ActiveState", "SubState", "MainPID", "NRestarts", "ActiveEnterTimestamp", "MemoryCurrent",
         "CPUUsageNSec", "Result", "ExecMainStatus", "UnitFileState", "TasksCurrent")


def _num(value):
    return int(value) if value and value.isdigit() and value != "18446744073709551615" else None


def unit_status(app_id):
    proc = run(["systemctl", "show", unit_name(app_id), "--timestamp=unix", "-p", ",".join(_SHOW)],
               timeout=20, check=False)
    data = {}
    for line in (proc.stdout or "").splitlines():
        k, _, val = line.partition("=")
        data[k] = val
    since = data.get("ActiveEnterTimestamp", "")
    active = data.get("ActiveState", "unknown")
    state = {"active": "running", "activating": "starting", "reloading": "running", "deactivating": "stopping",
             "failed": "failed"}.get(active, "stopped")
    if active == "activating" and data.get("SubState") == "auto-restart":
        state = "crashing"
    return {
        "state": state, "active_state": active, "sub_state": data.get("SubState"),
        "pid": _num(data.get("MainPID")) or None, "restarts": _num(data.get("NRestarts")) or 0,
        "since": int(since.lstrip("@")) if since.startswith("@") and since[1:].isdigit() else None,
        "memory_bytes": _num(data.get("MemoryCurrent")), "cpu_seconds": round((_num(data.get("CPUUsageNSec")) or 0) / 1e9, 1),
        "tasks": _num(data.get("TasksCurrent")), "result": data.get("Result"),
        "exit_status": _num(data.get("ExecMainStatus")), "enabled": data.get("UnitFileState") == "enabled",
    }


@action("webapp.status")
def webapp_status(params):
    app_id = _app_id(params)
    st = read_state(app_id)
    if not st:
        raise ActionError("not_found", "Unknown app")
    return {"id": app_id, "port": st.get("port"), **unit_status(app_id)}


@action("webapp.list")
def webapp_list(params):
    """Status of many apps at once (the overview pages): {ids: [...]} or every app of {domain}."""
    ids = params.get("ids")
    domain = v.domain(params, "domain", required=False)
    if ids is not None and (not isinstance(ids, list) or len(ids) > 500
                            or not all(isinstance(i, str) and re.fullmatch(_ID, i) for i in ids)):
        raise ActionError("invalid_params", "ids must be a list of app ids")
    out = {}
    for st in all_states():
        if (ids is not None and st["id"] not in ids) or (domain and st.get("domain") != domain):
            continue
        out[st["id"]] = {"port": st.get("port"), "domain": st.get("domain"), **unit_status(st["id"])}
    return {"apps": out}


@action("webapp.control")
def webapp_control(params):
    app_id = _app_id(params)
    op = v.choice(params, "op", ("start", "stop", "restart"))
    st = read_state(app_id)
    if not st:
        raise ActionError("not_found", "Unknown app")
    unit = unit_name(app_id)
    if op == "stop":
        run(["systemctl", "stop", unit], timeout=60)
    else:
        run(["systemctl", "reset-failed", unit], timeout=20, check=False)
        run(["systemctl", op, unit], timeout=90, check=False)
    return {"id": app_id, "op": op, **unit_status(app_id)}


@action("webapp.logs")
def webapp_logs(params):
    """The app's own output (stdout and stderr) from the journal, newest last."""
    app_id = _app_id(params)
    lines = v.integer(params, "lines", required=False, default=200, minimum=10, maximum=2000)
    if not read_state(app_id):
        raise ActionError("not_found", "Unknown app")
    proc = run(["journalctl", "-u", unit_name(app_id), "-n", str(lines), "--no-pager", "-o", "short-iso",
                "--no-hostname"], timeout=30, check=False)
    rows = [ln for ln in (proc.stdout or "").splitlines() if ln and not ln.startswith("-- ")]
    return {"id": app_id, "lines": rows[-lines:]}


@action("webapp.suspend")
def webapp_suspend(params):
    """Stop (suspended=true) or start again every enabled app of a website."""
    domain = v.domain(params, "domain")
    suspended = v.boolean(params, "suspended")
    done = []
    for st in all_states():
        if st.get("domain") != domain:
            continue
        unit = unit_name(st["id"])
        if suspended:
            run(["systemctl", "disable", "--now", unit], timeout=60, check=False)
        elif st.get("enabled"):
            run(["systemctl", "enable", "--now", unit], timeout=90, check=False)
        done.append(st["id"])
    return {"domain": domain, "suspended": suspended, "apps": done}


@action("webapp.remove")
def webapp_remove(params):
    """Stop the app and remove its unit, environment and Nginx config. The files stay
    (remove_files=true also removes node_modules/ or .venv/ of the app folder)."""
    app_id = _app_id(params)
    st = read_state(app_id)
    unit = unit_name(app_id)
    run(["systemctl", "disable", "--now", unit], timeout=60, check=False)
    for f in (os.path.join(SYSTEMD_DIR, unit), os.path.join(ETC_DIR, f"{app_id}.env")):
        if os.path.exists(f):
            os.unlink(f)
    run(["systemctl", "daemon-reload"], timeout=60, check=False)
    run(["systemctl", "reset-failed", unit], timeout=20, check=False)
    if st:
        remove_nginx(st)
        if params.get("remove_deps") is True:
            try:
                site = _site_for(st["domain"])
                d = app_dir(site, st["root"])
                for sub in ("node_modules", ".venv"):
                    target = os.path.join(d, sub)
                    if os.path.isdir(target) and not os.path.islink(target):
                        wordpress.as_user(site, ["/usr/bin/rm", "-rf", target], site["root"], timeout=600, check=False)
            except ActionError:
                pass
        os.unlink(_state_file(app_id))
    return {"id": app_id, "removed": True}


# ----------------------------------------------------------------- dependencies and build

def _build_argv(site, timeout):
    """systemd-run as the site user (like WP-CLI), with room for big npm builds."""
    base = wordpress.wp_argv(site, site["root"], [], timeout=timeout)
    prefix = base[:base.index(f"/usr/bin/php{site['php']}")]
    return ["MemoryMax=2G" if a == "MemoryMax=1G" else a for a in prefix]


def _as_user(site, argv, cwd, env, timeout=STEP_TIMEOUT):
    prefix = [a for a in _build_argv(site, timeout) if not a.startswith("--working-directory=")]
    full = [*prefix, f"--working-directory={cwd}", "/usr/bin/env", *[f"{k}={val}" for k, val in env.items()], *argv]
    return run(full, timeout=timeout + 15, check=False)


def _tool_env(site, app, workdir):
    env = base_env(site, {**app, "root": os.path.relpath(workdir, site["root"])}, 0)
    env.update({"UV_CACHE_DIR": f"{site['root']}/tmp/uv-cache", "UV_PYTHON_DOWNLOADS": "never",
                "UV_NO_PROGRESS": "1", "CI": "1", "NODE_ENV": "development"})
    return env


def dependency_commands(app, workdir):
    """What to run in the app folder: [(label, argv)]."""
    cmds = []
    has = lambda f: os.path.isfile(os.path.join(workdir, f))  # noqa: E731
    if app["runtime"] == "node":
        npm = os.path.join(runtime_bin_dir("node", app["version"]), "npm")
        if has("package.json"):
            sub = "ci" if has("package-lock.json") or has("npm-shrinkwrap.json") else "install"
            cmds.append((f"npm {sub}", [npm, sub, "--no-audit", "--no-fund"]))
    else:
        py = python_bin(app["version"])
        venv = os.path.join(workdir, ".venv")
        cmds.append(("uv venv", [UV, "venv", "--allow-existing", "--python", py, venv]))
        pip = [UV, "pip", "install", "--python", os.path.join(venv, "bin", "python")]
        if has("requirements.txt"):
            cmds.append(("uv pip install -r requirements.txt", [*pip, "-r", "requirements.txt"]))
        elif has("pyproject.toml"):
            cmds.append(("uv pip install -r pyproject.toml", [*pip, "-r", "pyproject.toml"]))
    if app.get("build"):
        cmds.append((app["build"].splitlines()[0][:80], ["/bin/sh", "-ec", app["build"]]))
    return cmds


def install_deps(site, app, workdir, log):
    if not is_installed(app["runtime"], app["version"]):
        raise ActionError("conflict", f"{app['runtime']} {app['version']} is not installed on this server")
    env = _tool_env(site, app, workdir)
    if app["runtime"] == "node":
        env["NODE_ENV"] = "development"  # build tools are devDependencies
    done = []
    for label, argv in dependency_commands(app, workdir):
        log(f"$ {label}")
        started = time.time()
        proc = _as_user(site, argv, workdir, env)
        for line in ((proc.stdout or "") + (proc.stderr or "")).strip().splitlines()[-40:]:
            log("  " + line)
        if proc.returncode != 0:
            raise ActionError("command_failed", f"{label} failed (exit code {proc.returncode})")
        done.append({"step": label, "seconds": round(time.time() - started, 1)})
    if not done:
        log("Nothing to install: no package.json, requirements.txt or pyproject.toml")
    return done


@action("webapp.install")
def webapp_install(params):
    """Job: install the dependencies (npm ci / uv pip install), run the build command and restart."""
    app_id = _app_id(params)
    st = read_state(app_id)
    if not st:
        raise ActionError("not_found", "Unknown app")
    site = _site_for(st["domain"])

    def work(p, log):
        steps = install_deps(site, st, app_dir(site, st["root"]), log)
        if st.get("enabled") and not site.get("suspended"):
            run(["systemctl", "restart", unit_name(app_id)], timeout=90, check=False)
            log("App restarted")
        return {"id": app_id, "steps": steps, "status": unit_status(app_id)}

    return start_job("webapp.install", work, params)


# ----------------------------------------------------------------- Git deployments

def apps_for_path(site, path):
    """Apps of the site whose folder is the Git deploy folder or inside it."""
    out = []
    for st in all_states():
        root = st.get("root") or ""
        if st.get("domain") == site["domain"] and (root == path or root.startswith(path + "/")):
            out.append(st)
    return out


def prepare_deploy(site, path, workdir, log):
    """Before a deployment goes live: dependencies and build in the new files (a release
    folder in zero-downtime mode). A failure stops the deployment."""
    apps = apps_for_path(site, path)
    for st in apps:
        if not st.get("deps_on_deploy", True):
            continue
        sub = st["root"][len(path):].lstrip("/")
        d = os.path.join(workdir, sub) if sub else workdir
        log(f"App {st.get('name') or st['id']}: installing dependencies")
        install_deps(site, st, d, log)
    return apps


def restart_after_deploy(site, apps, log):
    for st in apps:
        if st.get("enabled") and not site.get("suspended"):
            run(["systemctl", "restart", unit_name(st["id"])], timeout=90, check=False)
            log(f"App {st.get('name') or st['id']} restarted")
