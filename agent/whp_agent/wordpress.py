"""Shared WordPress helpers: find installs and run WP-CLI as the site user.

Used by the WordPress toolkit (actions/wordpress.py), the app installer and
the migration tool. Nothing here runs PHP as root: wp() always starts WP-CLI
as the site's own Linux user, in the sites slice, with the site's PHP version.

    from whp_agent import wordpress
    site = wordpress.site_info("example.com")
    installs = wordpress.detect("example.com")
    proc = wordpress.wp("example.com", ["plugin", "list", "--format=json"], path="")
"""

import os
import re

from . import paths
from .registry import ActionError
from .run import run

WP_CLI = os.environ.get("WHP_WP_CLI", "/usr/local/bin/wp")
SITES_DB = os.environ.get("WHP_SITES_DB", os.path.join(paths.STATE_DIR, "sites"))
SYSTEMD_RUN = "/usr/bin/systemd-run"
# Same pinned release as installer/modules/50-php.sh.
WP_CLI_VERSION = "2.12.0"
WP_CLI_URL = f"https://github.com/wp-cli/wp-cli/releases/download/v{WP_CLI_VERSION}/wp-cli-{WP_CLI_VERSION}.phar"
_VERSION_RE = re.compile(r"^\$wp_version\s*=\s*'([0-9A-Za-z.\-]+)'\s*;", re.M)
_PATH_RE = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}(/[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}){0,3}$")
# Folders in the docroot that never hold a separate WordPress install.
_SKIP_DIRS = {"wp-admin", "wp-content", "wp-includes", "cgi-bin", ".well-known", "node_modules", "vendor"}


def site_info(domain):
    """The state whp-site keeps for a site (user, PHP version, root, docroot)."""
    f = os.path.join(SITES_DB, f"{domain}.env")
    data = {}
    try:
        with open(f) as fh:
            for line in fh:
                key, sep, value = line.rstrip("\n").partition("=")
                if sep:
                    data[key] = value.strip('"')
    except FileNotFoundError:
        raise ActionError("not_found", f"Unknown website: {domain}")
    root = os.path.join(os.environ.get("WHP_VHOSTS", "/var/www/vhosts"), domain)
    docroot_rel = data.get("SITE_DOCROOT_REL") or "public"
    return {
        "domain": domain,
        "id": data.get("SITE_ID", ""),
        "user": data.get("SITE_USER", ""),
        "php": data.get("SITE_PHP") or "8.4",
        "root": root,
        "docroot": os.path.join(root, docroot_rel),
        "ssl": data.get("SITE_SSL", ""),
        "aliases": [a for a in data.get("SITE_ALIASES", "").split(",") if a],
        "suspended": data.get("SITE_SUSPENDED") == "yes",
        "slice": site_slice(data.get("SITE_OWNER", "")),
    }


def site_slice(owner):
    """The systemd slice a site's processes run in: its customer's slice when it has one."""
    if owner and re.fullmatch(r"c[0-9]{1,9}", owner):
        return f"whp-sites-{owner}.slice"
    return "whp-sites.slice"


def clean_path(value):
    """A path of an install below the docroot: "" (the docroot itself) or "blog", "shop/nl"."""
    if value in (None, "", "/"):
        return ""
    if not isinstance(value, str):
        raise ActionError("invalid_params", "path must be a string")
    value = value.strip("/")
    if not _PATH_RE.fullmatch(value) or ".." in value.split("/"):
        raise ActionError("invalid_params", "path has an invalid format")
    return value


def install_dir(site, path=""):
    path = clean_path(path)
    d = os.path.join(site["docroot"], path) if path else site["docroot"]
    real = os.path.realpath(d)
    if real != os.path.realpath(site["docroot"]) and not real.startswith(os.path.realpath(site["docroot"]) + os.sep):
        raise ActionError("forbidden", "path leaves the document root")
    return real


def read_version(wp_dir):
    """The WordPress version from wp-includes/version.php, read as text (no PHP is run)."""
    try:
        with open(os.path.join(wp_dir, "wp-includes", "version.php"), errors="replace") as fh:
            m = _VERSION_RE.search(fh.read(20000))
    except (FileNotFoundError, NotADirectoryError, PermissionError):
        return None
    return m.group(1) if m else None


def _is_install(d):
    has_config = os.path.isfile(os.path.join(d, "wp-config.php")) or os.path.isfile(
        os.path.join(os.path.dirname(d), "wp-config.php"))
    return has_config and os.path.isfile(os.path.join(d, "wp-includes", "version.php"))


def detect(domain, depth=2):
    """All WordPress installs of a site: the docroot and up to `depth` folder levels below it."""
    site = site_info(domain)
    found = []

    def walk(d, rel, level):
        if _is_install(d):
            found.append({"path": rel, "dir": d, "version": read_version(d)})
        if level >= depth:
            return
        try:
            entries = sorted(os.scandir(d), key=lambda e: e.name)
        except OSError:
            return
        for e in entries:
            if e.name.startswith(".") or e.name in _SKIP_DIRS or not e.is_dir(follow_symlinks=False):
                continue
            walk(e.path, f"{rel}/{e.name}" if rel else e.name, level + 1)

    walk(site["docroot"], "", 0)
    return found


def require_install(domain, path=""):
    site = site_info(domain)
    d = install_dir(site, path)
    if not _is_install(d):
        raise ActionError("not_found", f"No WordPress found in {domain}/{clean_path(path)}")
    return site, d


def wp_argv(site, wp_dir, args, timeout=300, memory="512M"):
    """The systemd-run command line for WP-CLI as the site user (kept separate for tests)."""
    if not site["user"] or not re.fullmatch(r"s_[a-z0-9_]{1,30}", site["user"]):
        raise ActionError("internal", f"Website {site['domain']} has no valid system user")
    if not re.fullmatch(r"\d\.\d", site["php"]):
        raise ActionError("internal", "Invalid PHP version")
    tmp = os.path.join(site["root"], "tmp")
    return [
        SYSTEMD_RUN, "--quiet", "--wait", "--pipe", "--collect",
        # Pass the arguments literally: no $VAR expansion (wp eval code contains $).
        "--expand-environment=no",
        f"--uid={site['user']}", f"--gid={site['user']}",
        f"--slice={site.get('slice') or 'whp-sites.slice'}",
        f"--working-directory={wp_dir}",
        "-p", "UMask=0022",  # nginx (www-data) must be able to read the files WP-CLI writes
        "-p", "NoNewPrivileges=yes",
        "-p", "PrivateTmp=yes",
        "-p", "ProtectSystem=full",
        "-p", "ProtectHome=yes",
        "-p", f"RuntimeMaxSec={int(timeout)}",
        "-p", "MemoryMax=1G",
        "-p", "Nice=5",
        f"--setenv=HOME={site['root']}",
        f"--setenv=TMPDIR={tmp}",
        f"--setenv=WP_CLI_CACHE_DIR={tmp}/wp-cli-cache",
        "--setenv=WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1",
        f"/usr/bin/php{site['php']}", "-d", f"memory_limit={memory}", WP_CLI,
        f"--path={wp_dir}", "--no-color", *args,
    ]


def ensure_cli():
    """Install WP-CLI when it is missing (servers installed before the toolkit existed
    never ran that installer step). Pinned release, checked against its sha512."""
    if os.path.isfile(WP_CLI):
        return WP_CLI
    import hashlib
    import tempfile
    import urllib.request

    def fetch(url, limit):
        req = urllib.request.Request(url, headers={"User-Agent": "webhosting-panel-agent"})
        try:
            with urllib.request.urlopen(req, timeout=120) as resp:
                return resp.read(limit)
        except OSError as exc:
            raise ActionError("command_failed", f"WP-CLI is not installed and could not be downloaded: {exc}")

    expected = re.search(rb"[0-9a-f]{128}", fetch(WP_CLI_URL + ".sha512", 4096))
    data = fetch(WP_CLI_URL, 50_000_000)
    if not expected or hashlib.sha512(data).hexdigest() != expected.group(0).decode():
        raise ActionError("command_failed", "WP-CLI download does not match its published checksum")
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(WP_CLI), prefix=".wp-cli.")
    with os.fdopen(fd, "wb") as fh:
        fh.write(data)
    os.chmod(tmp, 0o755)
    os.replace(tmp, WP_CLI)
    return WP_CLI


def wp(domain, args, path="", input_text=None, timeout=300, check=True, plugins=False):
    """Run WP-CLI for an install. plugins=False adds --skip-plugins --skip-themes, so a
    broken plugin cannot stop the toolkit from listing or updating it."""
    if not all(isinstance(a, str) for a in args):
        raise ActionError("internal", "WP-CLI arguments must be strings")
    site, d = require_install(domain, path)
    ensure_cli()
    extra = [] if plugins else ["--skip-plugins", "--skip-themes"]
    proc = run(wp_argv(site, d, [*args, *extra], timeout=timeout), timeout=timeout + 15,
               check=False, input_text=input_text)
    if check and proc.returncode != 0:
        raise failure(f"wp {' '.join(args[:2])}", proc)
    return proc


# Lines from systemd-run itself (the command never started) and from WP-CLI/PHP.
_SYSTEMD_RUN_ERR = re.compile(r"^(Failed to .*|Job for .* failed.*|Invalid .*|Unknown .*)$", re.M)
_WP_ERR = re.compile(r"^(?:PHP )?(Error: .*|Fatal error: .*|Parse error: .*|Warning: .*(?:Failed opening|Permission denied).*)$", re.M)


def explain(proc):
    """The useful line of a failed WP-CLI run, instead of "systemd-run exited with code 1"."""
    text = "\n".join(x for x in ((proc.stderr or ""), (proc.stdout or "")) if x)
    m = _SYSTEMD_RUN_ERR.search(text)
    if m:
        return "systemd-run: " + m.group(1).strip()
    found = _WP_ERR.findall(text)
    if found:
        # "Error establishing a database connection" and friends come as HTML in WP-CLI output.
        return re.sub(r"<[^>]+>", "", found[0]).strip()[:500]
    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
    if lines:
        return re.sub(r"<[^>]+>", "", lines[-1])[:500]
    return f"exit code {proc.returncode}, no output"


def failure(what, proc):
    return ActionError("command_failed", f"WordPress ({what}) failed: {explain(proc)}",
                       {"exit_code": proc.returncode, "stderr": (proc.stderr or "")[-2000:],
                        "stdout": (proc.stdout or "")[-2000:]})


def wp_json(domain, args, path="", timeout=300, plugins=False):
    import json

    proc = wp(domain, args, path=path, timeout=timeout, plugins=plugins)
    out = (proc.stdout or "").strip()
    # WP-CLI may print PHP notices before the JSON; take the last JSON line.
    for line in reversed(out.splitlines()):
        line = line.strip()
        if line[:1] in "[{":
            try:
                return json.loads(line)
            except ValueError:
                break
    if not out:
        return []
    raise ActionError("command_failed", "WP-CLI returned invalid JSON", {"stdout": out[-1000:]})


def as_user(site, argv, cwd, input_text=None, timeout=300, check=True):
    """Run any command (argv list, no shell) as the site user, like wp() does for WP-CLI."""
    base = wp_argv(site, cwd, [], timeout=timeout)
    prefix = base[:base.index(f"/usr/bin/php{site['php']}")]
    proc = run([*prefix, *argv], timeout=timeout + 15, check=False, input_text=input_text)
    if check and proc.returncode != 0:
        raise failure(os.path.basename(argv[0]), proc)
    return proc
