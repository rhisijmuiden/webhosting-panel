"""Per-site web rules: redirects, password-protected folders, custom error
pages, force HTTPS, HSTS and IP/country blocking (panel: Redirects & access).

The panel owns the rules and sends all of them for one website with every
``webrules.apply``. The agent writes:

* ``/etc/nginx/whp/sites/<domain>/30-web-rules.conf``: server-level
  directives, included by the site's server blocks (also aliases that serve
  the site). Redirects and country blocks use ``if``/``return`` at server
  level (safe there, and before any location is picked); protected folders
  use ``auth_basic`` with variables, so every location (PHP, static, apps)
  inherits them without copying the PHP handling; IP rules are ``allow`` /
  ``deny``; error pages are ``error_page``.
* ``/etc/nginx/whp/auth/<domain>/<n>.htpasswd``: users of protected folder n
  (crypt hashes made by the panel, 0640 root:www-data).
* ``/etc/nginx/whp/hsts/<domain>.conf``: one entry for the ``$whp_hsts`` map
  (conf.d/whp-http.conf) when the site wants another HSTS header than the
  server default.
* Force HTTPS through ``whp-site https <domain> force|allow``.

Everything is checked with ``nginx -t``; on a failure the previous files are
put back and nothing changes for visitors.
"""

import grp
import ipaddress
import os
import re
import shutil
import time

from .. import paths
from .. import validate as v
from ..registry import ActionError, action
from ..run import run
from ..wordpress import site_info

NGINX_WHP = os.environ.get("WHP_NGINX_DIR", "/etc/nginx/whp")
SITES_DB = os.environ.get("WHP_SITES_DB", os.path.join(paths.STATE_DIR, "sites"))
GEOIP_DB = os.environ.get("WHP_GEOIP_DB", os.path.join(paths.STATE_DIR, "geoip", "country.mmdb"))
GEOIP_CONF = os.environ.get("WHP_GEOIP_CONF", "/etc/nginx/conf.d/whp-geoip.conf")
WHP_SITE = "/usr/local/sbin/whp-site"
NGINX_PID = os.environ.get("WHP_NGINX_PID", "/run/nginx.pid")
SNIPPET = "30-web-rules.conf"
# SSL states in which the site has an HTTPS server block.
HTTPS_STATES = ("active", "imported", "custom", "wildcard")
HEADER = "# Managed by whp-agent (panel: Redirects & access). Changes are overwritten from the panel.\n"

REDIRECT_CODES = (301, 302, 307, 308)
ERROR_CODES = (400, 401, 403, 404, 410, 429, 500, 502, 503, 504)
HSTS_DEFAULT = 31536000
MAX_REDIRECTS = 200
MAX_FOLDERS = 20
MAX_USERS = 50
MAX_NETWORKS = 200

# Paths on the website: plain characters only, so they need no escaping in a
# regex or an nginx string.
_PATH = re.compile(r"^/(?:[A-Za-z0-9._~@+,=!-]+/?)*$")
# Targets: no quotes, spaces, braces, semicolons, backslashes or nginx variables.
_TARGET_CHARS = re.compile(r"^[^\s\"'\\${};<>`]+$")
_URL = re.compile(r"^https?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:[/?#].*)?$")
_USER = re.compile(r"^[A-Za-z0-9._@-]{1,64}$")
_HASH = re.compile(r"^\$(?:6|5|2y|2b)\$[./A-Za-z0-9$=]{20,200}$")
_REALM = re.compile(r"^[^\"'\\${};\x00-\x1f]{1,60}$")
_COUNTRY = re.compile(r"^[A-Z]{2}$")


# ---------------------------------------------------------------- validation

def _items(params, key, maximum):
    value = params.get(key)
    if value is None:
        return []
    if not isinstance(value, list):
        raise ActionError("invalid_params", f"{key} must be a list")
    if len(value) > maximum:
        raise ActionError("invalid_params", f"{key}: at most {maximum} entries")
    for item in value:
        if not isinstance(item, dict):
            raise ActionError("invalid_params", f"{key} entries must be objects")
    return value


def site_path(value, field):
    if not isinstance(value, str) or len(value) > 200 or not _PATH.match(value) or "/../" in value + "/" or "/./" in value + "/":
        raise ActionError("invalid_params", f"{field} must be a path on the website like /old-page")
    if len(value) > 1:
        value = value.rstrip("/")
    return value


def target(value, field):
    if not isinstance(value, str) or not value or len(value) > 2000 or not _TARGET_CHARS.match(value):
        raise ActionError("invalid_params", f"{field} must be a URL (https://...) or a path (/new-page)")
    if not (value.startswith("/") and not value.startswith("//")) and not _URL.match(value):
        raise ActionError("invalid_params", f"{field} must be a URL (https://...) or a path (/new-page)")
    return value


def _redirect(item, i):
    src = site_path(item.get("from"), f"redirects[{i}].from")
    dst = target(item.get("to"), f"redirects[{i}].to")
    code = v.integer(item, "code", required=False, default=301)
    if code not in REDIRECT_CODES:
        raise ActionError("invalid_params", f"redirects[{i}].code must be one of {REDIRECT_CODES}")
    prefix = bool(v.boolean(item, "prefix", required=False, default=False))
    if dst.split("?")[0].rstrip("/") == src.rstrip("/") and dst.startswith("/"):
        raise ActionError("invalid_params", f"redirects[{i}] points to itself")
    if prefix and src == "/" and dst.startswith("/"):
        raise ActionError("invalid_params", f"redirects[{i}] would redirect every page to this website again")
    return {
        "from": src,
        "to": dst,
        "code": code,
        "prefix": prefix,
        "keep_query": bool(v.boolean(item, "keep_query", required=False, default=True)),
    }


def _folder(item, i):
    path = site_path(item.get("path"), f"protected[{i}].path")
    realm = item.get("realm") or "Restricted area"
    if not isinstance(realm, str) or not _REALM.match(realm) or realm.strip().lower() == "off":
        raise ActionError("invalid_params", f"protected[{i}].realm has invalid characters")
    users = _items(item, "users", MAX_USERS)
    if not users:
        raise ActionError("invalid_params", f"protected[{i}] needs at least one user")
    out, seen = [], set()
    for u in users:
        name, hashed = u.get("name"), u.get("hash")
        if not isinstance(name, str) or not _USER.match(name):
            raise ActionError("invalid_params", f"protected[{i}]: invalid user name")
        if not isinstance(hashed, str) or not _HASH.match(hashed):
            raise ActionError("invalid_params", f"protected[{i}]: invalid password hash for {name}")
        if name in seen:
            raise ActionError("invalid_params", f"protected[{i}]: user {name} twice")
        seen.add(name)
        out.append({"name": name, "hash": hashed})
    return {"path": path, "realm": realm, "users": out}


def _error_pages(params):
    raw = params.get("error_pages") or {}
    if not isinstance(raw, dict):
        raise ActionError("invalid_params", "error_pages must be an object {code: path}")
    out = {}
    for code, path in raw.items():
        try:
            c = int(code)
        except (TypeError, ValueError):
            c = 0
        if c not in ERROR_CODES:
            raise ActionError("invalid_params", f"error_pages: {code} is not a supported status code")
        p = site_path(path, f"error_pages.{c}")
        if p == "/":
            raise ActionError("invalid_params", f"error_pages.{c} must be a file like /404.html")
        out[c] = p
    return dict(sorted(out.items()))


def _hsts(params):
    raw = params.get("hsts")
    if raw is None:
        raw = {}
    if not isinstance(raw, dict):
        raise ActionError("invalid_params", "hsts must be an object")
    cfg = {
        "enabled": bool(v.boolean(raw, "enabled", required=False, default=True)),
        "max_age": v.integer(raw, "max_age", required=False, default=HSTS_DEFAULT, minimum=300, maximum=63072000),
        "subdomains": bool(v.boolean(raw, "subdomains", required=False, default=False)),
        "preload": bool(v.boolean(raw, "preload", required=False, default=False)),
    }
    if cfg["preload"] and (not cfg["subdomains"] or cfg["max_age"] < 31536000):
        raise ActionError("invalid_params", "HSTS preload needs includeSubDomains and a max-age of at least one year")
    return cfg


def _network(value, field):
    params = {field: value}
    return str(v.ip_or_network(params, field))


def _access(params):
    raw = params.get("access")
    if raw is None:
        raw = {}
    if not isinstance(raw, dict):
        raise ActionError("invalid_params", "access must be an object")
    mode = v.choice(raw, "mode", ["off", "deny", "allow"], required=False, default="off")
    nets = raw.get("networks") or []
    if not isinstance(nets, list) or len(nets) > MAX_NETWORKS:
        raise ActionError("invalid_params", f"access.networks: a list of at most {MAX_NETWORKS} addresses")
    networks = sorted({_network(n, "access.networks") for n in nets})
    cmode = v.choice(raw, "countries_mode", ["off", "deny", "allow"], required=False, default="off")
    countries = raw.get("countries") or []
    if not isinstance(countries, list) or len(countries) > 250:
        raise ActionError("invalid_params", "access.countries must be a list of country codes")
    codes = []
    for c in countries:
        if not isinstance(c, str) or not _COUNTRY.match(c.upper()):
            raise ActionError("invalid_params", f"access.countries: {c!r} is not a two-letter country code")
        codes.append(c.upper())
    codes = sorted(set(codes))
    if mode != "off" and not networks:
        mode = "off"
    if cmode != "off" and not codes:
        cmode = "off"
    return {"mode": mode, "networks": networks, "countries_mode": cmode, "countries": codes}


def parse(params):
    domain = v.domain(params, "domain")
    redirects = [_redirect(r, i) for i, r in enumerate(_items(params, "redirects", MAX_REDIRECTS))]
    seen = set()
    for r in redirects:
        key = (r["from"], r["prefix"])
        if key in seen:
            raise ActionError("invalid_params", f"Two redirects for {r['from']}")
        seen.add(key)
    folders = [_folder(f, i) for i, f in enumerate(_items(params, "protected", MAX_FOLDERS))]
    if len({f["path"] for f in folders}) != len(folders):
        raise ActionError("invalid_params", "A folder is protected twice")
    return {
        "domain": domain,
        "redirects": redirects,
        "protected": folders,
        "error_pages": _error_pages(params),
        "force_https": bool(v.boolean(params, "force_https", required=False, default=True)),
        "hsts": _hsts(params),
        "access": _access(params),
    }


# ---------------------------------------------------------------- rendering

def _rx(path):
    """Regex for a validated site path: only '.' and '+' need escaping."""
    return path.replace(".", r"\.").replace("+", r"\+")


def _redirect_lines(r):
    to = r["to"]
    # A target with its own query string keeps it; otherwise the visitor's query string follows.
    q = "$is_args$args" if r["keep_query"] and "?" not in to else ""
    if r["prefix"]:
        rx = "^/(.*)$" if r["from"] == "/" else f"^{_rx(r['from'])}(?:/(.*))?$"
        dest = to if "?" in to else to.rstrip("/") + "/$1"
    else:
        rx = "^/$" if r["from"] == "/" else f"^{_rx(r['from'])}/?$"
        dest = to
    return f'if ($uri ~ "{rx}") {{ return {r["code"]} "{dest}{q}"; }}'


def auth_dir(domain):
    return os.path.join(NGINX_WHP, "auth", domain)


def render(cfg):
    d = cfg["domain"]
    out = [HEADER.rstrip("\n"), f"# Website: {d}"]
    acc = cfg["access"]

    if acc["mode"] == "allow":
        out.append("# Only these addresses may visit the website.")
        out += ["allow 127.0.0.1;", "allow ::1;"]
        # The server itself (uptime checks, cron jobs that call the site).
        out += [f"allow {ip};" for ip in cfg.get("server_ips", [])]
        out += [f"allow {n};" for n in acc["networks"]]
        out.append("deny all;")
    elif acc["mode"] == "deny":
        out.append("# Blocked addresses.")
        out += [f"deny {n};" for n in acc["networks"]]

    if acc["countries_mode"] != "off":
        rx = "^(" + "|".join(acc["countries"]) + ")$"
        out.append(f"# Countries ({acc['countries_mode']}), from the GeoIP database.")
        out.append('set $whp_geo_block "";')
        if acc["countries_mode"] == "deny":
            out.append(f'if ($whp_country ~ "{rx}") {{ set $whp_geo_block 1; }}')
        else:
            out.append(f'if ($whp_country !~ "{rx}") {{ set $whp_geo_block 1; }}')
            out.append('if ($whp_country = "") { set $whp_geo_block ""; }')
        out.append('if ($uri ~ "^/\\.well-known/acme-challenge/") { set $whp_geo_block ""; }')
        out.append("if ($whp_geo_block) { return 403; }")

    if cfg["redirects"]:
        out.append("# Redirects (most specific first).")
        ordered = sorted(cfg["redirects"], key=lambda r: (-len(r["from"]), r["prefix"]))
        out += [_redirect_lines(r) for r in ordered]

    if cfg["protected"]:
        out.append("# Password-protected folders (longest path wins).")
        out.append('set $whp_auth "off";')
        out.append(f'set $whp_auth_file "{auth_dir(d)}/none";')
        for i, f in sorted(enumerate(cfg["protected"]), key=lambda x: len(x[1]["path"])):
            rx = "^/" if f["path"] == "/" else f"^{_rx(f['path'])}(?:/|$)"
            out.append(f'if ($uri ~ "{rx}") {{ set $whp_auth "{f["realm"]}"; set $whp_auth_file "{auth_dir(d)}/{i + 1}.htpasswd"; }}')
        out.append("auth_basic $whp_auth;")
        out.append("auth_basic_user_file $whp_auth_file;")

    if cfg["error_pages"]:
        out.append("# Custom error pages (files in the website folder).")
        out += [f"error_page {c} {p};" for c, p in cfg["error_pages"].items()]

    return "\n".join(out) + "\n"


def hsts_value(h):
    if not h["enabled"]:
        return ""
    value = f"max-age={h['max_age']}"
    if h["subdomains"]:
        value += "; includeSubDomains"
    if h["preload"]:
        value += "; preload"
    return value


def render_hsts(cfg):
    """Map entry for conf.d/whp-http.conf, or None when the server default fits."""
    h = cfg["hsts"]
    if h["enabled"] and h["max_age"] == HSTS_DEFAULT and not h["subdomains"] and not h["preload"]:
        return None
    return f'{HEADER}"on:{cfg["domain"]}" "{hsts_value(h)}";\n'


def render_htpasswd(folder):
    return "".join(f"{u['name']}:{u['hash']}\n" for u in folder["users"])


def is_empty(cfg):
    a = cfg["access"]
    return not (cfg["redirects"] or cfg["protected"] or cfg["error_pages"] or a["mode"] != "off" or a["countries_mode"] != "off")


# ---------------------------------------------------------------- files

def snippet_path(domain):
    return os.path.join(NGINX_WHP, "sites", domain, SNIPPET)


def hsts_path(domain):
    return os.path.join(NGINX_WHP, "hsts", f"{domain}.conf")


def http_allowed_flag(domain):
    return os.path.join(SITES_DB, f"{domain}.http-allowed")


def geoip_available():
    return os.path.isfile(GEOIP_DB) and os.path.isfile(GEOIP_CONF)


def _www_gid():
    try:
        return grp.getgrnam("www-data").gr_gid
    except KeyError:
        return None


def _write(path, text, mode=0o644, gid=None):
    os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
    tmp = f"{path}.whp-new"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, mode)
    with os.fdopen(fd, "w") as fh:
        fh.write(text)
    os.chmod(tmp, mode)
    if gid is not None and os.geteuid() == 0:
        os.chown(tmp, 0, gid)
    os.replace(tmp, path)


def _snapshot(domain):
    """The current files, to put back when nginx refuses the new ones."""
    snap = {"files": {}, "auth": {}}
    for p in (snippet_path(domain), hsts_path(domain)):
        if os.path.isfile(p):
            with open(p) as fh:
                snap["files"][p] = fh.read()
        else:
            snap["files"][p] = None
    ad = auth_dir(domain)
    if os.path.isdir(ad):
        for name in os.listdir(ad):
            with open(os.path.join(ad, name)) as fh:
                snap["auth"][name] = fh.read()
    return snap


def _restore(domain, snap):
    for p, content in snap["files"].items():
        if content is None:
            if os.path.exists(p):
                os.unlink(p)
        else:
            _write(p, content)
    ad = auth_dir(domain)
    shutil.rmtree(ad, ignore_errors=True)
    for name, content in snap["auth"].items():
        _write(os.path.join(ad, name), content, 0o640, _www_gid())


def write_files(cfg):
    d = cfg["domain"]
    if is_empty(cfg):
        if os.path.exists(snippet_path(d)):
            os.unlink(snippet_path(d))
    else:
        _write(snippet_path(d), render(cfg))
    hsts = render_hsts(cfg)
    if hsts is None:
        if os.path.exists(hsts_path(d)):
            os.unlink(hsts_path(d))
    else:
        _write(hsts_path(d), hsts)
    ad = auth_dir(d)
    shutil.rmtree(ad, ignore_errors=True)
    if cfg["protected"]:
        os.makedirs(ad, mode=0o750, exist_ok=True)
        gid = _www_gid()
        if gid is not None and os.geteuid() == 0:
            os.chown(ad, 0, gid)
        for i, f in enumerate(cfg["protected"]):
            _write(os.path.join(ad, f"{i + 1}.htpasswd"), render_htpasswd(f), 0o640, gid)


def server_ips():
    proc = run(["hostname", "-I"], timeout=10, check=False)
    ips = []
    for part in (proc.stdout or "").split():
        try:
            ips.append(str(ipaddress.ip_address(part.split("%")[0])))
        except ValueError:
            continue
    return sorted(set(ips))


def _workers(master):
    """Pids of the nginx worker processes of this master."""
    pids = set()
    try:
        for entry in os.listdir("/proc"):
            if not entry.isdigit():
                continue
            try:
                with open(f"/proc/{entry}/stat") as fh:
                    if int(fh.read().rsplit(")", 1)[1].split()[1]) == master:
                        pids.add(int(entry))
            except (OSError, ValueError, IndexError):
                continue
    except OSError:
        pass
    return pids


def reload_nginx(check=True):
    """Reload nginx and wait until the old workers are gone, so the next
    request is answered with the new rules (like whp-site's nginx_reload_wait)."""
    try:
        with open(NGINX_PID) as fh:
            master = int(fh.read().strip())
    except (OSError, ValueError):
        master = None
    old = _workers(master) if master else set()
    run(["systemctl", "reload", "nginx"], timeout=60, check=check)
    for _ in range(100):
        if not old or not (old & _workers(master)):
            break
        time.sleep(0.1)


def _nginx_test():
    proc = run(["nginx", "-t"], timeout=60, check=False)
    if proc.returncode != 0:
        raise ActionError("conflict", "Nginx refused these rules, nothing was changed", {"stderr": proc.stderr[-2000:]})


# ---------------------------------------------------------------- actions

@action("webrules.apply")
def apply(params):
    """Write all web rules of one website, test nginx and reload it."""
    cfg = parse(params)
    d = cfg["domain"]
    site = site_info(d)
    if cfg["access"]["countries_mode"] != "off" and not geoip_available():
        raise ActionError("conflict", "Country blocking is not available: the GeoIP country database is not installed on this server")
    if cfg["access"]["mode"] == "allow":
        cfg["server_ips"] = server_ips()
    force_was = not os.path.exists(http_allowed_flag(d))
    snap = _snapshot(d)
    write_files(cfg)
    try:
        _nginx_test()
        if cfg["force_https"] != force_was:
            run([WHP_SITE, "https", d, "force" if cfg["force_https"] else "allow", "--json"], timeout=180)
        else:
            reload_nginx()
    except ActionError:
        _restore(d, snap)
        reload_nginx(check=False)
        raise
    missing = []
    for code, path in cfg["error_pages"].items():
        if not os.path.isfile(os.path.join(site["docroot"], path.lstrip("/"))):
            missing.append({"code": code, "path": path})
    return {
        "domain": d,
        "redirects": len(cfg["redirects"]),
        "protected": len(cfg["protected"]),
        "error_pages": len(cfg["error_pages"]),
        "missing_error_pages": missing,
        "force_https": cfg["force_https"],
        "hsts": hsts_value(cfg["hsts"]),
        "https_active": site["ssl"] in HTTPS_STATES,
    }


@action("webrules.status")
def status(params):
    """What the server offers (GeoIP) and the current HTTPS state of a website."""
    data = {"geoip": {"available": geoip_available(), "updated_at": None}}
    if os.path.isfile(GEOIP_DB):
        data["geoip"]["updated_at"] = int(os.path.getmtime(GEOIP_DB))
    domain = v.domain(params, "domain", required=False)
    if domain:
        site = site_info(domain)
        data["domain"] = domain
        data["force_https"] = not os.path.exists(http_allowed_flag(domain))
        data["https_active"] = site["ssl"] in HTTPS_STATES
        data["rules_file"] = os.path.exists(snippet_path(domain))
    return data
