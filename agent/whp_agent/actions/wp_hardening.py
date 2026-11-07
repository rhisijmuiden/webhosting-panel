"""WordPress hardening: one-click security measures per WordPress install,
each of which can be switched off again (panel: WordPress > Security hardening).

Measures (keys as the panel sends them):

* ``file_edit``: ``DISALLOW_FILE_EDIT`` in wp-config.php, so a stolen admin
  login cannot edit plugin or theme PHP from wp-admin.
* ``php_folders``: Nginx refuses PHP in wp-content/uploads, upgrade and cache,
  in wp-includes (files WordPress never opens directly) and wp-admin/includes.
  For an install in the document root the uploads part is already refused
  for every site by the server (snippets/deny.conf); installs in a folder
  are not covered by that rule, this measure is.
* ``wp_config``: wp-config.php readable by the website only (mode 0600) and
  never served over the web.
* ``hide_version``: no WordPress version in the page source or feeds (mu-plugin;
  ``?ver=`` of core files becomes a hash so caches still refresh after an
  update) and readme.html / license.txt are not served.
* ``user_enum``: ``/?author=1`` scans are refused (Nginx), the users REST
  endpoint and the users sitemap are hidden from visitors who are not logged in.
* Salts (``wphard.salts``): new secret keys in wp-config.php, which logs
  everybody out. The previous keys are kept (root only) so the last refresh
  can be undone.

Directory listing needs no measure: Nginx never lists folders on this server
(``autoindex`` is off), the status reports it as a server protection.

Where things live:

* ``/etc/nginx/whp/sites/<domain>/25-wp-hardening.conf``: the Nginx rules of
  all installs of a site (locations before the PHP location of site-body.conf;
  the web-rules snippet 30-web-rules.conf is separate). ``nginx -t`` before
  every reload, the old file is put back when Nginx refuses.
* ``<install>/wp-content/mu-plugins/whp-hardening.php``: the WordPress part,
  written with the measures that are on and removed when none are.
* ``/var/lib/webhosting-panel/wp-hardening/<domain>.json``: which Nginx
  measures are on per install, the previous wp-config mode and salt refresh
  times; ``<domain>/<key>.salts.json`` (0600) the previous salts.

wp-config.php is edited as text by root through migration.wordpress_fix
(no symlinks, only files of the site user, mode and owner kept); no PHP runs.
"""

import fcntl
import hashlib
import json
import os
import re
import secrets
import stat
import time

from .. import paths, wordpress
from .. import validate as v
from ..migration import wordpress_fix as wpfix
from ..registry import ActionError, action
from . import web_rules

STATE = os.environ.get("WHP_WP_HARDENING_STATE", os.path.join(paths.STATE_DIR, "wp-hardening"))
SNIPPET = "25-wp-hardening.conf"
MU_FILE = "whp-hardening.php"
HEADER = "# Managed by whp-agent (panel: WordPress security hardening). Changes are overwritten from the panel.\n"

MEASURES = ("file_edit", "php_folders", "wp_config", "hide_version", "user_enum")
NGINX_MEASURES = ("php_folders", "wp_config", "hide_version", "user_enum")
MU_MEASURES = ("hide_version", "user_enum")
SALT_KEYS = ("AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY",
             "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT")
# Characters of generated salts: what WordPress uses, without quotes and backslashes.
SALT_CHARS = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_[]{}<>~+=,.;:/?|"
DEFAULT_CONFIG_MODE = 0o640
MARK = "// Added by the Webhosting Panel (WordPress security hardening)."

_BOOL_DEFINE = r"""^[ \t]*define\(\s*['"]{key}['"]\s*,\s*(true|false|1|0)\s*\)\s*;[^\n]*\n?"""
# Where WordPress expects own constants: above the "stop editing" line or wp-settings.php.
_ANCHORS = (
    re.compile(r"^/\*\s*That's all, stop editing!.*$", re.M | re.I),
    re.compile(r"^/\*\*\s*Absolute path to the WordPress directory.*$", re.M),
    re.compile(r"^[ \t]*if\s*\(\s*!\s*defined\(\s*['\"]ABSPATH['\"]\s*\)\s*\)", re.M),
    re.compile(r"^[ \t]*require(?:_once)?[ \t(]*ABSPATH[^\n]*wp-settings\.php", re.M),
)


# ---------------------------------------------------------------- wp-config text (pure)

def config_bool(text, key):
    """True/False when the constant is defined as a boolean, None when it is not defined."""
    m = re.search(_BOOL_DEFINE.format(key=re.escape(key)), text, re.M | re.I)
    if not m:
        return None
    return m.group(1).lower() in ("true", "1")


def _insert(text, line):
    for anchor in _ANCHORS:
        m = anchor.search(text)
        if m:
            return text[:m.start()] + line + "\n\n" + text[m.start():]
    raise ActionError("conflict", "wp-config.php has an unusual layout: the panel cannot find where to add settings")


def set_file_edit(text, enabled):
    """The wp-config text with DISALLOW_FILE_EDIT on or removed."""
    pattern = re.compile(_BOOL_DEFINE.format(key="DISALLOW_FILE_EDIT"), re.M | re.I)
    # Our own comment line above it goes too.
    # Our own block (comment, define and the blank line after it) goes as a whole.
    text = re.sub(r"^[ \t]*" + re.escape(MARK) + r"[ \t]*\n[ \t]*define\(\s*'DISALLOW_FILE_EDIT'\s*,\s*true\s*\)\s*;[ \t]*\n\n?",
                  "", text, flags=re.M)
    text = pattern.sub("", text)
    if enabled:
        text = _insert(text, f"{MARK}\ndefine( 'DISALLOW_FILE_EDIT', true );")
    return text


def new_salt():
    return "".join(secrets.choice(SALT_CHARS) for _ in range(64))


def read_salts(text):
    return {k: wpfix.config_value(text, k) for k in SALT_KEYS}


def set_salts(text, values):
    """Replace the salts that exist and add the ones that are missing."""
    missing = []
    for key, value in values.items():
        if wpfix.config_value(text, key) is None:
            missing.append(f"define( '{key}', {wpfix.php_string(value)} );")
        else:
            text = wpfix.set_config(text, key, value)
    if missing:
        text = _insert(text, "\n".join(missing))
    return text


# ---------------------------------------------------------------- nginx / mu-plugin text (pure)

def _prefix(path):
    """The install folder as a regex part: "" or "blog/" (clean_path allows no regex characters but ".")."""
    return (path.replace(".", r"\.") + "/") if path else ""


def render_nginx(installs):
    """installs: {path: [nginx measures]}. Locations per install, deepest folder first."""
    out = [HEADER.rstrip("\n")]
    for path in sorted(installs, key=lambda p: (-p.count("/") - (1 if p else 0), p)):
        on = [m for m in NGINX_MEASURES if m in installs[path]]
        if not on:
            continue
        p = _prefix(path)
        label = "/" + path if path else "/ (document root)"
        out.append(f"\n# WordPress in {label}")
        if "php_folders" in on:
            out.append(f'location ~* "^/{p}wp-content/(?:uploads|upgrade|cache)/.*\\.php(?:$|/)" {{ deny all; return 404; }}')
            out.append(f'location ~* "^/{p}wp-includes/[^/]+\\.php$" {{ deny all; return 404; }}')
            out.append(f'location ~* "^/{p}wp-includes/(?:js/tinymce/langs/.+\\.php|theme-compat/)" {{ deny all; return 404; }}')
            out.append(f'location ~* "^/{p}wp-admin/includes/" {{ deny all; return 404; }}')
        if "wp_config" in on:
            out.append(f'location ~* "^/{p}wp-config(?:-sample)?\\.php$" {{ deny all; return 404; }}')
        if "hide_version" in on:
            out.append(f'location ~* "^/{p}(?:readme\\.html|license\\.txt)$" {{ deny all; return 404; }}')
        if "user_enum" in on:
            var = "$whp_wph_" + (hashlib.sha1(path.encode()).hexdigest()[:8] if path else "root")
            out.append(f'set {var} "";')
            out.append(f'if ($arg_author ~ "^[0-9]") {{ set {var} "a"; }}')
            # Front end of this install only: wp-admin filters posts by ?author= too.
            out.append(f'if ($uri ~ "^/{p}") {{ set {var} "${{{var[1:]}}}u"; }}')
            out.append(f'if ($uri ~ "^/{p}wp-admin/") {{ set {var} "${{{var[1:]}}}x"; }}')
            out.append(f'if ({var} = "au") {{ return 403; }}')
    return "\n".join(out) + "\n"


MU_HEAD = r"""<?php
/**
 * Plugin Name: Dekwerk security hardening
 * Description: Security measures switched on in Dekwerk. Managed by Dekwerk: changes to this file are overwritten.
 */

defined( 'ABSPATH' ) || exit;
"""

MU_PARTS = {
    "hide_version": r"""
// whp:hide_version
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
// Core files: ?ver=<WordPress version> becomes a hash, so caches still refresh after an update.
$whp_hardening_ver = static function ( $src ) {
	$version = get_bloginfo( 'version' );
	if ( is_string( $src ) && $version && strpos( $src, 'ver=' . $version ) !== false ) {
		$salt = defined( 'NONCE_SALT' ) ? NONCE_SALT : ABSPATH;
		$src  = add_query_arg( 'ver', substr( md5( $version . $salt ), 0, 10 ), $src );
	}
	return $src;
};
add_filter( 'style_loader_src', $whp_hardening_ver, 9999 );
add_filter( 'script_loader_src', $whp_hardening_ver, 9999 );
""",
    "user_enum": r"""
// whp:user_enum
add_filter( 'rest_endpoints', static function ( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( strpos( $route, '/wp/v2/users' ) === 0 ) {
				unset( $endpoints[ $route ] );
			}
		}
	}
	return $endpoints;
} );
add_filter( 'wp_sitemaps_add_provider', static function ( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}, 10, 2 );
add_filter( 'oembed_response_data', static function ( $data ) {
	unset( $data['author_name'], $data['author_url'] );
	return $data;
} );
""",
}


def render_mu(enabled):
    parts = [MU_PARTS[m] for m in MU_MEASURES if m in enabled]
    return MU_HEAD + "".join(parts) if parts else None


def mu_measures(text):
    return [m for m in MU_MEASURES if text and f"// whp:{m}\n" in text]


# ---------------------------------------------------------------- state

def _state_file(domain):
    return os.path.join(STATE, f"{domain}.json")


def load_state(domain):
    try:
        with open(_state_file(domain)) as fh:
            data = json.load(fh)
    except (FileNotFoundError, ValueError):
        data = {}
    if not isinstance(data.get("installs"), dict):
        data["installs"] = {}
    return data


def save_state(domain, data):
    os.makedirs(STATE, mode=0o700, exist_ok=True)
    tmp = _state_file(domain) + ".new"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as fh:
        json.dump(data, fh, indent=1, sort_keys=True)
    os.replace(tmp, _state_file(domain))


def _salts_file(domain, path):
    key = "root" if not path else hashlib.sha1(path.encode()).hexdigest()[:12]
    return os.path.join(STATE, domain, f"{key}.salts.json")


class _Lock:
    """One hardening change per website at a time."""

    def __init__(self, domain):
        os.makedirs(STATE, mode=0o700, exist_ok=True)
        self.path = os.path.join(STATE, f".{domain}.lock")

    def __enter__(self):
        self.fh = open(self.path, "w")
        fcntl.flock(self.fh, fcntl.LOCK_EX)
        return self

    def __exit__(self, *exc):
        fcntl.flock(self.fh, fcntl.LOCK_UN)
        self.fh.close()


# ---------------------------------------------------------------- files of the install

def _site_ids(site):
    import pwd

    try:
        pw = pwd.getpwnam(site["user"])
    except KeyError:
        raise ActionError("internal", f"Website {site['domain']} has no system user")
    return pw.pw_uid, pw.pw_gid


def config_path(site, d):
    """wp-config.php of the install (WordPress also allows it one folder up)."""
    own = os.path.join(d, "wp-config.php")
    if os.path.lexists(own):
        return own
    parent = os.path.join(os.path.dirname(d), "wp-config.php")
    docroot = os.path.realpath(site["docroot"])
    up = os.path.dirname(d)
    if os.path.lexists(parent) and (os.path.realpath(up) == docroot or os.path.realpath(up).startswith(docroot + os.sep)
                                    or os.path.realpath(up) == os.path.dirname(docroot)):
        return parent
    raise ActionError("not_found", "wp-config.php not found")


def _open_config(path, uid):
    """fd of wp-config.php without following symlinks; only a regular file of the site user."""
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        raise ActionError("forbidden", "wp-config.php is not a regular file of the website")
    st = os.fstat(fd)
    if not stat.S_ISREG(st.st_mode) or st.st_uid != uid:
        os.close(fd)
        raise ActionError("forbidden", "wp-config.php is not a regular file of the website")
    return fd, st


def config_mode(path, uid):
    fd, st = _open_config(path, uid)
    os.close(fd)
    return stat.S_IMODE(st.st_mode)


def chmod_config(path, uid, mode):
    fd, _ = _open_config(path, uid)
    try:
        os.fchmod(fd, mode)
    finally:
        os.close(fd)


def read_config(path, uid):
    text = wpfix._read(path, uid)
    if text is None:
        raise ActionError("forbidden", "wp-config.php cannot be read (not a regular file of the website, or too large)")
    return text


def write_config(path, text, uid, gid):
    wpfix._write(path, text, uid, gid)


def mu_path(d):
    return os.path.join(d, "wp-content", "mu-plugins", MU_FILE)


def read_mu(d, uid):
    return wpfix._read(mu_path(d), uid)


def write_mu(site, d, enabled, uid, gid):
    text = render_mu(enabled)
    target = mu_path(d)
    content = os.path.join(d, "wp-content")
    real = os.path.realpath(content)
    docroot = os.path.realpath(site["docroot"])
    if os.path.islink(content) or not (real == docroot or real.startswith(docroot + os.sep)):
        raise ActionError("forbidden", "wp-content is not a plain folder of the website")
    if text is None:
        if os.path.lexists(target):
            wordpress.as_user(site, ["/usr/bin/rm", "-f", "--", target], cwd=d, timeout=60)
        return
    wordpress.as_user(site, ["/usr/bin/mkdir", "-p", "--", os.path.dirname(target)], cwd=d, timeout=60)
    if not os.path.lexists(target):
        wordpress.as_user(site, ["/usr/bin/touch", "--", target], cwd=d, timeout=60)
    wpfix._write(target, text, uid, gid)


def snippet_path(domain):
    return os.path.join(web_rules.NGINX_WHP, "sites", domain, SNIPPET)


def write_nginx(domain, state):
    """Write the snippet for all installs, test nginx and reload; put the old file back on failure."""
    target = snippet_path(domain)
    old = None
    if os.path.isfile(target):
        with open(target) as fh:
            old = fh.read()
    installs = {p: i.get("nginx", []) for p, i in state["installs"].items() if i.get("nginx")}
    new = render_nginx(installs) if installs else None
    if new == old:
        return False
    if new is None:
        os.unlink(target)
    else:
        web_rules._write(target, new)
    try:
        web_rules._nginx_test()
        web_rules.reload_nginx()
    except ActionError:
        if old is None:
            if os.path.exists(target):
                os.unlink(target)
        else:
            web_rules._write(target, old)
        web_rules.reload_nginx(check=False)
        raise
    return True


# ---------------------------------------------------------------- status

def _install(params):
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    site, d = wordpress.require_install(domain, path)
    return domain, path, site, d


def build_status(domain, path, site, d, state=None):
    uid, _ = _site_ids(site)
    state = state if state is not None else load_state(domain)
    inst = state["installs"].get(path, {})
    nginx_on = set(inst.get("nginx", [])) if os.path.isfile(snippet_path(domain)) else set()
    problems = []
    measures = {}

    try:
        cfg = config_path(site, d)
        text = read_config(cfg, uid)
        mode = config_mode(cfg, uid)
    except ActionError as exc:
        cfg, text, mode = None, None, None
        problems.append(exc.message)

    if text is not None:
        edit = config_bool(text, "DISALLOW_FILE_EDIT")
        mods = config_bool(text, "DISALLOW_FILE_MODS")
        measures["file_edit"] = {"on": bool(edit) or bool(mods), "via": "DISALLOW_FILE_MODS" if mods and not edit else None}
    else:
        measures["file_edit"] = {"on": False, "unknown": True}

    measures["php_folders"] = {"on": "php_folders" in nginx_on, "uploads_by_server": path == ""}
    measures["wp_config"] = {
        "on": mode is not None and not (mode & 0o077) and "wp_config" in nginx_on,
        "mode": f"{mode:04o}" if mode is not None else None,
        "private": mode is not None and not (mode & 0o077),
        "web_blocked": "wp_config" in nginx_on,
        "location": "parent" if cfg and os.path.dirname(cfg) != d else "install",
    }
    mu = mu_measures(read_mu(d, uid) or "")
    measures["hide_version"] = {"on": "hide_version" in mu and "hide_version" in nginx_on}
    measures["user_enum"] = {"on": "user_enum" in mu and "user_enum" in nginx_on}

    salts = read_salts(text) if text is not None else {}
    return {
        "domain": domain,
        "path": path,
        "measures": measures,
        "on": sum(1 for m in MEASURES if measures[m]["on"]),
        "total": len(MEASURES),
        "salts": {
            "complete": bool(salts) and all(salts.values()),
            "default": any(val and "put your unique phrase here" in val for val in salts.values()),
            "refreshed_at": inst.get("salts_refreshed_at"),
            "undo": os.path.isfile(_salts_file(domain, path)),
        },
        # Always on for every site on this server.
        "server": {"directory_listing": True, "php_in_uploads": True, "xmlrpc": True},
        "problems": problems,
    }


@action("wphard.status")
def status(params):
    """Which hardening measures are on for one WordPress install (read from the files themselves)."""
    domain, path, site, d = _install(params)
    return build_status(domain, path, site, d)


# ---------------------------------------------------------------- apply

def _wanted(params):
    raw = params.get("measures")
    if not isinstance(raw, dict) or not raw:
        raise ActionError("invalid_params", "measures must be an object like {\"file_edit\": true}")
    out = {}
    for key, value in raw.items():
        if key not in MEASURES:
            raise ActionError("invalid_params", f"Unknown measure: {key}")
        if not isinstance(value, bool):
            raise ActionError("invalid_params", f"measures.{key} must be true or false")
        out[key] = value
    return out


def _still_there(site, path):
    try:
        return os.path.isfile(os.path.join(wordpress.install_dir(site, path), "wp-includes", "version.php"))
    except ActionError:
        return False


@action("wphard.apply")
def apply(params):
    """Switch measures on or off for one install: {"domain", "path", "measures": {key: bool}}."""
    domain, path, site, d = _install(params)
    wanted = _wanted(params)
    uid, gid = _site_ids(site)
    changed = []
    with _Lock(domain):
        state = load_state(domain)
        inst = state["installs"].setdefault(path, {})
        before = build_status(domain, path, site, d, state)["measures"]

        # wp-config.php first: everything else can be redone, a refused edit stops here.
        if "file_edit" in wanted and before["file_edit"]["on"] != wanted["file_edit"]:
            cfg = config_path(site, d)
            text = read_config(cfg, uid)
            new = set_file_edit(text, wanted["file_edit"])
            if not wanted["file_edit"] and config_bool(new, "DISALLOW_FILE_MODS"):
                raise ActionError("conflict", "DISALLOW_FILE_MODS in wp-config.php also blocks file editing; remove it there yourself")
            if new != text:
                write_config(cfg, new, uid, gid)
            changed.append("file_edit")

        if "wp_config" in wanted:
            cfg = config_path(site, d)
            mode = config_mode(cfg, uid)
            if wanted["wp_config"] and mode & 0o077:
                inst["config_mode_before"] = mode
                chmod_config(cfg, uid, 0o600)
                changed.append("wp_config")
            elif not wanted["wp_config"] and not (mode & 0o077):
                chmod_config(cfg, uid, int(inst.pop("config_mode_before", DEFAULT_CONFIG_MODE)) & 0o7777 | 0o600)
                changed.append("wp_config")

        mu_wanted = {m for m in MU_MEASURES if wanted.get(m, m in mu_measures(read_mu(d, uid) or ""))}
        if mu_wanted != set(mu_measures(read_mu(d, uid) or "")):
            write_mu(site, d, mu_wanted, uid, gid)
            changed += [m for m in MU_MEASURES if m in wanted and m not in changed]

        nginx = set(inst.get("nginx", []))
        for m in NGINX_MEASURES:
            if m in wanted:
                (nginx.add if wanted[m] else nginx.discard)(m)
        inst["nginx"] = [m for m in NGINX_MEASURES if m in nginx]
        if not inst["nginx"]:
            inst.pop("nginx")
        # Forget installs that are gone, so their rules leave the snippet too.
        for p in list(state["installs"]):
            if p != path and not _still_there(site, p):
                state["installs"].pop(p)
        if not state["installs"].get(path):
            state["installs"].pop(path, None)
        try:
            if write_nginx(domain, state):
                changed += [m for m in NGINX_MEASURES if m in wanted and m not in changed]
        finally:
            save_state(domain, state)

        result = build_status(domain, path, site, d, state)
    result["changed"] = sorted(set(changed))
    return result


@action("wphard.salts")
def salts(params):
    """New secret keys and salts (logs everyone out), or {"undo": true} to put the previous ones back."""
    domain, path, site, d = _install(params)
    undo = bool(v.boolean(params, "undo", required=False, default=False))
    uid, gid = _site_ids(site)
    backup = _salts_file(domain, path)
    with _Lock(domain):
        cfg = config_path(site, d)
        text = read_config(cfg, uid)
        current = read_salts(text)
        if undo:
            try:
                with open(backup) as fh:
                    previous = json.load(fh)["salts"]
            except (FileNotFoundError, ValueError, KeyError, TypeError):
                raise ActionError("not_found", "There are no previous keys to put back")
            values = {k: previous[k] for k in SALT_KEYS if isinstance(previous.get(k), str)}
            write_config(cfg, set_salts(text, values), uid, gid)
            os.unlink(backup)
        else:
            os.makedirs(os.path.dirname(backup), mode=0o700, exist_ok=True)
            fd = os.open(backup + ".new", os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            with os.fdopen(fd, "w") as fh:
                json.dump({"salts": {k: val for k, val in current.items() if val is not None}, "saved_at": int(time.time())}, fh)
            write_config(cfg, set_salts(text, {k: new_salt() for k in SALT_KEYS}), uid, gid)
            os.replace(backup + ".new", backup)
        state = load_state(domain)
        inst = state["installs"].setdefault(path, {})
        if undo:
            inst.pop("salts_refreshed_at", None)
        else:
            inst["salts_refreshed_at"] = int(time.time())
        save_state(domain, state)
        result = build_status(domain, path, site, d, state)
    result["changed"] = ["salts"]
    return result
