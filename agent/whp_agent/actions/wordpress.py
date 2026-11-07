"""WordPress toolkit: find installs, list outdated parts, update everything in one
go (with a snapshot and automatic rollback), take over WP-cron with a systemd
timer, manage administrator passwords and create one-click login links.

All WordPress work runs through wordpress.wp(), i.e. WP-CLI as the site user.
"""

import hashlib
import json
import os
import re
import secrets
import shutil
import string
import time
from pathlib import Path

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

STATE = Path(paths.STATE_DIR) / "wordpress"
SNAPSHOTS = STATE / "snapshots"
SYSTEMD_DIR = Path("/etc/systemd/system")
WHP_SITE = "/usr/local/sbin/whp-site"
MU_PLUGIN = Path(__file__).resolve().parent.parent / "data" / "whp-login.php"
KEEP_SNAPSHOTS = 2
CRON_INTERVALS = [1, 5, 15, 30, 60]
_SLUG = re.compile(r"^[a-z0-9][a-z0-9._-]{0,99}$")


def _domain_path(params):
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    return domain, path


def _key(domain, path):
    """A file/unit friendly name for an install."""
    site = wordpress.site_info(domain)
    suffix = "root" if not path else hashlib.sha1(path.encode()).hexdigest()[:8]
    return f"{site['id']}-{suffix}"


def _slugs(params, key):
    value = params.get(key)
    if value in (None, False):
        return None
    if value is True or value == "all":
        return "all"
    items = v.list_of(params, key, lambda s: s if isinstance(s, str) and _SLUG.fullmatch(s) else _bad(key), max_items=200)
    return items or None


def _bad(key):
    raise ActionError("invalid_params", f"{key} must be 'all' or a list of plugin/theme slugs")


# ---------------------------------------------------------------- status

def _site_url(domain, path):
    try:
        url = wordpress.wp(domain, ["option", "get", "siteurl"], path=path, timeout=60).stdout.strip().splitlines()[-1]
    except (ActionError, IndexError):
        url = ""
    if not re.match(r"^https?://", url or ""):
        site = wordpress.site_info(domain)
        scheme = "https" if site["ssl"] == "active" else "http"
        url = f"{scheme}://{domain}" + (f"/{path}" if path else "")
    return url.rstrip("/")


def _items(rows, kind):
    out = []
    for r in rows if isinstance(rows, list) else []:
        if kind == "plugin" and r.get("status") in ("must-use", "dropin"):
            continue
        out.append({
            "name": r.get("name", ""),
            "title": r.get("title") or r.get("name", ""),
            "status": r.get("status", ""),
            "version": r.get("version", ""),
            "update": r.get("update") == "available",
            "update_version": r.get("update_version") or None,
            "auto_update": r.get("auto_update") == "on",
        })
    return out


def status(domain, path):
    _, d = wordpress.require_install(domain, path)
    version = wordpress.read_version(d)
    errors = []

    def ask(label, argv):
        try:
            return wordpress.wp_json(domain, argv, path=path, timeout=180)
        except ActionError as exc:
            errors.append(f"{label}: {exc.message}")
            return []

    fields = "--fields=name,title,status,version,update,update_version,auto_update"
    core = ask("core", ["core", "check-update", "--format=json"])
    plugins = _items(ask("plugins", ["plugin", "list", "--format=json", fields]), "plugin")
    themes = _items(ask("themes", ["theme", "list", "--format=json", fields]), "theme")
    core_update = None
    if isinstance(core, list) and core:
        newest = core[0]
        core_update = {"version": newest.get("version"), "type": newest.get("update_type", "")}
    data = {
        "domain": domain, "path": path, "version": version,
        "core_update": core_update,
        "plugins": plugins, "themes": themes,
        "outdated": {
            "core": core_update is not None,
            "plugins": sum(1 for p in plugins if p["update"]),
            "themes": sum(1 for t in themes if t["update"]),
        },
        "cron": cron_get(domain, path),
        "checked_at": int(time.time()),
        "errors": errors,
    }
    STATE.mkdir(mode=0o711, parents=True, exist_ok=True)
    (STATE / f"{_key(domain, path)}.json").write_text(json.dumps(data))
    return data


@action("wp.detect")
def wp_detect(params):
    """WordPress installs of a site (reads files only, fast)."""
    domain = v.domain(params, "domain")
    return {"domain": domain, "installs": [{"path": i["path"], "version": i["version"]} for i in wordpress.detect(domain)]}


@action("wp.status")
def wp_status(params):
    """Core version, plugins, themes and what can be updated (asks WordPress.org)."""
    return status(*_domain_path(params))


# ---------------------------------------------------------------- health + snapshots

def health(url):
    """HTTP status of the front page, asked of this server itself (no DNS)."""
    m = re.match(r"^(https?)://([^/:]+)(/.*)?$", url)
    if not m:
        return 0
    scheme, host = m.group(1), m.group(2)
    port = "443" if scheme == "https" else "80"
    proc = run(["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "45", "-k",
                "--resolve", f"{host}:{port}:127.0.0.1", "-H", "Cache-Control: no-cache",
                f"{url}/?whp_health={secrets.token_hex(4)}"], timeout=60, check=False)
    try:
        return int((proc.stdout or "0").strip()[-3:])
    except ValueError:
        return 0


def _healthy(code):
    return 200 <= code < 500


def _snapshot_dir(domain, path, site):
    base = SNAPSHOTS / _key(domain, path)
    for p in (STATE, SNAPSHOTS):
        p.mkdir(mode=0o711, exist_ok=True, parents=True)
        os.chmod(p, 0o711)
    base.mkdir(mode=0o700, exist_ok=True)
    shutil.chown(base, site["user"], site["user"])
    os.chmod(base, 0o700)
    snap = base / time.strftime("%Y%m%d-%H%M%S")
    snap.mkdir(mode=0o700)
    shutil.chown(snap, site["user"], site["user"])
    return snap


def snapshot(domain, path, log):
    """Database export plus the plugins and themes folders, written by the site user."""
    site, d = wordpress.require_install(domain, path)
    snap = _snapshot_dir(domain, path, site)
    log(f"Snapshot in {snap}")
    wordpress.wp(domain, ["db", "export", str(snap / "database.sql"), "--single-transaction", "--quiet"],
                 path=path, timeout=1800)
    wordpress.as_user(site, ["/usr/bin/tar", "-czf", str(snap / "files.tar.gz"),
                             "wp-content/plugins", "wp-content/themes"], cwd=d, timeout=1800)
    (snap / "versions.json").write_text(json.dumps({"core": wordpress.read_version(d)}))
    # Keep only the newest snapshots of this install.
    old = sorted(p for p in snap.parent.iterdir() if p.is_dir() and not p.is_symlink())[:-KEEP_SNAPSHOTS]
    for p in old:
        shutil.rmtree(p, ignore_errors=True)
    return snap


def rollback(domain, path, snap, updated, log):
    site, d = wordpress.require_install(domain, path)
    log("Rolling back to the snapshot")
    folders = [f"wp-content/plugins/{p['name']}" for p in updated.get("plugins", []) if _SLUG.fullmatch(p["name"])]
    folders += [f"wp-content/themes/{t['name']}" for t in updated.get("themes", []) if _SLUG.fullmatch(t["name"])]
    if folders:
        wordpress.as_user(site, ["/usr/bin/rm", "-rf", "--", *folders], cwd=d, timeout=300)
        wordpress.as_user(site, ["/usr/bin/tar", "-xzf", str(snap / "files.tar.gz"), "--", *folders], cwd=d,
                          timeout=1800, check=False)
    try:
        old_core = json.loads((snap / "versions.json").read_text()).get("core")
    except (OSError, ValueError):
        old_core = None
    if updated.get("core") and old_core:
        wordpress.wp(domain, ["core", "download", f"--version={old_core}", "--skip-content", "--force"],
                     path=path, timeout=900)
    wordpress.wp(domain, ["db", "import", str(snap / "database.sql"), "--quiet"], path=path, timeout=1800)
    log("Rollback finished")


# ---------------------------------------------------------------- update

def _parse_updates(rows):
    out = []
    for r in rows if isinstance(rows, list) else []:
        if r.get("status") == "Updated" or (r.get("new_version") and r.get("new_version") != r.get("old_version")):
            out.append({"name": r.get("name", ""), "old_version": r.get("old_version"), "new_version": r.get("new_version")})
    return out


def update(params, log):
    domain, path = params["domain"], params["path"]
    _, d = wordpress.require_install(domain, path)
    url = _site_url(domain, path)
    before = health(url)
    log(f"{url} answers HTTP {before} before the update")
    snap = snapshot(domain, path, log) if params["snapshot"] else None
    updated = {"core": None, "plugins": [], "themes": [], "translations": False}
    errors = []
    try:
        if params["core"]:
            old = wordpress.read_version(d)
            log("Updating WordPress core")
            # core_minor: only the newest release of the installed branch (security fixes).
            wordpress.wp(domain, ["core", "update", *(["--minor"] if params.get("core_minor") else [])], path=path, timeout=900)
            wordpress.wp(domain, ["core", "update-db"], path=path, timeout=900, check=False)
            new = wordpress.read_version(d)
            if new != old:
                updated["core"] = {"old_version": old, "new_version": new}
                log(f"WordPress {old} -> {new}")
        for kind in ("plugins", "themes"):
            sel = params[kind]
            if not sel:
                continue
            cmd = kind[:-1]
            argv = [cmd, "update", *(["--all"] if sel == "all" else sel), "--format=json"]
            log(f"Updating {kind}: {'all' if sel == 'all' else ', '.join(sel)}")
            try:
                rows = wordpress.wp_json(domain, argv, path=path, timeout=1800)
            except ActionError as exc:
                errors.append(f"{kind}: {exc.message}")
                log(f"{kind}: {exc.message} {exc.details.get('stderr', '')}")
                continue
            updated[kind] = _parse_updates(rows)
            for u in updated[kind]:
                log(f"  {u['name']} {u['old_version']} -> {u['new_version']}")
        if params["translations"]:
            for argv in (["language", "core", "update"], ["language", "plugin", "update", "--all"],
                         ["language", "theme", "update", "--all"]):
                wordpress.wp(domain, argv, path=path, timeout=600, check=False)
            updated["translations"] = True
    finally:
        run([WHP_SITE, "cache", domain, "purge"], timeout=120, check=False)

    after = health(url)
    log(f"{url} answers HTTP {after} after the update")
    rolled_back = False
    if snap and params["rollback"] and _healthy(before) and not _healthy(after):
        rollback(domain, path, snap, updated, log)
        run([WHP_SITE, "cache", domain, "purge"], timeout=120, check=False)
        rolled_back = True
        after = health(url)
        log(f"{url} answers HTTP {after} after the rollback")
    result = {"domain": domain, "path": path, "updated": updated, "errors": errors,
              "health": {"before": before, "after": after}, "rolled_back": rolled_back,
              "snapshot": snap.name if snap else None}
    try:
        result["status"] = status(domain, path)
    except ActionError as exc:
        log(f"Status after the update failed: {exc.message}")
    if rolled_back:
        raise ActionError("command_failed", "The site broke after the update and was rolled back", result)
    return result


def _update_params(params, domain, path):
    job = {
        "domain": domain, "path": path,
        "core": v.boolean(params, "core", required=False, default=True),
        "plugins": _slugs(params, "plugins") if "plugins" in params else "all",
        "themes": _slugs(params, "themes") if "themes" in params else "all",
        "translations": v.boolean(params, "translations", required=False, default=True),
        "snapshot": v.boolean(params, "snapshot", required=False, default=True),
        "rollback": v.boolean(params, "rollback", required=False, default=True),
    }
    if not job["snapshot"]:
        job["rollback"] = False
    return job


@action("wp.update")
def wp_update(params):
    """Job: update core, plugins, themes and translations, snapshot first, roll back when the site breaks."""
    domain, path = _domain_path(params)
    wordpress.require_install(domain, path)
    return start_job("wp.update", update, _update_params(params, domain, path))


@action("wp.update_many")
def wp_update_many(params):
    """Job: update several installs one after the other (bulk update from the security screen).
    installs: [{"domain": ..., "path": ...}], the other params as for wp.update."""
    items = v.list_of(params, "installs", lambda i: _domain_path(i if isinstance(i, dict) else {}), max_items=1000)
    if not items:
        raise ActionError("invalid_params", "installs is empty")
    jobs_ = [_update_params(params, d, p) for d, p in items]

    def work(_, log):
        results = []
        for job in jobs_:
            name = job["domain"] + (f"/{job['path']}" if job["path"] else "")
            log(f"== {name}")
            try:
                results.append({"ok": True, **update(job, log)})
            except ActionError as exc:
                log(f"{name}: {exc.message}")
                results.append({"ok": False, "domain": job["domain"], "path": job["path"],
                                "error": exc.message, **({"result": exc.details} if exc.details else {})})
        return {"results": results, "failed": sum(1 for r in results if not r["ok"])}

    return start_job("wp.update_many", work, {})


@action("wp.snapshots")
def wp_snapshots(params):
    domain, path = _domain_path(params)
    base = SNAPSHOTS / _key(domain, path)
    snaps = []
    if base.is_dir():
        for p in sorted(base.iterdir(), reverse=True):
            if p.is_dir() and not p.is_symlink():
                size = sum(f.stat().st_size for f in p.iterdir() if f.is_file() and not f.is_symlink())
                snaps.append({"id": p.name, "size": size})
    return {"snapshots": snaps}


@action("wp.restore")
def wp_restore(params):
    """Job: put a snapshot back (database plus all plugins and themes)."""
    domain, path = _domain_path(params)
    sid = v.string(params, "snapshot", pattern=r"\d{8}-\d{6}", max_len=15)
    snap = SNAPSHOTS / _key(domain, path) / sid
    if not snap.is_dir() or snap.is_symlink():
        raise ActionError("not_found", "Snapshot not found")

    def work(_, log):
        site, d = wordpress.require_install(domain, path)
        log(f"Restoring snapshot {sid}")
        wordpress.as_user(site, ["/usr/bin/rm", "-rf", "--", "wp-content/plugins", "wp-content/themes"], cwd=d, timeout=600)
        wordpress.as_user(site, ["/usr/bin/tar", "-xzf", str(snap / "files.tar.gz")], cwd=d, timeout=1800)
        wordpress.wp(domain, ["db", "import", str(snap / "database.sql"), "--quiet"], path=path, timeout=1800)
        run([WHP_SITE, "cache", domain, "purge"], timeout=120, check=False)
        return {"domain": domain, "path": path, "snapshot": sid}

    return start_job("wp.restore", work, {})


# ---------------------------------------------------------------- WP-cron

def _units(domain, path):
    name = f"whp-wpcron-{_key(domain, path)}"
    return name, SYSTEMD_DIR / f"{name}.service", SYSTEMD_DIR / f"{name}.timer"


def _write_cron_units(domain, path, interval):
    site, d = wordpress.require_install(domain, path)
    wordpress.ensure_cli()
    name, service, timer = _units(domain, path)
    service.write_text(
        "# Managed by whp-agent (WordPress toolkit). WP-cron for "
        f"{domain}/{path}\n"
        "[Unit]\n"
        f"Description=WP-cron for {domain}{'/' + path if path else ''}\n"
        "After=mariadb.service valkey-server.service\n\n"
        "[Service]\n"
        "Type=oneshot\n"
        f"User={site['user']}\nGroup={site['user']}\n"
        f"Slice={site.get('slice') or 'whp-sites.slice'}\n"
        "UMask=0022\nNice=10\nIOSchedulingClass=idle\n"
        "NoNewPrivileges=yes\nPrivateTmp=yes\nProtectSystem=full\nProtectHome=yes\n"
        "TimeoutStartSec=15min\nMemoryMax=1G\n"
        f"WorkingDirectory={d}\n"
        f"Environment=HOME={site['root']} TMPDIR={site['root']}/tmp WP_CLI_CACHE_DIR={site['root']}/tmp/wp-cli-cache"
        " WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1\n"
        f"ExecStart=/usr/bin/php{site['php']} -d memory_limit=512M {wordpress.WP_CLI} --path={d} --no-color"
        " cron event run --due-now --quiet\n")
    calendar = f"*:0/{interval}" if interval < 60 else "hourly"
    timer.write_text(
        "# Managed by whp-agent (WordPress toolkit)\n"
        "[Unit]\n"
        f"Description=WP-cron for {domain} every {interval} min\n\n"
        "[Timer]\n"
        f"OnCalendar={calendar}\n"
        "RandomizedDelaySec=30s\nAccuracySec=15s\nPersistent=false\n\n"
        "[Install]\nWantedBy=timers.target\n")
    return name


def cron_get(domain, path=""):
    name, service, timer = _units(domain, path)
    interval = None
    if timer.exists():
        m = re.search(r"OnCalendar=\*:0/(\d+)", timer.read_text())
        interval = int(m.group(1)) if m else 60
    mode = "system" if timer.exists() else "wordpress"
    last = {}
    if timer.exists():
        proc = run(["systemctl", "show", f"{name}.service", "-p", "Result", "-p", "ExecMainExitTimestamp",
                    "-p", "ExecMainStatus"], timeout=15, check=False)
        last = dict(line.split("=", 1) for line in (proc.stdout or "").splitlines() if "=" in line)
        active = run(["systemctl", "is-active", f"{name}.timer"], timeout=15, check=False).stdout.strip()
    else:
        active = "inactive"
    return {"mode": mode, "interval": interval, "timer": f"{name}.timer", "timer_active": active == "active",
            "last_run": last.get("ExecMainExitTimestamp") or None,
            "last_result": last.get("Result") or None}


@action("wp.cron_get")
def wp_cron_get(params):
    domain, path = _domain_path(params)
    wordpress.require_install(domain, path)
    return cron_get(domain, path)


@action("wp.cron_set")
def wp_cron_set(params):
    """mode "system": DISABLE_WP_CRON in wp-config and a systemd timer runs due events.
    mode "wordpress": back to WordPress' own visitor-triggered cron."""
    domain, path = _domain_path(params)
    mode = v.choice(params, "mode", ["system", "wordpress"])
    interval = v.choice(params, "interval", CRON_INTERVALS, required=False, default=5)
    wordpress.require_install(domain, path)
    name, service, timer = _units(domain, path)
    if mode == "system":
        wordpress.wp(domain, ["config", "set", "DISABLE_WP_CRON", "true", "--raw", "--type=constant"], path=path, timeout=60)
        _write_cron_units(domain, path, interval)
        run(["systemctl", "daemon-reload"], timeout=30)
        run(["systemctl", "enable", "--now", f"{name}.timer"], timeout=30)
    else:
        run(["systemctl", "disable", "--now", f"{name}.timer"], timeout=30, check=False)
        for f in (service, timer):
            f.unlink(missing_ok=True)
        run(["systemctl", "daemon-reload"], timeout=30)
        wordpress.wp(domain, ["config", "delete", "DISABLE_WP_CRON", "--type=constant"], path=path, timeout=60, check=False)
    return cron_get(domain, path)


@action("wp.cron_run")
def wp_cron_run(params):
    """Run the due WP-cron events now (also when WordPress' own cron is used)."""
    domain, path = _domain_path(params)
    proc = wordpress.wp(domain, ["cron", "event", "run", "--due-now"], path=path, timeout=600, plugins=True, check=False)
    return {"ok": proc.returncode == 0, "output": (proc.stdout or proc.stderr or "")[-2000:]}


def refresh_cron_units(domain):
    """Rewrite the WP-cron units of a site, e.g. after its PHP version changed."""
    changed = []
    for inst in wordpress.detect(domain):
        name, service, timer = _units(domain, inst["path"])
        if timer.exists():
            info = cron_get(domain, inst["path"])
            _write_cron_units(domain, inst["path"], info["interval"] or 5)
            changed.append(name)
    if changed:
        run(["systemctl", "daemon-reload"], timeout=30)
    return changed


# ---------------------------------------------------------------- users, password, login

def _admins(domain, path):
    rows = wordpress.wp_json(domain, ["user", "list", "--role=administrator", "--format=json",
                                      "--fields=ID,user_login,user_email,display_name"], path=path, timeout=60)
    return [{"id": int(r["ID"]), "login": r.get("user_login", ""), "email": r.get("user_email", ""),
             "name": r.get("display_name", "")} for r in rows if str(r.get("ID", "")).isdigit()]


@action("wp.users")
def wp_users(params):
    """The administrators of an install."""
    domain, path = _domain_path(params)
    return {"users": _admins(domain, path)}


def _admin(domain, path, user_id):
    for u in _admins(domain, path):
        if u["id"] == user_id:
            return u
    raise ActionError("not_found", "Administrator not found")


def generate_password(length=20):
    alphabet = string.ascii_letters + string.digits + "-_!@#%^*"
    while True:
        pw = "".join(secrets.choice(alphabet) for _ in range(length))
        if any(c.islower() for c in pw) and any(c.isupper() for c in pw) and any(c.isdigit() for c in pw):
            return pw


@action("wp.set_password")
def wp_set_password(params):
    """Set (or generate) the password of an administrator. The password goes to
    WP-CLI on stdin, never on a command line."""
    domain, path = _domain_path(params)
    user_id = v.integer(params, "user_id", minimum=1, maximum=2**31)
    password = params.get("password")
    generated = password is None
    if generated:
        password = generate_password()
    elif not isinstance(password, str) or not (12 <= len(password) <= 128) or any(c in password for c in "\x00\r\n"):
        raise ActionError("invalid_params", "password must be 12 to 128 characters")
    user = _admin(domain, path, user_id)
    code = ("$p = rtrim((string) fgets(STDIN), \"\\n\"); $u = get_userdata(%d);"
            " if (!$u || strlen($p) < 12) { WP_CLI::error('invalid'); }"
            " wp_set_password($p, $u->ID); WP_CLI::success('ok');") % user_id
    wordpress.wp(domain, ["eval", code], path=path, input_text=password + "\n", timeout=60)
    out = {"user": user}
    if generated:
        out["password"] = password
    return out


def ensure_mu_plugin(domain, path):
    site, d = wordpress.require_install(domain, path)
    content = MU_PLUGIN.read_text()
    target = Path(d) / "wp-content" / "mu-plugins" / "whp-login.php"
    try:
        if target.is_file() and not target.is_symlink() and target.read_text() == content:
            return
    except OSError:
        pass
    # Written by the site user (the folder is theirs), the content comes over stdin.
    wordpress.as_user(site, ["/bin/sh", "-c", "mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/whp-login.php"],
                      cwd=d, input_text=content, timeout=60)


@action("wp.login_link")
def wp_login_link(params):
    """A single use link (valid 2 minutes) that logs an administrator into wp-admin."""
    domain, path = _domain_path(params)
    user_id = params.get("user_id")
    admins = _admins(domain, path)
    if not admins:
        raise ActionError("not_found", "This WordPress has no administrator")
    if user_id is None:
        user = admins[0]
    else:
        v.integer(params, "user_id", minimum=1, maximum=2**31)
        user = next((u for u in admins if u["id"] == user_id), None)
        if user is None:
            raise ActionError("not_found", "Administrator not found")
    ensure_mu_plugin(domain, path)
    token = secrets.token_urlsafe(32)
    key = "whp_sso_" + hashlib.sha256(token.encode()).hexdigest()[:40]
    wordpress.wp(domain, ["transient", "set", key, str(user["id"]), "120"], path=path, timeout=60)
    return {"url": f"{_site_url(domain, path)}/wp-login.php?whp_sso={token}", "user": user, "expires_in": 120}
