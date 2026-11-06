"""One-click apps: install WordPress, Joomla or Nextcloud into a website.

    apps.catalog                       which apps this agent can install
    apps.list    {domain?}             installed apps (from the agent's own state)
    apps.install {domain, app, ...}    job: database + files + configuration + admin user
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

Adding an app: write a recipe class with install() (and optionally
nginx_snippet() / data_dirs()), add it to RECIPES, and add its card to
packages/apps/config/apps.php in the panel.
"""

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

from .. import paths, wordpress
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


def download(url, dest, timeout=300):
    """Download url to dest (in the agent's own cache). Returns the sha256 and sha512."""
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
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

    def install(self, ctx):
        p = ctx.params
        locale = self.WP_LOCALE[p["locale"]]
        # The shared runner of the WordPress toolkit: WP-CLI as the site user under
        # systemd-run in whp-sites.slice, with the site's PHP version and UMask 0022.
        wsite = wordpress.site_info(ctx.domain)
        cli = self.wp_cli()

        def wp(args, input_text=None, timeout=300):
            argv = wordpress.wp_argv(wsite, ctx.target, args, timeout=timeout)
            argv[argv.index(wordpress.WP_CLI)] = cli
            return run(argv, timeout=timeout + 15, input_text=input_text)

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

    ASSET = re.compile(r"^Joomla_[0-9.]+-Stable-Full_Package\.zip$")

    def release(self):
        doc = json.loads(fetch_text(JOOMLA_RELEASE_API, headers={"Accept": "application/vnd.github+json"}))
        for asset in doc.get("assets", []):
            if self.ASSET.match(asset.get("name", "")):
                return doc.get("tag_name", "").lstrip("v"), asset
        raise ActionError("command_failed", "No Joomla full package in the latest release")

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


def _sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


RECIPES = {r.id: r for r in (WordPress(), Joomla(), Nextcloud())}


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


def _claim(domain):
    with _busy_lock:
        if domain in _busy:
            raise ActionError("conflict", f"An app is already being installed or removed on {domain}")
        _busy.add(domain)


def _release(domain):
    with _busy_lock:
        _busy.discard(domain)


# --------------------------------------------------------------------------- actions

@action("apps.catalog")
def apps_catalog(params):
    return {"apps": [{"id": r.id, "name": r.name, "subdir": r.subdir, "locales": r.locales,
                      "admin_path": r.admin_path, "database": True,
                      "page_cache": getattr(r, "page_cache", True)} for r in RECIPES.values()]}


@action("apps.list")
def apps_list(params):
    domain = v.domain(params, "domain", required=False)
    if domain:
        return {"apps": load_state(domain)}
    items = []
    if os.path.isdir(apps_dir()):
        for name in sorted(os.listdir(apps_dir())):
            if name.endswith(".json"):
                items += load_state(name[:-5])
    return {"apps": items}


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
        "admin_user": v.string(params, "admin_user", max_len=60, pattern=ADMIN_USER_RE),
        "admin_email": v.string(params, "admin_email", max_len=254, pattern=EMAIL_RE),
        "admin_password": password,
        "locale": locale,
    }


@action("apps.install")
def apps_install(params):
    domain = v.domain(params, "domain")
    recipe, path, opts = _install_params(params)
    site = site_info(domain)
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
            log("Creating the database")
            ctx.db = databases.create_for_site(domain, recipe.id[:8])
            recipe.install(ctx)
            snippet = recipe.nginx_snippet(ctx)
            if snippet:
                write_snippet(domain, install_id, snippet)
                snippet_written = True
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
                "database": ctx.db["name"], "data_dirs": recipe.data_dirs(ctx),
                "installed_at": int(time.time()),
            }
            with _state_lock:
                items = load_state(domain)
                items.append(item)
                _save_state(domain, items)
            log(f"{recipe.name} {ctx.version or ''} is ready at {ctx.url}")
            return {**item, "page_cache_disabled": page_cache_off}
        except Exception:
            log("Install failed, rolling back")
            try:
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
