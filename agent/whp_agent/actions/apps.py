"""One-click apps: install a curated set of web apps into a website.

    apps.catalog {max_age_days?, refresh?}  which apps this agent can install, with
                                             their latest release and whether they are
                                             still maintained (a release in the last
                                             max_age_days, default 365, and not end of life)
    apps.list    {domain?}             installed apps (from the agent's own state), with
                                       the version on disk and whether an update is out
    apps.install {domain, app, ...}    job: database + files + configuration + admin user
                                       (+ plugin_set for WordPress/WooCommerce, see wp_sets.py)
    apps.remove  {domain, id}          job: files, database and web server snippet

How it stays safe:

* Everything that touches the site's files runs as the site's own Linux
  user (runuser), with umask 022 so Nginx can read static files. The agent
  (root) only reads archives it downloaded itself into its own cache, writes
  wp-config.php with O_EXCL|O_NOFOLLOW, and removes files after checking that
  the path has no symlinks and lies inside the site's document root.
* Passwords never go on a command line: WP-CLI reads them on stdin, Joomla
  and Nextcloud get them through the environment of a small PHP wrapper.
* Downloads are checked against the checksum the project publishes.
* An install only goes into an empty folder (the placeholder page of a new
  site does not count), and is rolled back completely when a step fails.
* Only maintained apps are offered: an app whose project published no release
  in max_age_days, or whose branch is end of life, cannot be installed.
* Apps that keep their own login sessions switch the site's page cache off,
  so a logged-in page is never served to someone else from the cache.
* Folders with configuration, data or code that must not be fetched directly
  are closed in Nginx with ^~ locations (they win over the static file rules).

Adding an app: write a recipe class with install() (and optionally
nginx_snippet() / data_dirs()), add it to RECIPES, and add its card to
packages/apps/config/apps.php in the panel.
"""

import calendar
import concurrent.futures
import email.utils
import hashlib
import json
import os
import re
import secrets
import shutil
import string
import threading
import time
import urllib.request
import uuid

from .. import paths, wordpress, wp_sets
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run
from . import databases

WHP_SITE = "/usr/local/sbin/whp-site"
VHOSTS = os.environ.get("WHP_VHOSTS", "/var/www/vhosts")
NGINX_SITES = "/etc/nginx/whp/sites"
USER_AGENT = "webhosting-panel-app-installer"

WP_CLI_URL = "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
JOOMLA_RELEASE_API = "https://api.github.com/repos/joomla/joomla-cms/releases/latest"
NEXTCLOUD_URL = "https://download.nextcloud.com/server/releases/latest.zip"
GITHUB_RELEASE_API = "https://api.github.com/repos/{}/releases/latest"
WP_RELEASES_FEED = "https://wordpress.org/news/category/releases/feed/"
WP_VERSION_API = "https://api.wordpress.org/core/version-check/1.7/"
WP_PLUGIN_API = "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D={}"
PIWIGO_DOWNLOAD = "https://piwigo.org/download/dlcounter.php?code={}"
MEDIAWIKI_RELEASES = "https://releases.wikimedia.org/mediawiki/"
DOKUWIKI_URL = "https://download.dokuwiki.org/src/dokuwiki/dokuwiki-stable.tgz"
PHPBB_VERSIONS = "https://version.phpbb.com/phpbb/versions.json"
PHPBB_RELEASES = "https://download.phpbb.com/pub/release/{branch}/"
PHPBB_DOWNLOAD = PHPBB_RELEASES + "{version}/phpBB-{version}.zip"
# download.phpbb.com answers 403 to unknown user agents; it serves the curl that ships with Debian.
PHPBB_HEADERS = {"User-Agent": "curl/8.14.1"}
FRESHRSS_DOWNLOAD = "https://github.com/FreshRSS/FreshRSS/archive/refs/tags/{}.tar.gz"

DEFAULT_MAX_AGE_DAYS = 365
RELEASE_TTL = 6 * 3600          # how long a release check is trusted
RELEASE_RETRY = 3600            # after a failed check
RELEASE_TIMEOUT = 15
SYSTEMD_DIR = "/etc/systemd/system"

PATH_RE = r"[a-z0-9][a-z0-9_-]{0,39}(?:/[a-z0-9][a-z0-9_-]{0,39}){0,3}"
ADMIN_USER_RE = r"[A-Za-z0-9][A-Za-z0-9._@-]{2,59}"
EMAIL_RE = r"[^@\s]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,63}"

_busy = set()
_busy_lock = threading.Lock()
_state_lock = threading.Lock()


# --------------------------------------------------------------------------- helpers

def apps_dir():
    return os.path.join(paths.STATE_DIR, "apps")


def _cache_dir():
    d = os.path.join(apps_dir(), "cache")
    os.makedirs(d, mode=0o755, exist_ok=True)
    os.chmod(apps_dir(), 0o755)
    os.chmod(d, 0o755)
    return d


def _state_path(domain):
    return os.path.join(apps_dir(), f"{domain}.json")


def load_state(domain):
    try:
        with open(_state_path(domain)) as fh:
            data = json.load(fh)
            return data if isinstance(data, list) else []
    except FileNotFoundError:
        return []


def _save_state(domain, items):
    os.makedirs(apps_dir(), mode=0o755, exist_ok=True)
    tmp = _state_path(domain) + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(items, fh, indent=1)
    os.chmod(tmp, 0o600)
    os.replace(tmp, _state_path(domain))


def site_info(domain):
    proc = run([WHP_SITE, "show", domain, "--json"], timeout=60, check=False)
    if proc.returncode != 0:
        raise ActionError("not_found", f"Website {domain} does not exist on this server")
    try:
        site = json.loads(proc.stdout)
    except ValueError:
        raise ActionError("command_failed", "whp-site returned invalid JSON")
    if site.get("suspended"):
        raise ActionError("conflict", f"Website {domain} is suspended")
    return site


def random_password(length=24):
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


def download(url, dest, timeout=300, headers=None):
    """Download url to dest (in the agent's own cache). Returns the sha256 and sha512."""
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, **(headers or {})})
    tmp = f"{dest}.{uuid.uuid4().hex}.part"
    sha256, sha512 = hashlib.sha256(), hashlib.sha512()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp, open(tmp, "wb") as fh:
            while True:
                chunk = resp.read(1 << 20)
                if not chunk:
                    break
                sha256.update(chunk)
                sha512.update(chunk)
                fh.write(chunk)
        os.chmod(tmp, 0o644)
        os.replace(tmp, dest)
    except OSError as exc:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise ActionError("command_failed", f"Download failed: {url}: {exc}")
    return sha256.hexdigest(), sha512.hexdigest()


def fetch_text(url, timeout=60, headers=None):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, **(headers or {})})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.read(5_000_000).decode("utf-8", "replace")
    except OSError as exc:
        raise ActionError("command_failed", f"Download failed: {url}: {exc}")


def first_hex(text, length):
    m = re.search(rf"\b[0-9a-f]{{{length}}}\b", text.lower())
    if not m:
        raise ActionError("command_failed", "Published checksum not found")
    return m.group(0)


def safe_inside(path, base):
    """True when path is base or below it, with no symlinks on the way."""
    real, norm = os.path.realpath(path), os.path.normpath(path)
    base_real = os.path.realpath(base)
    return real == norm and (real == base_real or real.startswith(base_real + "/"))


def github_release(repo):
    """The latest release of a GitHub project (tag, date, assets)."""
    return json.loads(fetch_text(GITHUB_RELEASE_API.format(repo), timeout=RELEASE_TIMEOUT,
                                 headers={"Accept": "application/vnd.github+json"}))


def iso_time(value):
    """2025-05-14T10:00:00Z -> epoch seconds (None when it is not such a date)."""
    m = re.match(r"(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?", str(value or ""))
    if not m:
        return None
    parts = [int(x) if x else 0 for x in m.groups()]
    return calendar.timegm((*parts, 0, 0, 0))


def github_info(repo, strip=r"^v"):
    doc = github_release(repo)
    return {"version": re.sub(strip, "", doc.get("tag_name") or "") or None,
            "released": iso_time(doc.get("published_at"))}


def version_key(value):
    """A sortable key for 6.8.3, v31.0.9, 2025-05-14a or 3.3.15 (digits as numbers)."""
    key = []
    for tok in re.findall(r"\d+|[a-z]+", re.sub(r"^\D+", "", str(value or "").lower())):
        key.append((1, int(tok), "") if tok.isdigit() else (0, 0, tok))
    return tuple(key)


def newer(latest, current):
    if not latest or not current:
        return False
    return version_key(latest) > version_key(current)


def read_version_file(path, pattern, base):
    """Read a version string from a file of an installed app (small files only, no symlinks)."""
    try:
        if not safe_inside(path, base) or os.path.getsize(path) > 2_000_000:
            return None
        with open(path, errors="replace") as fh:
            m = re.search(pattern, fh.read())
        return m.group(1) if m else None
    except OSError:
        return None


def _releases_path():
    return os.path.join(apps_dir(), "releases.json")


def _load_releases():
    try:
        with open(_releases_path()) as fh:
            data = json.load(fh)
            return data if isinstance(data, dict) else {}
    except (FileNotFoundError, ValueError):
        return {}


def release_info(recipe, refresh=False):
    """The latest release of an app, from the agent's cache or the project itself."""
    entry = _load_releases().get(recipe.id) or {}
    now = int(time.time())
    if entry and not refresh and now < entry.get("next_check", 0):
        return entry
    try:
        info = recipe.release_info()
        entry = {"version": info.get("version"), "released": info.get("released"), "eol": info.get("eol"),
                 "checked": now, "next_check": now + RELEASE_TTL, "error": None}
    except Exception as exc:  # network, JSON, an unexpected page: keep what we knew
        entry = {**entry, "next_check": now + RELEASE_RETRY, "error": str(exc)[:200]}
    with _state_lock:
        data = _load_releases()
        data[recipe.id] = entry
        os.makedirs(apps_dir(), mode=0o755, exist_ok=True)
        tmp = _releases_path() + ".tmp"
        with open(tmp, "w") as fh:
            json.dump(data, fh, indent=1, sort_keys=True)
        os.replace(tmp, _releases_path())
    return entry


def maintained(entry, max_age_days):
    """True / False, or None when it is not known (never checked successfully)."""
    if entry.get("eol") is True:
        return False
    if entry.get("released"):
        return time.time() - entry["released"] <= max_age_days * 86400
    if entry.get("eol") is False and entry.get("version"):
        return True
    return None


def cached_download(name, url, sha256=None, timeout=900, max_age=None, headers=None):
    """Download into the agent's cache once. Checked against sha256 when the project publishes one;
    without a checksum a versioned file is kept, an unversioned one (max_age) is fetched again."""
    name = re.sub(r"[^A-Za-z0-9._-]", "_", name)
    dest = os.path.join(_cache_dir(), name)
    if os.path.exists(dest):
        if sha256 and _sha256(dest) == sha256:
            return dest
        if not sha256 and (max_age is None or time.time() - os.path.getmtime(dest) < max_age):
            return dest
    got, _ = download(url, dest + ".new", timeout=timeout, headers=headers)
    if sha256 and got != sha256:
        os.unlink(dest + ".new")
        raise ActionError("command_failed", f"Checksum of {name} does not match")
    os.replace(dest + ".new", dest)
    return dest


def location_prefix(path):
    return "/" + (path + "/" if path else "")


def deny_dirs(path, dirs):
    """Nginx: never serve these folders of an app. ^~ wins over the regex locations (static files, PHP)."""
    prefix = location_prefix(path)
    return "".join(f"location ^~ {prefix}{d}/ {{ return 404; }}\n" for d in dirs)


def deny_files(path, files):
    prefix = location_prefix(path)
    return "".join(f"location = {prefix}{f} {{ return 404; }}\n" for f in files)


def front_controller(path, target):
    """Nginx: pretty URLs of an app in a subfolder go to its own index.php (the site's / does the root)."""
    if not path:
        return ""
    return f"location /{path}/ {{\n    try_files $uri $uri/ /{path}/{target};\n}}\n"


class Context:
    """Everything a recipe needs for one install."""

    def __init__(self, site, app, path, params, log):
        self.site = site
        self.domain = site["domain"]
        self.user = site["user"]
        self.php = f"php{site['php']}"
        self.root = site.get("root") or os.path.join(VHOSTS, self.domain)
        self.docroot = site["docroot"]
        self.path = path
        self.target = os.path.join(self.docroot, path) if path else self.docroot
        scheme = "https" if site.get("ssl") == "active" else "http"
        self.url = f"{scheme}://{self.domain}" + (f"/{path}" if path else "")
        self.app = app
        self.params = params
        self.log = log
        self.db = None
        self.version = None
        self.tmp_files = []

    def as_site(self, argv, input_text=None, timeout=600, cwd=None, env=None):
        full_env = {"HOME": self.root, "TMPDIR": os.path.join(self.root, "tmp"),
                    "WP_CLI_CACHE_DIR": os.path.join(self.root, "tmp", "wp-cli-cache")}
        full_env.update(env or {})
        return run(["runuser", "-u", self.user, "--", *argv], input_text=input_text, timeout=timeout,
                   cwd=cwd or self.target, env=full_env, umask=0o022)

    def write_private(self, path, content, mode=0o600):
        """Create a new file owned by the site user, never following a planted symlink."""
        if not safe_inside(os.path.dirname(path), self.root):
            raise ActionError("forbidden", "Refusing to write outside the website")
        pw = _pwnam(self.user)
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
        try:
            os.fchown(fd, pw.pw_uid, pw.pw_gid)
            os.write(fd, content.encode())
        finally:
            os.close(fd)

    def replace_private(self, path, content, mode=0o600):
        """write_private, replacing a file the archive brought along (never through a symlink)."""
        if os.path.lexists(path):
            if os.path.islink(path) or not safe_inside(os.path.dirname(path), self.target):
                raise ActionError("forbidden", "Refusing to replace a file outside the app")
            os.unlink(path)
        self.write_private(path, content, mode)

    def wrapper(self, body):
        """A PHP file in the agent's state dir (no secrets in it) that the site user can run."""
        d = os.path.join(apps_dir(), "run")
        os.makedirs(d, mode=0o755, exist_ok=True)
        os.chmod(d, 0o755)
        path = os.path.join(d, f"{uuid.uuid4().hex}.php")
        with open(path, "w") as fh:
            fh.write(body)
        os.chmod(path, 0o644)
        self.tmp_files.append(path)
        return path

    def php_cli(self, script, args, env=None, timeout=600, argv0=None, cwd=None):
        """Run a PHP CLI script of the app as the site user. "$WHP_NAME" in an argument is filled
        in from the environment by the wrapper, so secrets never show up in the process list."""
        wrapper = self.wrapper(f"""<?php
$argv = array_map(function ($a) {{
    return preg_replace_callback('/\\$(WHP_[A-Z_]+)/', fn ($m) => (string) getenv($m[1]), $a);
}}, json_decode({php_string(json.dumps([argv0 or script, *args]))}, true));
$argc = count($argv);
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc;
$GLOBALS['argv'] = $argv;
$GLOBALS['argc'] = $argc;
chdir({php_string(cwd or os.path.dirname(script))});
require {php_string(script)};
""")
        return self.as_site([self.php, "-d", "memory_limit=1G", wrapper], env=env, timeout=timeout,
                            cwd=cwd or self.target)

    def php_eval(self, code, env=None, timeout=120):
        """Run a small PHP program (no secrets in it: those come from env) as the site user."""
        return self.as_site([self.php, self.wrapper("<?php\n" + code)], env=env, timeout=timeout)

    def extract(self, archive, strip=True):
        """Unpack an archive from the agent's cache into the target, as the site user. With strip,
        a single top folder in the archive (wordpress/, phpBB3/, ...) is left out."""
        staging = os.path.join(self.root, "tmp", f"whp-app-{uuid.uuid4().hex[:8]}")
        try:
            self.as_site(["mkdir", "-p", "-m", "0700", "--", staging], cwd=self.root)
            if archive.endswith(".zip"):
                self.as_site(["unzip", "-q", archive, "-d", staging], timeout=900, cwd=self.root)
            else:
                self.as_site(["tar", "-xzf", archive, "-C", staging, "--no-same-owner"], timeout=900, cwd=self.root)
            src = staging
            entries = os.listdir(staging)
            if strip and len(entries) == 1:
                only = os.path.join(staging, entries[0])
                if os.path.isdir(only) and not os.path.islink(only):
                    src = only
            self.as_site(["cp", "-a", "--", src + "/.", self.target + "/"], timeout=900, cwd=self.root)
        finally:
            self.as_site(["rm", "-rf", "--", staging], timeout=300, cwd=self.root)

    def private_tmp(self, content):
        """A 0600 file of the site user in the site's tmp folder (removed by cleanup)."""
        path = os.path.join(self.root, "tmp", f"whp-app-{uuid.uuid4().hex}")
        self.write_private(path, content)
        self.tmp_files.append(path)
        return path

    def cleanup(self):
        for f in self.tmp_files:
            try:
                os.unlink(f)
            except FileNotFoundError:
                pass


def _pwnam(user):
    import pwd
    return pwd.getpwnam(user)


def php_string(value):
    """A PHP single-quoted string literal."""
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


# --------------------------------------------------------------------------- recipes

class WordPress:
    id = "wordpress"
    name = "WordPress"
    subdir = True
    locales = ["en", "nl"]
    admin_path = "wp-admin/"

    WP_LOCALE = {"en": "en_US", "nl": "nl_NL"}

    def wp_cli(self):
        """The installer's WP-CLI (/usr/local/bin/wp); a checked copy in the agent's cache if it is missing."""
        if os.path.exists(wordpress.WP_CLI):
            return wordpress.WP_CLI
        dest = os.path.join(_cache_dir(), "wp-cli.phar")
        if os.path.exists(dest) and time.time() - os.path.getmtime(dest) < 7 * 86400:
            return dest
        expected = first_hex(fetch_text(WP_CLI_URL + ".sha512"), 128)
        tmp = dest + ".new"
        _, sha512 = download(WP_CLI_URL, tmp)
        if sha512 != expected:
            os.unlink(tmp)
            raise ActionError("command_failed", "WP-CLI checksum does not match")
        os.replace(tmp, dest)
        return dest

    def release_info(self):
        version = json.loads(fetch_text(WP_VERSION_API, timeout=RELEASE_TIMEOUT))["offers"][0]["current"]
        feed = fetch_text(WP_RELEASES_FEED, timeout=RELEASE_TIMEOUT)
        m = re.search(r"<pubDate>([^<]+)</pubDate>", feed.split("<item>", 1)[-1])
        released = int(email.utils.parsedate_to_datetime(m.group(1)).timestamp()) if m else None
        return {"version": version, "released": released}

    def installed_version(self, target):
        return wordpress.read_version(target)

    def wp_runner(self, ctx):
        # The shared runner of the WordPress toolkit: WP-CLI as the site user under
        # systemd-run in whp-sites.slice, with the site's PHP version and UMask 0022.
        wsite = wordpress.site_info(ctx.domain)
        cli = self.wp_cli()

        def wp(args, input_text=None, timeout=300):
            argv = wordpress.wp_argv(wsite, ctx.target, args, timeout=timeout)
            argv[argv.index(wordpress.WP_CLI)] = cli
            return run(argv, timeout=timeout + 15, input_text=input_text)
        return wp

    def install(self, ctx):
        p = ctx.params
        locale = self.WP_LOCALE[p["locale"]]
        wp = self.wp_runner(ctx)

        ctx.log("Downloading WordPress")
        wp(["core", "download", f"--locale={locale}"], timeout=600)
        ctx.log("Writing wp-config.php")
        ctx.write_private(os.path.join(ctx.target, "wp-config.php"), self.config(ctx))
        ctx.log("Installing WordPress")
        wp(["core", "install", f"--url={ctx.url}", f"--title={p['title']}",
            f"--admin_user={p['admin_user']}", f"--admin_email={p['admin_email']}",
            "--skip-email", "--prompt=admin_password"], input_text=p["admin_password"] + "\n")
        wp(["rewrite", "structure", "/%postname%/"], timeout=120)
        wp(["plugin", "delete", "hello"], timeout=120)
        if p["locale"] == "nl":
            wp(["option", "update", "timezone_string", "Europe/Amsterdam"], timeout=120)
        ctx.version = wordpress.read_version(ctx.target) or wp(["core", "version"], timeout=60).stdout.strip()

    def config(self, ctx):
        db = ctx.db
        salts = "\n".join(
            f"define({php_string(k)}, {php_string(random_password(64))});"
            for k in ["AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY",
                      "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT"])
        prefix = "wp_" + secrets.token_hex(2) + "_"
        return f"""<?php
// Written by the Webhosting Panel app installer.
define('DB_NAME', {php_string(db['name'])});
define('DB_USER', {php_string(db['user'])});
define('DB_PASSWORD', {php_string(db['password'])});
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

{salts}

$table_prefix = '{prefix}';

define('WP_DEBUG', false);
// Themes and plugins are not edited from wp-admin (a common way in after a stolen password).
define('DISALLOW_FILE_EDIT', true);
define('FS_METHOD', 'direct');

if (!defined('ABSPATH')) {{
    define('ABSPATH', __DIR__ . '/');
}}
require_once ABSPATH . 'wp-settings.php';
"""

    def nginx_snippet(self, ctx):
        if not ctx.path:
            return None
        return (f"location /{ctx.path}/ {{\n"
                f"    try_files $uri $uri/ /{ctx.path}/index.php?$args;\n}}\n")

    def data_dirs(self, ctx):
        return []


class Joomla:
    id = "joomla"
    name = "Joomla"
    subdir = True
    locales = ["en"]
    admin_path = "administrator/"
    # Joomla's session cookie has a random name, so the page cache cannot tell a
    # logged-in visitor apart: switch the cache off instead of risking a leak.
    page_cache = False

    ASSET = re.compile(r"^Joomla_[0-9.]+-Stable-Full_Package\.zip$")

    def release(self):
        doc = json.loads(fetch_text(JOOMLA_RELEASE_API, headers={"Accept": "application/vnd.github+json"}))
        for asset in doc.get("assets", []):
            if self.ASSET.match(asset.get("name", "")):
                return doc.get("tag_name", "").lstrip("v"), asset
        raise ActionError("command_failed", "No Joomla full package in the latest release")

    def release_info(self):
        doc = github_release("joomla/joomla-cms")
        return {"version": (doc.get("tag_name") or "").lstrip("v") or None, "released": iso_time(doc.get("published_at"))}

    def installed_version(self, target):
        f = os.path.join(target, "libraries", "src", "Version.php")
        parts = [read_version_file(f, rf"const {k}_VERSION\s*=\s*(\d+)", target) for k in ("MAJOR", "MINOR", "PATCH")]
        return ".".join(parts) if all(parts) else None

    def install(self, ctx):
        p = ctx.params
        version, asset = self.release()
        ctx.log(f"Downloading Joomla {version}")
        archive = os.path.join(_cache_dir(), asset["name"])
        if not os.path.exists(archive):
            sha256, _ = download(asset["browser_download_url"], archive + ".new")
            digest = (asset.get("digest") or "").lower()
            if digest.startswith("sha256:") and digest[7:] != sha256:
                os.unlink(archive + ".new")
                raise ActionError("command_failed", "Joomla checksum does not match")
            os.replace(archive + ".new", archive)
        ctx.as_site(["unzip", "-q", archive, "-d", ctx.target], timeout=600)
        ctx.log("Installing Joomla")
        script = os.path.join(ctx.target, "installation", "joomla.php")
        wrapper = ctx.wrapper(f"""<?php
// Runs the Joomla CLI installer with the secrets from the environment.
$argv = [{php_string(script)}, 'install', '-n',
    '--site-name=' . getenv('WHP_TITLE'),
    '--admin-user=' . getenv('WHP_ADMIN_USER'),
    '--admin-username=' . getenv('WHP_ADMIN_USER'),
    '--admin-password=' . getenv('WHP_ADMIN_PASS'),
    '--admin-email=' . getenv('WHP_ADMIN_EMAIL'),
    '--db-type=mysqli', '--db-host=localhost',
    '--db-user=' . getenv('WHP_DB_USER'),
    '--db-pass=' . getenv('WHP_DB_PASS'),
    '--db-name=' . getenv('WHP_DB_NAME'),
    '--db-prefix=' . getenv('WHP_DB_PREFIX'),
    '--db-encryption=0'];
$argc = count($argv);
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc;
chdir(dirname({php_string(script)}));
require {php_string(script)};
""")
        ctx.as_site([ctx.php, wrapper], timeout=600, env={
            "WHP_TITLE": p["title"], "WHP_ADMIN_USER": p["admin_user"], "WHP_ADMIN_PASS": p["admin_password"],
            "WHP_ADMIN_EMAIL": p["admin_email"], "WHP_DB_USER": ctx.db["user"], "WHP_DB_PASS": ctx.db["password"],
            "WHP_DB_NAME": ctx.db["name"], "WHP_DB_PREFIX": "j" + secrets.token_hex(2) + "_",
        })
        inst = os.path.join(ctx.target, "installation")
        if os.path.isdir(inst):
            ctx.as_site(["rm", "-rf", "--", inst], timeout=120)
        ctx.version = version

    def nginx_snippet(self, ctx):
        if not ctx.path:
            return None
        return (f"location /{ctx.path}/ {{\n"
                f"    try_files $uri $uri/ /{ctx.path}/index.php?$query_string;\n}}\n")

    def data_dirs(self, ctx):
        return []


class Nextcloud:
    id = "nextcloud"
    name = "Nextcloud"
    subdir = False
    locales = ["en", "nl"]
    admin_path = "index.php/settings/admin"
    page_cache = False

    def release_info(self):
        return github_info("nextcloud/server")

    def installed_version(self, target):
        return read_version_file(os.path.join(target, "version.php"), r"\$OC_VersionString\s*=\s*'([^']+)'", target)

    def install(self, ctx):
        p = ctx.params
        ctx.log("Downloading Nextcloud")
        archive = os.path.join(_cache_dir(), "nextcloud-latest.zip")
        expected = first_hex(fetch_text(NEXTCLOUD_URL + ".sha256"), 64)
        if not os.path.exists(archive) or _sha256(archive) != expected:
            sha256, _ = download(NEXTCLOUD_URL, archive + ".new", timeout=900)
            if sha256 != expected:
                os.unlink(archive + ".new")
                raise ActionError("command_failed", "Nextcloud checksum does not match")
            os.replace(archive + ".new", archive)
        staging = os.path.join(ctx.root, "tmp", f"whp-nextcloud-{uuid.uuid4().hex[:8]}")
        try:
            ctx.as_site(["unzip", "-q", archive, "-d", staging], timeout=900, cwd=ctx.root)
            ctx.as_site(["cp", "-a", "--", os.path.join(staging, "nextcloud") + "/.", ctx.target + "/"],
                        timeout=600, cwd=ctx.root)
        finally:
            ctx.as_site(["rm", "-rf", "--", staging], timeout=300, cwd=ctx.root)
        data = self.data_dirs(ctx)[0]
        ctx.as_site(["mkdir", "-p", "-m", "0750", "--", data], cwd=ctx.root)

        ctx.log("Installing Nextcloud")
        occ = self.occ(ctx)
        occ(["maintenance:install", "--database=mysql", "--database-host=localhost",
             "--database-name=" + ctx.db["name"], "--database-user=" + ctx.db["user"],
             "--database-pass=$WHP_DB_PASS", "--admin-user=" + p["admin_user"],
             "--admin-pass=$WHP_ADMIN_PASS", "--admin-email=" + p["admin_email"],
             "--data-dir=" + data],
            env={"WHP_DB_PASS": ctx.db["password"], "WHP_ADMIN_PASS": p["admin_password"]}, timeout=900)
        names = [ctx.domain, *ctx.site.get("aliases", [])]
        for i, host in enumerate(names):
            occ(["config:system:set", "trusted_domains", str(i), "--value=" + host])
        occ(["config:system:set", "overwrite.cli.url", "--value=" + ctx.url])
        if p["locale"] == "nl":
            occ(["config:system:set", "default_language", "--value=nl"])
            occ(["config:system:set", "default_locale", "--value=nl_NL"])
            occ(["config:system:set", "default_phone_region", "--value=NL"])
        out = occ(["status", "--output=json"]).stdout
        try:
            ctx.version = json.loads(out[out.index("{"):]).get("versionstring")
        except ValueError:
            ctx.version = None

    def occ(self, ctx):
        console = os.path.join(ctx.target, "console.php")

        def call(args, env=None, timeout=300):
            # "$NAME" arguments are filled in from the environment by the wrapper,
            # so secrets do not appear in the process list.
            wrapper = ctx.wrapper(f"""<?php
$argv = array_map(function ($a) {{
    return preg_replace_callback('/\\$(WHP_[A-Z_]+)/', fn ($m) => (string) getenv($m[1]), $a);
}}, json_decode({php_string(json.dumps(["occ", *args]))}, true));
$argc = count($argv);
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc;
chdir({php_string(ctx.target)});
require {php_string(console)};
""")
            return ctx.as_site([ctx.php, "-d", "memory_limit=1G", "-d", "apc.enable_cli=1", wrapper],
                               env=env, timeout=timeout)
        return call

    def nginx_snippet(self, ctx):
        return (
            "# Nextcloud (app installer)\n"
            "location = /.well-known/carddav { return 301 /remote.php/dav/; }\n"
            "location = /.well-known/caldav { return 301 /remote.php/dav/; }\n"
            "location ~ ^/(?:build|tests|config|lib|3rdparty|templates|data)(?:$|/) { return 404; }\n"
            "location ~ ^/(?:autotest|occ|issue|indie|db_|console) { return 404; }\n"
            "client_max_body_size 512M;\n"
        )

    def data_dirs(self, ctx):
        return [os.path.join(ctx.root, "nextcloud-data")]


def http_last_modified(url, timeout=RELEASE_TIMEOUT):
    """The Last-Modified date of a download (epoch), for projects that publish no release dates."""
    req = urllib.request.Request(url, method="HEAD", headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            value = resp.headers.get("Last-Modified")
    except OSError as exc:
        raise ActionError("command_failed", f"Download failed: {url}: {exc}")
    return int(email.utils.parsedate_to_datetime(value).timestamp()) if value else None


def _site_base_url(ctx):
    """https://example.nl (without the app's folder)."""
    return ctx.url[: -(len(ctx.path) + 1)] if ctx.path else ctx.url


def _latest_version(recipe):
    version = release_info(recipe).get("version")
    if not version or not re.fullmatch(r"[0-9][0-9A-Za-z.\-]{0,30}", version):
        raise ActionError("command_failed", f"Could not find the latest version of {recipe.name}")
    return version


class WooCommerce(WordPress):
    """WordPress with WooCommerce, ready for a Dutch shop (EUR, NL) when the language is Dutch."""
    id = "woocommerce"
    name = "WooCommerce"
    admin_path = "wp-admin/admin.php?page=wc-admin"

    def release_info(self):
        doc = json.loads(fetch_text(WP_PLUGIN_API.format("woocommerce"), timeout=RELEASE_TIMEOUT))
        return {"version": doc.get("version"), "released": iso_time(doc.get("last_updated"))}

    def installed_version(self, target):
        return read_version_file(os.path.join(target, "wp-content", "plugins", "woocommerce", "woocommerce.php"),
                                 r"(?m)^\s*\*\s*Version:\s*([0-9][0-9.]*)", target)

    def install(self, ctx):
        super().install(ctx)
        wp = self.wp_runner(ctx)
        ctx.log("Installing WooCommerce")
        wp(["plugin", "install", "woocommerce", "--activate"], timeout=900)
        if ctx.params["locale"] == "nl":
            wp(["language", "plugin", "install", "woocommerce", "nl_NL"], timeout=300)
            wp(["option", "update", "woocommerce_default_country", "NL"], timeout=120)
            wp(["option", "update", "woocommerce_currency", "EUR"], timeout=120)
        ctx.version = self.installed_version(ctx.target) or ctx.version


class Piwigo:
    """Photo albums. Piwigo's own web installer, run from the command line."""
    id = "piwigo"
    name = "Piwigo"
    subdir = True
    locales = ["en", "nl"]
    admin_path = "admin.php"
    page_cache = False

    LANG = {"en": "en_UK", "nl": "nl_NL"}

    def release_info(self):
        return github_info("Piwigo/Piwigo")

    def installed_version(self, target):
        return read_version_file(os.path.join(target, "include", "constants.php"),
                                 r"define\(\s*'PHPWG_VERSION'\s*,\s*'([^']+)'", target)

    def install(self, ctx):
        p = ctx.params
        version = _latest_version(self)
        ctx.log(f"Downloading Piwigo {version}")
        ctx.extract(cached_download(f"piwigo-{version}.zip", PIWIGO_DOWNLOAD.format(version)))
        ctx.log("Installing Piwigo")
        script = os.path.join(ctx.target, "install.php")
        uri = location_prefix(ctx.path) + "install.php"
        wrapper = ctx.wrapper(f"""<?php
// Piwigo's web installer, fed from the environment (no secrets on the command line).
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = getenv('WHP_HOST');
$_SERVER['SERVER_PORT'] = getenv('WHP_HTTPS') ? '443' : '80';
$_SERVER['HTTPS'] = getenv('WHP_HTTPS') ? 'on' : '';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $_SERVER['REQUEST_URI'] = {php_string(uri)};
$_SERVER['SCRIPT_FILENAME'] = {php_string(script)};
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = ['language' => getenv('WHP_LANG')];
$_POST = [
    'install' => '1', 'language' => getenv('WHP_LANG'),
    'dbhost' => 'localhost', 'dbuser' => getenv('WHP_DB_USER'), 'dbpasswd' => getenv('WHP_DB_PASS'),
    'dbname' => getenv('WHP_DB_NAME'), 'prefix' => getenv('WHP_DB_PREFIX'),
    'admin_name' => getenv('WHP_ADMIN_USER'), 'admin_pass1' => getenv('WHP_ADMIN_PASS'),
    'admin_pass2' => getenv('WHP_ADMIN_PASS'), 'admin_mail' => getenv('WHP_ADMIN_EMAIL'),
];
$_REQUEST = array_merge($_GET, $_POST);
chdir({php_string(ctx.target)});
require {php_string(script)};
""")
        proc = ctx.as_site([ctx.php, wrapper], timeout=900, env={
            "WHP_HOST": ctx.domain, "WHP_HTTPS": "1" if ctx.url.startswith("https:") else "",
            "WHP_LANG": self.LANG[p["locale"]], "WHP_DB_USER": ctx.db["user"], "WHP_DB_PASS": ctx.db["password"],
            "WHP_DB_NAME": ctx.db["name"], "WHP_DB_PREFIX": "piwigo_" + secrets.token_hex(2) + "_",
            "WHP_ADMIN_USER": p["admin_user"], "WHP_ADMIN_PASS": p["admin_password"],
            "WHP_ADMIN_EMAIL": p["admin_email"],
        })
        config = os.path.join(ctx.target, "local", "config", "database.inc.php")
        if not os.path.exists(config):
            errors = re.findall(r"<li>(.*?)</li>", re.sub(r"\s+", " ", proc.stdout or ""))
            raise ActionError("command_failed", "Piwigo was not installed: "
                              + (re.sub(r"<[^>]+>", "", "; ".join(errors[:3])) or "no configuration written"))
        ctx.as_site(["chmod", "0600", "--", config])
        ctx.version = self.installed_version(ctx.target) or version

    def nginx_snippet(self, ctx):
        return "# Piwigo (app installer)\n" + deny_dirs(ctx.path, ["local/config", "_data/logs"])

    def data_dirs(self, ctx):
        return []


class MediaWiki:
    """The wiki software of Wikipedia, installed with its own command line installer."""
    id = "mediawiki"
    name = "MediaWiki"
    subdir = True
    locales = ["en", "nl"]
    admin_path = "index.php?title=Special:UserLogin"
    page_cache = False
    admin_user_re = r"[A-Za-z0-9][A-Za-z0-9._-]{2,59}"  # MediaWiki does not allow @ in names

    @staticmethod
    def _listing_date(line):
        m = re.search(r"(\d{4}-\d{2}-\d{2} \d{2}:\d{2})", line)
        if m:
            return iso_time(m.group(1))
        m = re.search(r"(\d{2}-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2})", line)
        if m:
            return calendar.timegm(time.strptime(m.group(1), "%d-%b-%Y %H:%M"))
        return None

    def release_info(self):
        index = fetch_text(MEDIAWIKI_RELEASES, timeout=RELEASE_TIMEOUT)
        branches = sorted(set(re.findall(r'href="(\d+\.\d+)/"', index)), key=version_key, reverse=True)
        for branch in branches[:3]:
            page = fetch_text(f"{MEDIAWIKI_RELEASES}{branch}/", timeout=RELEASE_TIMEOUT)
            found = []
            for line in page.splitlines():
                m = re.search(r'href="mediawiki-(\d+\.\d+\.\d+)\.tar\.gz"', line)
                if m:
                    found.append((m.group(1), self._listing_date(line)))
            if found:
                version, released = max(found, key=lambda f: version_key(f[0]))
                return {"version": version, "released": released}
        raise ActionError("command_failed", "No MediaWiki release found")

    def installed_version(self, target):
        return read_version_file(os.path.join(target, "includes", "Defines.php"),
                                 r"define\(\s*'MW_VERSION'\s*,\s*'([^']+)'", target)

    def install(self, ctx):
        p = ctx.params
        version = _latest_version(self)
        branch = ".".join(version.split(".")[:2])
        ctx.log(f"Downloading MediaWiki {version}")
        ctx.extract(cached_download(f"mediawiki-{version}.tar.gz",
                                    f"{MEDIAWIKI_RELEASES}{branch}/mediawiki-{version}.tar.gz"))
        ctx.log("Installing MediaWiki")
        run_php = os.path.join(ctx.target, "maintenance", "run.php")
        env = {"WHP_DB_PASS": ctx.db["password"], "WHP_ADMIN_PASS": p["admin_password"]}
        ctx.php_cli(run_php, [
            "install", "--dbtype=mysql", "--dbserver=localhost", "--dbname=" + ctx.db["name"],
            "--dbuser=" + ctx.db["user"], "--dbpass=$WHP_DB_PASS",
            "--installdbuser=" + ctx.db["user"], "--installdbpass=$WHP_DB_PASS",
            "--server=" + _site_base_url(ctx), "--scriptpath=" + ("/" + ctx.path if ctx.path else ""),
            "--lang=" + p["locale"], "--pass=$WHP_ADMIN_PASS", "--confpath=" + ctx.target,
            p["title"], p["admin_user"],
        ], env=env, timeout=900, cwd=ctx.target)
        settings = os.path.join(ctx.target, "LocalSettings.php")
        if not os.path.exists(settings):
            raise ActionError("command_failed", "MediaWiki did not write LocalSettings.php")
        ctx.as_site(["chmod", "0600", "--", settings])
        try:
            ctx.php_cli(run_php, ["resetUserEmail", "--no-reset-password", p["admin_user"], p["admin_email"]],
                        timeout=300, cwd=ctx.target)
        except ActionError as exc:
            ctx.log(f"Could not set the e-mail address of the admin: {exc}")
        ctx.version = self.installed_version(ctx.target) or version

    def nginx_snippet(self, ctx):
        prefix = location_prefix(ctx.path)
        return ("# MediaWiki (app installer)\n"
                + deny_dirs(ctx.path, ["cache", "includes", "languages", "maintenance", "serialized", "tests",
                                       "mw-config", "images/deleted", "images/temp"])
                + f"location ~ ^{prefix}images/.*\\.(?:php|phtml|phar)$ {{ return 404; }}\n")

    def data_dirs(self, ctx):
        return []


class DokuWiki:
    """A wiki that keeps its pages in plain files: no database needed."""
    id = "dokuwiki"
    name = "DokuWiki"
    subdir = True
    locales = ["en", "nl"]
    admin_path = "doku.php?do=admin"
    page_cache = False
    database = False
    admin_user_re = r"[a-z0-9][a-z0-9._-]{2,59}"  # DokuWiki names are lowercase

    def release_info(self):
        version = None
        try:
            text = fetch_text("https://raw.githubusercontent.com/dokuwiki/dokuwiki/stable/VERSION",
                              timeout=RELEASE_TIMEOUT)
            m = re.match(r"\s*(\d{4}-\d{2}-\d{2}[a-z]?)", text)
            version = m.group(1) if m else None
        except ActionError:
            pass
        # The stable download is replaced at every release and hotfix.
        return {"version": version, "released": http_last_modified(DOKUWIKI_URL)}

    def installed_version(self, target):
        return read_version_file(os.path.join(target, "VERSION"), r"(\d{4}-\d{2}-\d{2}[a-z]?)", target)

    def install(self, ctx):
        p = ctx.params
        if ":" in p["admin_email"]:
            raise ActionError("invalid_params", "The e-mail address cannot contain ':' for DokuWiki")
        ctx.log("Downloading DokuWiki")
        ctx.extract(cached_download("dokuwiki-stable.tgz", DOKUWIKI_URL, max_age=86400))
        ctx.log("Configuring DokuWiki")
        hashed = ctx.php_eval("echo password_hash(getenv('WHP_ADMIN_PASS'), PASSWORD_BCRYPT);",
                              env={"WHP_ADMIN_PASS": p["admin_password"]}).stdout.strip()
        if not re.fullmatch(r"\$2y\$\d\d\$[./A-Za-z0-9]{53}", hashed):
            raise ActionError("command_failed", "Could not hash the admin password")
        conf = os.path.join(ctx.target, "conf")
        ctx.replace_private(os.path.join(conf, "local.php"), "\n".join([
            "<?php",
            "// Written by the Webhosting Panel app installer.",
            f"$conf['title'] = {php_string(p['title'])};",
            f"$conf['lang'] = {php_string(p['locale'])};",
            "$conf['license'] = 'cc-by-sa';",
            "$conf['useacl'] = 1;",
            "$conf['superuser'] = '@admin';",
            "$conf['passcrypt'] = 'bcrypt';",
            "$conf['disableactions'] = 'register';",
            "",
        ]))
        ctx.replace_private(os.path.join(conf, "users.auth.php"), (
            "# users.auth.php\n# <?php exit()?>\n# Don't modify the lines above\n#\n"
            "# login:passwordhash:Real Name:email:groups,comma,separated\n\n"
            f"{p['admin_user']}:{hashed}:{p['admin_user']}:{p['admin_email']}:admin,user\n"))
        ctx.replace_private(os.path.join(conf, "acl.auth.php"), (
            "# acl.auth.php\n# <?php exit()?>\n# Don't modify the lines above\n#\n"
            "# Everyone may read, logged-in users may edit and upload.\n"
            "*\t@ALL\t1\n*\t@user\t8\n"))
        ctx.as_site(["rm", "-f", "--", os.path.join(ctx.target, "install.php")])
        ctx.version = self.installed_version(ctx.target)

    def nginx_snippet(self, ctx):
        return "# DokuWiki (app installer)\n" + deny_dirs(ctx.path, ["data", "conf", "bin", "inc", "vendor"])

    def data_dirs(self, ctx):
        return []


class PhpBB:
    """Discussion forum, installed with phpBB's command line installer."""
    id = "phpbb"
    name = "phpBB"
    subdir = True
    locales = ["en"]
    admin_path = "adm/index.php"
    page_cache = False
    admin_user_re = r"[A-Za-z0-9][A-Za-z0-9._-]{2,19}"  # phpBB allows 3 to 20 characters

    def release_info(self):
        stable = json.loads(fetch_text(PHPBB_VERSIONS, timeout=RELEASE_TIMEOUT))["stable"]
        branch = max(stable, key=version_key)
        version = stable[branch].get("current")
        # versions.json marks no branch as end of life (not even 3.0), so the date of the
        # release folder on the download server is what tells whether phpBB is still maintained.
        listing = fetch_text(PHPBB_RELEASES.format(branch=branch), timeout=RELEASE_TIMEOUT, headers=PHPBB_HEADERS)
        released = None
        for line in listing.splitlines():
            if f'href="{version}/"' in line:
                released = MediaWiki._listing_date(line)
        return {"version": version, "released": released}

    def installed_version(self, target):
        return read_version_file(os.path.join(target, "includes", "constants.php"),
                                 r"define\(\s*'PHPBB_VERSION'\s*,\s*'([^']+)'", target)

    def config(self, ctx):
        p, q = ctx.params, json.dumps
        https = ctx.url.startswith("https:")
        return "\n".join([
            "installer:",
            "    admin:",
            f"        name: {q(p['admin_user'])}",
            f"        password: {q(p['admin_password'])}",
            f"        email: {q(p['admin_email'])}",
            "    board:",
            "        lang: \"en\"",
            f"        name: {q(p['title'])}",
            f"        description: {q(p['title'])}",
            "    database:",
            "        dbms: \"mysqli\"",
            "        dbhost: \"localhost\"",
            "        dbport: ~",
            f"        dbuser: {q(ctx.db['user'])}",
            f"        dbpasswd: {q(ctx.db['password'])}",
            f"        dbname: {q(ctx.db['name'])}",
            f"        table_prefix: {q('phpbb_' + secrets.token_hex(2) + '_')}",
            "    email:",
            # Mail through the server's own Postfix (PHP mail()), for activation and notifications.
            "        enabled: true",
            "        smtp_delivery: false",
            "        smtp_host: ~",
            "        smtp_port: ~",
            "        smtp_auth: ~",
            "        smtp_user: ~",
            "        smtp_pass: ~",
            "    server:",
            f"        cookie_secure: {'true' if https else 'false'}",
            f"        server_protocol: {q('https://' if https else 'http://')}",
            "        force_server_vars: false",
            f"        server_name: {q(ctx.domain)}",
            f"        server_port: {443 if https else 80}",
            f"        script_path: {q('/' + ctx.path if ctx.path else '/')}",
            "    extensions: []",
            "",
        ])

    def install(self, ctx):
        version = _latest_version(self)
        branch = ".".join(version.split(".")[:2])
        url = PHPBB_DOWNLOAD.format(branch=branch, version=version)
        ctx.log(f"Downloading phpBB {version}")
        expected = first_hex(fetch_text(url + ".sha256", headers=PHPBB_HEADERS), 64)
        ctx.extract(cached_download(f"phpBB-{version}.zip", url, sha256=expected, headers=PHPBB_HEADERS))
        ctx.log("Installing phpBB")
        config_file = ctx.private_tmp(self.config(ctx))
        cli = os.path.join(ctx.target, "install", "phpbbcli.php")
        ctx.php_cli(cli, ["install", config_file], timeout=900, cwd=ctx.target)
        config = os.path.join(ctx.target, "config.php")
        if read_version_file(config, r"(PHPBB_INSTALLED)", ctx.target) is None:
            raise ActionError("command_failed", "phpBB did not finish its installation")
        ctx.as_site(["chmod", "0600", "--", config])
        # The board stays closed while the installer is there.
        ctx.as_site(["rm", "-rf", "--", os.path.join(ctx.target, "install")])
        ctx.version = self.installed_version(ctx.target) or version

    def nginx_snippet(self, ctx):
        return ("# phpBB (app installer)\n"
                + deny_dirs(ctx.path, ["cache", "files", "images/avatars/upload", "includes", "phpbb",
                                       "store", "vendor", "install"])
                + deny_files(ctx.path, ["config.php", "common.php"]))

    def data_dirs(self, ctx):
        return []


class FreshRSS:
    """Feed reader (RSS/Atom), refreshed every 20 minutes by a timer of the website."""
    id = "freshrss"
    name = "FreshRSS"
    subdir = True
    locales = ["en", "nl"]
    admin_path = "p/i/"
    page_cache = False
    admin_user_re = r"[A-Za-z0-9][A-Za-z0-9_]{2,15}"  # FreshRSS user names
    timer = ("app/actualize_script.php", 20)

    def release_info(self):
        return github_info("FreshRSS/FreshRSS")

    def installed_version(self, target):
        return read_version_file(os.path.join(target, "constants.php"),
                                 r"FRESHRSS_VERSION\s*=\s*'([^']+)'", target)

    def install(self, ctx):
        p = ctx.params
        version = _latest_version(self)
        ctx.log(f"Downloading FreshRSS {version}")
        ctx.extract(cached_download(f"freshrss-{version}.tar.gz", FRESHRSS_DOWNLOAD.format(version)))
        ctx.log("Installing FreshRSS")
        prefix = "freshrss_" + secrets.token_hex(2) + "_"
        custom = os.path.join(ctx.target, "data", "config.custom.php")
        # The database password goes in through FreshRSS' own config.custom.php, not the command line.
        ctx.replace_private(custom, "<?php\nreturn " + _php_array({"db": {
            "type": "mysql", "host": "localhost", "user": ctx.db["user"], "password": ctx.db["password"],
            "base": ctx.db["name"], "prefix": prefix}}) + ";\n")
        try:
            ctx.as_site([ctx.php, "cli/do-install.php", "--default-user=" + p["admin_user"], "--auth-type=form",
                         "--environment=production", "--base-url=" + ctx.url.rstrip("/") + "/p/",
                         "--language=" + p["locale"], "--title=" + p["title"], "--db-type=mysql",
                         "--db-host=localhost", "--db-user=" + ctx.db["user"], "--db-base=" + ctx.db["name"],
                         "--db-prefix=" + prefix], timeout=600, cwd=ctx.target)
        finally:
            ctx.as_site(["rm", "-f", "--", custom])
        ctx.as_site([ctx.php, "cli/create-user.php", "--user=" + p["admin_user"], "--language=" + p["locale"],
                     "--email=" + p["admin_email"]], timeout=600, cwd=ctx.target)
        user_conf = os.path.join(ctx.target, "data", "users", p["admin_user"], "config.php")
        # FreshRSS logs in with bcrypt.js, which expects the $2a$ prefix (FreshRSS_password_Util::hash).
        ctx.php_eval(f"""$f = {php_string(user_conf)};
$c = include $f;
if (!is_array($c)) {{ fwrite(STDERR, "no user config\\n"); exit(1); }}
$h = password_hash(getenv('WHP_ADMIN_PASS'), PASSWORD_BCRYPT, ['cost' => 9]);
$c['passwordHash'] = preg_replace('/^\\$2[xy]\\$/', '\\$2a\\$', $h);
$c['is_admin'] = true;
$c['mail_login'] = getenv('WHP_ADMIN_EMAIL');
file_put_contents($f, "<?php\\nreturn " . var_export($c, true) . ";\\n", LOCK_EX);
""", env={"WHP_ADMIN_PASS": p["admin_password"], "WHP_ADMIN_EMAIL": p["admin_email"]})
        ctx.version = self.installed_version(ctx.target) or version

    def nginx_snippet(self, ctx):
        return "# FreshRSS (app installer)\n" + deny_dirs(
            ctx.path, ["app", "cli", "data", "docs", "extensions", "lib", "tests", "vendor", "Docker"])

    def data_dirs(self, ctx):
        return []


def _php_array(value):
    """A PHP literal for nested dicts / strings (for small generated config files)."""
    if isinstance(value, dict):
        return "[" + ", ".join(f"{php_string(k)} => {_php_array(v)}" for k, v in value.items()) + "]"
    return php_string(value)


def _sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


RECIPES = {r.id: r for r in (WordPress(), WooCommerce(), Joomla(), Nextcloud(), Piwigo(), MediaWiki(),
                             DokuWiki(), PhpBB(), FreshRSS())}


# --------------------------------------------------------------------------- shared steps

def _is_placeholder(path, domain):
    try:
        if os.path.islink(path) or os.path.getsize(path) > 512:
            return False
        with open(path, errors="replace") as fh:
            return f"<title>{domain}</title>" in fh.read()
    except OSError:
        return False


def check_target(ctx):
    """The target folder must be empty, apart from the placeholder page of a new site."""
    if not safe_inside(ctx.docroot, ctx.root):
        raise ActionError("forbidden", "The document root is not a plain folder inside the website")
    for item in load_state(ctx.domain):
        other = item.get("path", "")
        if other == ctx.path or not other or not ctx.path \
                or ctx.path.startswith(other + "/") or other.startswith(ctx.path + "/"):
            raise ActionError("conflict", f"{item.get('name') or item.get('app')} is already installed at /{other}")
    if not os.path.lexists(ctx.target):
        return
    if not os.path.isdir(ctx.target) or not safe_inside(ctx.target, ctx.docroot):
        raise ActionError("conflict", f"/{ctx.path} exists and is not a folder")
    for name in os.listdir(ctx.target):
        full = os.path.join(ctx.target, name)
        if name == ".well-known" or (name == "index.html" and _is_placeholder(full, ctx.domain)):
            continue
        raise ActionError("conflict", f"The folder /{ctx.path} is not empty ({name}). "
                                      "Remove its files first or choose another folder.")


def _snippet_path(domain, install_id):
    return os.path.join(NGINX_SITES, domain, f"app-{install_id}.conf")


def reload_nginx():
    run(["nginx", "-t"], timeout=60)
    run(["systemctl", "reload", "nginx"], timeout=60)


def write_snippet(domain, install_id, text):
    d = os.path.join(NGINX_SITES, domain)
    os.makedirs(d, mode=0o755, exist_ok=True)
    path = _snippet_path(domain, install_id)
    with open(path, "w") as fh:
        fh.write(f"# Managed by the Webhosting Panel app installer ({install_id})\n{text}")
    os.chmod(path, 0o644)
    try:
        reload_nginx()
    except ActionError:
        os.unlink(path)
        raise


def remove_snippet(domain, install_id):
    path = _snippet_path(domain, install_id)
    if os.path.exists(path):
        os.unlink(path)
        reload_nginx()


def clear_target(domain, root, docroot, path):
    """Remove the app's files. A root install leaves an empty document root with the placeholder page."""
    target = os.path.join(docroot, path) if path else docroot
    if not os.path.lexists(target):
        return
    if not safe_inside(target, docroot) or not safe_inside(docroot, root):
        raise ActionError("forbidden", "Refusing to remove files outside the document root")
    if path:
        shutil.rmtree(target)
        return
    for name in os.listdir(target):
        full = os.path.join(target, name)
        if name == ".well-known":
            continue
        if os.path.isdir(full) and not os.path.islink(full):
            shutil.rmtree(full)
        else:
            os.unlink(full)


def put_placeholder(ctx_like_user, docroot, domain):
    path = os.path.join(docroot, "index.html")
    if os.path.lexists(path):
        return
    pw = _pwnam(ctx_like_user)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
    try:
        os.fchown(fd, pw.pw_uid, pw.pw_gid)
        os.write(fd, f'<!doctype html><meta charset="utf-8"><title>{domain}</title><p>{domain}</p>\n'.encode())
    finally:
        os.close(fd)


def remove_data_dirs(root, dirs):
    for d in dirs:
        if os.path.lexists(d) and safe_inside(d, root) and d != os.path.realpath(root):
            shutil.rmtree(d)


def purge_cache(domain):
    run([WHP_SITE, "cache", domain, "purge", "--json"], timeout=120, check=False)


def _timer_units(install_id):
    name = f"whp-app-{install_id}"
    return name, os.path.join(SYSTEMD_DIR, f"{name}.service"), os.path.join(SYSTEMD_DIR, f"{name}.timer")


def write_timer(domain, item):
    """A systemd timer that runs an app's own background script as the site user (FreshRSS feeds)."""
    recipe = RECIPES.get(item.get("app"))
    spec = getattr(recipe, "timer", None)
    if not spec or not re.fullmatch(r"[0-9a-f]{8}", item.get("id", "")):
        return None
    script, minutes = spec
    wsite = wordpress.site_info(domain)
    target = os.path.join(wsite["docroot"], item["path"]) if item.get("path") else wsite["docroot"]
    if not re.fullmatch(r"s_[a-z0-9_]{1,30}", wsite.get("user") or "") or not re.fullmatch(r"\d\.\d", wsite.get("php") or ""):
        raise ActionError("internal", f"Website {domain} has no valid user or PHP version")
    if not re.fullmatch(r"/[A-Za-z0-9._/-]+", target):
        raise ActionError("internal", "Unexpected app folder")
    name, service, timer = _timer_units(item["id"])
    with open(service, "w") as fh:
        fh.write(
            f"# Managed by whp-agent (app installer): {recipe.name} on {domain}\n"
            "[Unit]\n"
            f"Description={recipe.name} background tasks for {domain}\n"
            "After=mariadb.service\n\n"
            "[Service]\nType=oneshot\n"
            f"User={wsite['user']}\nGroup={wsite['user']}\n"
            f"Slice={wsite.get('slice') or 'whp-sites.slice'}\n"
            "UMask=0022\nNice=10\nIOSchedulingClass=idle\n"
            "NoNewPrivileges=yes\nPrivateTmp=yes\nProtectSystem=full\nProtectHome=yes\n"
            "TimeoutStartSec=15min\nMemoryMax=512M\n"
            f"WorkingDirectory={target}\n"
            f"Environment=HOME={wsite['root']} TMPDIR={wsite['root']}/tmp\n"
            f"ExecStart=/usr/bin/php{wsite['php']} {target}/{script}\n")
    with open(timer, "w") as fh:
        fh.write(
            "# Managed by whp-agent (app installer)\n[Unit]\n"
            f"Description={recipe.name} for {domain} every {minutes} min\n\n"
            f"[Timer]\nOnCalendar=*:0/{minutes}\nRandomizedDelaySec=60s\nPersistent=false\n\n"
            "[Install]\nWantedBy=timers.target\n")
    run(["systemctl", "daemon-reload"], timeout=30)
    run(["systemctl", "enable", "--now", f"{name}.timer"], timeout=30)
    return name


def remove_timer(install_id):
    name, service, timer = _timer_units(install_id)
    if not (os.path.exists(service) or os.path.exists(timer)):
        return
    run(["systemctl", "disable", "--now", f"{name}.timer"], timeout=30, check=False)
    for f in (service, timer):
        if os.path.exists(f):
            os.unlink(f)
    run(["systemctl", "daemon-reload"], timeout=30, check=False)


def refresh_app_timers(domain):
    """Write the app timers of a site again, e.g. after its PHP version or slice changed."""
    changed = []
    for item in load_state(domain):
        if item.get("timer"):
            changed.append(write_timer(domain, item))
    return changed


def _claim(domain):
    with _busy_lock:
        if domain in _busy:
            raise ActionError("conflict", f"An app is already being installed or removed on {domain}")
        _busy.add(domain)


def _release(domain):
    with _busy_lock:
        _busy.discard(domain)


# --------------------------------------------------------------------------- actions

def _max_age(params):
    return v.integer(params, "max_age_days", required=False, default=DEFAULT_MAX_AGE_DAYS,
                     minimum=30, maximum=3650) or DEFAULT_MAX_AGE_DAYS


@action("apps.catalog")
def apps_catalog(params):
    """The installable apps with their latest release. maintained is false for an app that had no
    release in max_age_days or is end of life (it cannot be installed), null when not yet known."""
    max_age = _max_age(params)
    refresh = v.boolean(params, "refresh", required=False, default=False)
    recipes = list(RECIPES.values())
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        infos = list(pool.map(lambda r: release_info(r, refresh), recipes))
    apps = []
    for r, info in zip(recipes, infos):
        apps.append({
            "id": r.id, "name": r.name, "subdir": r.subdir, "locales": r.locales,
            "admin_path": r.admin_path, "database": getattr(r, "database", True),
            "page_cache": getattr(r, "page_cache", True),
            "admin_user_pattern": getattr(r, "admin_user_re", ADMIN_USER_RE),
            "latest_version": info.get("version"), "released_at": info.get("released"),
            "end_of_life": info.get("eol") is True, "checked_at": info.get("checked"),
            "maintained": maintained(info, max_age),
        })
    return {"apps": apps, "max_age_days": max_age}


def _with_versions(items, releases):
    """Add the version on disk (apps update themselves) and the latest release to each item."""
    out = []
    for item in items:
        item = dict(item)
        recipe = RECIPES.get(item.get("app"))
        current = item.get("version")
        if recipe is not None and hasattr(recipe, "installed_version"):
            target = item.get("target") or ""  # from the agent's own state file
            if target.startswith("/") and os.path.isdir(target):
                try:
                    current = recipe.installed_version(target) or current
                except (OSError, ActionError):
                    pass
        latest = (releases.get(item.get("app")) or {}).get("version")
        item.update({"version": current, "latest_version": latest, "update_available": newer(latest, current)})
        out.append(item)
    return out


@action("apps.list")
def apps_list(params):
    domain = v.domain(params, "domain", required=False)
    releases = _load_releases()
    if domain:
        return {"apps": _with_versions(load_state(domain), releases)}
    items = []
    if os.path.isdir(apps_dir()):
        for name in sorted(os.listdir(apps_dir())):
            if name.endswith(".json") and name != "releases.json":
                items += load_state(name[:-5])
    return {"apps": _with_versions(items, releases)}


def _install_params(params):
    app = v.choice(params, "app", list(RECIPES))
    recipe = RECIPES[app]
    path = (v.string(params, "path", required=False, default="", max_len=170) or "").strip("/")
    if path:
        v.string({"path": path}, "path", max_len=170, pattern=PATH_RE)
        if not recipe.subdir:
            raise ActionError("invalid_params", f"{recipe.name} can only be installed in the root of a website")
    locale = v.choice(params, "locale", ["en", "nl"], required=False, default="en")
    if locale not in recipe.locales:
        locale = "en"
    password = v.string(params, "admin_password", max_len=128)
    if len(password) < 12:
        raise ActionError("invalid_params", "admin_password must be at least 12 characters")
    return recipe, path, {
        "title": v.string(params, "title", required=False, default="My website", max_len=100) or "My website",
        "admin_user": v.string(params, "admin_user", max_len=60, pattern=getattr(recipe, "admin_user_re", ADMIN_USER_RE)),
        "admin_email": v.string(params, "admin_email", max_len=254, pattern=EMAIL_RE),
        "admin_password": password,
        "locale": locale,
        # WordPress and WooCommerce only: plugins and themes added right after the install.
        "plugin_set": wp_sets.validate(params.get("plugin_set")) if isinstance(recipe, WordPress) else None,
    }


@action("apps.install")
def apps_install(params):
    domain = v.domain(params, "domain")
    recipe, path, opts = _install_params(params)
    max_age = _max_age(params)
    site = site_info(domain)
    if maintained(release_info(recipe), max_age) is False:
        raise ActionError("forbidden", f"{recipe.name} is not offered: it had no release in the last "
                                       f"{max_age} days or its version is end of life")
    install_id = secrets.token_hex(4)

    check_target(Context(site, recipe.id, path, opts, lambda _line: None))
    _claim(domain)

    def work(_params, log):
        ctx = Context(site, recipe.id, path, opts, log)
        snippet_written = False
        try:
            check_target(ctx)
            if not os.path.lexists(ctx.target):
                ctx.as_site(["mkdir", "-p", "--", ctx.target], cwd=ctx.root)
            placeholder = os.path.join(ctx.target, "index.html")
            if _is_placeholder(placeholder, domain):
                os.unlink(placeholder)
            if getattr(recipe, "database", True):
                log("Creating the database")
                ctx.db = databases.create_for_site(domain, recipe.id[:8])
            recipe.install(ctx)
            plugin_set = None
            if opts.get("plugin_set"):
                plugin_set = wp_sets.apply(recipe.wp_runner(ctx), opts["plugin_set"], opts["locale"], log)
            snippet = recipe.nginx_snippet(ctx)
            if snippet:
                write_snippet(domain, install_id, snippet)
                snippet_written = True
            timer = None
            if getattr(recipe, "timer", None):
                timer = write_timer(domain, {"id": install_id, "app": recipe.id, "path": path})
            page_cache_off = False
            if not getattr(recipe, "page_cache", True) and site.get("cache"):
                run([WHP_SITE, "cache", domain, "off", "--json"], timeout=300)
                page_cache_off = True
            purge_cache(domain)
            item = {
                "id": install_id, "app": recipe.id, "name": recipe.name, "version": ctx.version,
                "domain": domain, "path": path, "url": ctx.url,
                "admin_url": ctx.url.rstrip("/") + "/" + recipe.admin_path,
                "admin_user": opts["admin_user"], "locale": opts["locale"],
                "database": ctx.db["name"] if ctx.db else None, "data_dirs": recipe.data_dirs(ctx),
                "timer": timer, "target": ctx.target, "installed_at": int(time.time()),
            }
            with _state_lock:
                items = load_state(domain)
                items.append(item)
                _save_state(domain, items)
            log(f"{recipe.name} {ctx.version or ''} is ready at {ctx.url}")
            return {**item, "page_cache_disabled": page_cache_off, "plugin_set": plugin_set}
        except Exception:
            log("Install failed, rolling back")
            try:
                remove_timer(install_id)
                if snippet_written:
                    remove_snippet(domain, install_id)
                if ctx.db:
                    databases.drop(ctx.db["name"], domain)
                # The folder was empty (or new) before, so everything in it is ours.
                clear_target(domain, ctx.root, ctx.docroot, path)
                if not path:
                    put_placeholder(ctx.user, ctx.docroot, domain)
                remove_data_dirs(ctx.root, recipe.data_dirs(ctx))
            except Exception as exc:  # pragma: no cover - best effort
                log(f"Rollback incomplete: {exc}")
            raise
        finally:
            ctx.cleanup()
            _release(domain)

    try:
        return start_job("apps.install", work, {})
    except Exception:
        _release(domain)
        raise


@action("apps.remove")
def apps_remove(params):
    domain = v.domain(params, "domain")
    install_id = v.string(params, "id", max_len=16, pattern=r"[0-9a-f]{8}")
    item = next((i for i in load_state(domain) if i.get("id") == install_id), None)
    if item is None:
        raise ActionError("not_found", "App installation not found")
    site = site_info(domain)
    _claim(domain)

    def work(_params, log):
        try:
            root = site.get("root") or os.path.join(VHOSTS, domain)
            log(f"Removing {item.get('name')} from {item.get('url')}")
            remove_timer(install_id)
            remove_snippet(domain, install_id)
            clear_target(domain, root, site["docroot"], item.get("path", ""))
            if not item.get("path"):
                put_placeholder(site["user"], site["docroot"], domain)
            remove_data_dirs(root, item.get("data_dirs") or [])
            if item.get("database"):
                try:
                    databases.drop(item["database"], domain)
                except ActionError as exc:
                    if exc.code != "not_found":
                        raise
                    log(f"Database {item['database']} was already gone")
            purge_cache(domain)
            with _state_lock:
                _save_state(domain, [i for i in load_state(domain) if i.get("id") != install_id])
            log("Removed")
            return {"id": install_id, "removed": True}
        finally:
            _release(domain)

    try:
        return start_job("apps.remove", work, {})
    except Exception:
        _release(domain)
        raise
