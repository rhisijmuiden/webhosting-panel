"""Valkey object cache per website (panel: Speed & caching → Object cache).

Every website that switches the object cache on gets its own Valkey server,
never a database on the shared Valkey of the panel:

- it runs as the site's own Linux user in the subscription's resource jail
  (slice), so it counts for the package memory and no other site can read it,
- it listens on a unix socket only (no TCP port, PrivateNetwork=yes):
  /run/whp-objcache/<site user>/valkey.sock, folder 0700 for the site user,
- maxmemory comes from the package (allkeys-lru: the oldest cache entries go
  first when it is full), the systemd MemoryMax sits a little above it,
- nothing is written to disk (a cache, not a database); a restart starts empty,
- admin commands (CONFIG, SHUTDOWN, DEBUG, MODULE, ...) are blocked for PHP.

WordPress installs of the site are configured with the Redis Object Cache
plugin (wordpress.org/plugins/redis-cache, works with Valkey): wp-config gets
the WP_REDIS_* constants, the plugin is installed and its object-cache.php
drop-in enabled. WooCommerce needs nothing extra; it is reported so the panel
can show it.

Files (all written by the agent, the panel is the source of truth):

    /etc/systemd/system/whp-objcache-<user>.service
    /etc/webhosting-panel/objcache/<user>.conf    Valkey config (root:<user> 0640)
    /var/lib/webhosting-panel/objcache/<domain>.json  state (memory, installs)
"""

import json
import os
import re
import socket
import time

from .. import paths, wordpress
from .. import validate as v
from ..registry import ActionError, action
from ..run import run

SYSTEMD_DIR = os.environ.get("WHP_SYSTEMD_DIR", "/etc/systemd/system")
ETC_DIR = os.path.join(paths.CONFIG_DIR, "objcache")
STATE_DIR = os.path.join(paths.STATE_DIR, "objcache")
RUN_DIR = os.environ.get("WHP_OBJCACHE_RUN_DIR", "/run/whp-objcache")
VALKEY_SERVER = os.environ.get("WHP_VALKEY_SERVER", "/usr/bin/valkey-server")
MEMORY_MIN, MEMORY_MAX = 16, 65536
PLUGIN = "redis-cache"
# Constants this module writes to wp-config.php (and removes again).
WP_CONSTANTS = ("WP_REDIS_SCHEME", "WP_REDIS_PATH", "WP_REDIS_PREFIX", "WP_REDIS_DATABASE",
                "WP_REDIS_TIMEOUT", "WP_REDIS_READ_TIMEOUT", "WP_REDIS_SELECTIVE_FLUSH")

_USER = re.compile(r"s_[a-z0-9_]{1,30}")


# ----------------------------------------------------------------- names

def unit_name(user):
    return f"whp-objcache-{user}.service"


def socket_path(user):
    return os.path.join(RUN_DIR, user, "valkey.sock")


def conf_path(user):
    return os.path.join(ETC_DIR, f"{user}.conf")


def _state_file(domain):
    return os.path.join(STATE_DIR, f"{domain}.json")


def read_state(domain):
    try:
        with open(_state_file(domain)) as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return None


def write_state(st):
    os.makedirs(STATE_DIR, mode=0o750, exist_ok=True)
    tmp = _state_file(st["domain"]) + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(st, fh, indent=1)
    os.replace(tmp, _state_file(st["domain"]))


def all_states():
    try:
        names = sorted(os.listdir(STATE_DIR))
    except OSError:
        return []
    out = []
    for n in names:
        if n.endswith(".json"):
            st = read_state(n[:-5])
            if st:
                out.append(st)
    return out


def _site_for(domain):
    site = wordpress.site_info(domain)
    if not _USER.fullmatch(site.get("user") or ""):
        raise ActionError("internal", f"Website {domain} has no valid system user")
    return site


def _write(path, text, mode, group=None):
    os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
    tmp = f"{path}.whp-new"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, mode)
    with os.fdopen(fd, "w") as fh:
        fh.write(text)
    os.chmod(tmp, mode)
    if group is not None:
        try:
            import grp

            os.chown(tmp, 0, grp.getgrnam(group).gr_gid)
        except (KeyError, PermissionError):
            pass
    os.replace(tmp, path)


# ----------------------------------------------------------------- files

def memory_cap(memory_mb):
    """systemd MemoryMax for a maxmemory: Valkey needs some room for buffers and fragmentation."""
    return memory_mb + max(32, memory_mb // 4)


def render_conf(site, memory_mb):
    user = site["user"]
    return (
        f"# Managed by whp-agent: object cache of {site['domain']}. Changes are overwritten.\n"
        "port 0\n"
        f"unixsocket {socket_path(user)}\n"
        "unixsocketperm 600\n"
        f"dir {os.path.join(RUN_DIR, user)}\n"
        "supervised systemd\n"
        "daemonize no\n"
        'logfile ""\n'
        "loglevel warning\n"
        "databases 16\n"
        "maxclients 512\n"
        f"maxmemory {int(memory_mb)}mb\n"
        "maxmemory-policy allkeys-lru\n"
        # A cache: nothing on disk, a restart starts empty.
        'save ""\n'
        "appendonly no\n"
        "lazyfree-lazy-eviction yes\n"
        "lazyfree-lazy-expire yes\n"
        "lazyfree-lazy-user-flush yes\n"
        "enable-debug-command no\n"
        "enable-module-command no\n"
        "enable-protected-configs no\n"
        # PHP may read and write keys and flush, never reconfigure or stop the server.
        "user default on nopass ~* &* +@all -@admin\n"
    )


def render_unit(site, memory_mb):
    user = site["user"]
    return (
        "# Managed by whp-agent (object cache). Changes are overwritten.\n"
        "[Unit]\n"
        f"Description=Valkey object cache for {site['domain']}\n"
        "After=network.target\n"
        "StartLimitIntervalSec=300\nStartLimitBurst=10\n\n"
        "[Service]\n"
        "Type=notify\n"
        f"User={user}\nGroup={user}\n"
        f"Slice={site.get('slice') or 'whp-sites.slice'}\n"
        f"ExecStart={VALKEY_SERVER} {conf_path(user)}\n"
        f"RuntimeDirectory=whp-objcache/{user}\n"
        "RuntimeDirectoryMode=0700\n"
        "Restart=on-failure\nRestartSec=2s\n"
        "TimeoutStartSec=30s\nTimeoutStopSec=15s\n"
        f"MemoryMax={memory_cap(int(memory_mb))}M\nMemorySwapMax=0\n"
        "TasksMax=64\nLimitNOFILE=1024\n"
        "Nice=5\n"
        f"SyslogIdentifier=whp-objcache-{user}\n"
        "UMask=0077\n"
        "NoNewPrivileges=yes\n"
        "PrivateTmp=yes\nPrivateDevices=yes\nPrivateNetwork=yes\n"
        "ProtectSystem=strict\nProtectHome=yes\n"
        "InaccessiblePaths=-/var/www\n"
        "ProtectKernelTunables=yes\nProtectKernelModules=yes\nProtectKernelLogs=yes\n"
        "ProtectControlGroups=yes\nProtectClock=yes\nProtectHostname=yes\nProtectProc=invisible\n"
        "RestrictSUIDSGID=yes\nRestrictRealtime=yes\nRestrictNamespaces=yes\nLockPersonality=yes\n"
        "MemoryDenyWriteExecute=yes\n"
        "RestrictAddressFamilies=AF_UNIX\n"
        "\n[Install]\nWantedBy=multi-user.target\n"
    )


# ----------------------------------------------------------------- talking to Valkey

def _encode(args):
    out = [f"*{len(args)}\r\n".encode()]
    for a in args:
        b = a.encode() if isinstance(a, str) else bytes(a)
        out.append(f"${len(b)}\r\n".encode() + b + b"\r\n")
    return b"".join(out)


class _Reader:
    def __init__(self, sock):
        self.sock = sock
        self.buf = b""

    def _fill(self):
        chunk = self.sock.recv(65536)
        if not chunk:
            raise ActionError("command_failed", "The object cache closed the connection")
        self.buf += chunk

    def line(self):
        while b"\r\n" not in self.buf:
            self._fill()
        ln, _, self.buf = self.buf.partition(b"\r\n")
        return ln

    def exact(self, n):
        while len(self.buf) < n + 2:
            self._fill()
        data, self.buf = self.buf[:n], self.buf[n + 2:]
        return data

    def reply(self):
        ln = self.line()
        kind, rest = ln[:1], ln[1:].decode(errors="replace")
        if kind == b"+":
            return rest
        if kind == b"-":
            raise ActionError("command_failed", f"Object cache: {rest}")
        if kind == b":":
            return int(rest)
        if kind == b"$":
            n = int(rest)
            return None if n < 0 else self.exact(n).decode(errors="replace")
        if kind == b"*":
            n = int(rest)
            return None if n < 0 else [self.reply() for _ in range(n)]
        raise ActionError("command_failed", "Object cache sent an unknown reply")


def command(user, *args, timeout=5.0):
    """One command on the site's Valkey (root may open the 0700 socket folder)."""
    sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    sock.settimeout(timeout)
    try:
        sock.connect(socket_path(user))
        sock.sendall(_encode(args))
        return _Reader(sock).reply()
    except (OSError, ValueError) as e:
        raise ActionError("command_failed", f"Object cache is not reachable: {e}") from e
    finally:
        sock.close()


def parse_info(text):
    info = {}
    for ln in (text or "").splitlines():
        if ln and not ln.startswith("#") and ":" in ln:
            k, _, val = ln.partition(":")
            info[k] = val.strip()
    return info


def _int(value):
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def stats(user):
    """Memory, keys and hit ratio, or None when the server does not answer."""
    try:
        info = parse_info(command(user, "INFO"))
    except ActionError:
        return None
    keys = 0
    for k, val in info.items():
        if re.fullmatch(r"db\d+", k):
            m = re.search(r"keys=(\d+)", val)
            keys += int(m.group(1)) if m else 0
    hits, misses = _int(info.get("keyspace_hits")) or 0, _int(info.get("keyspace_misses")) or 0
    return {
        "version": info.get("valkey_version") or info.get("redis_version"),
        "uptime_seconds": _int(info.get("uptime_in_seconds")),
        "used_memory_bytes": _int(info.get("used_memory")),
        "maxmemory_bytes": _int(info.get("maxmemory")),
        "keys": keys,
        "hits": hits, "misses": misses,
        "hit_ratio": round(hits / (hits + misses) * 100, 1) if hits + misses else None,
        "evicted_keys": _int(info.get("evicted_keys")) or 0,
        "clients": _int(info.get("connected_clients")),
    }


def unit_state(user):
    proc = run(["systemctl", "show", unit_name(user), "-p", "ActiveState,SubState,UnitFileState,MemoryCurrent"],
               timeout=20, check=False)
    data = dict(ln.partition("=")[::2] for ln in (proc.stdout or "").splitlines() if "=" in ln)
    active = data.get("ActiveState", "unknown")
    state = {"active": "running", "activating": "starting", "deactivating": "stopping", "failed": "failed"}.get(active, "stopped")
    mem = data.get("MemoryCurrent", "")
    return {"state": state, "autostart": data.get("UnitFileState") == "enabled",
            "memory_bytes": int(mem) if mem.isdigit() and mem != "18446744073709551615" else None}


def _wait_ready(user, seconds=10):
    for _ in range(seconds * 4):
        try:
            if command(user, "PING", timeout=1.0) == "PONG":
                return True
        except ActionError:
            pass
        time.sleep(0.25)
    return False


# ----------------------------------------------------------------- WordPress

def _has_plugin(site, install, slug):
    d = wordpress.install_dir(site, install["path"])
    return os.path.isdir(os.path.join(d, "wp-content", "plugins", slug))


def dropin_state(site, install):
    """ours | foreign | none: who owns wp-content/object-cache.php."""
    f = os.path.join(wordpress.install_dir(site, install["path"]), "wp-content", "object-cache.php")
    try:
        with open(f, errors="replace") as fh:
            head = fh.read(4000)
    except (FileNotFoundError, NotADirectoryError):
        return "none"
    except OSError:
        return "foreign"
    return "ours" if "Redis Object Cache" in head or "redis-cache" in head else "foreign"


def wp_constants(user, path, index, count):
    prefix = re.sub(r"[^a-z0-9]+", "_", (path or "root").lower()).strip("_") or "root"
    const = {
        "WP_REDIS_SCHEME": "unix",
        "WP_REDIS_PATH": socket_path(user),
        "WP_REDIS_PREFIX": f"{prefix}:",
        # One database per install, so a flush from one WordPress leaves the others alone.
        "WP_REDIS_DATABASE": str(min(index, 15)),
        "WP_REDIS_TIMEOUT": "1",
        "WP_REDIS_READ_TIMEOUT": "1",
    }
    if count > 16:
        # More installs than databases: some share one, a flush then only removes its own prefix.
        const["WP_REDIS_SELECTIVE_FLUSH"] = "true"
    return const


def configure_wordpress(site, domain):
    """Set the constants, install and activate the plugin, enable its drop-in. One result per install."""
    installs = wordpress.detect(domain)
    out = []
    for i, inst in enumerate(installs):
        row = {"path": inst["path"], "woocommerce": _has_plugin(site, inst, "woocommerce")}
        try:
            if dropin_state(site, inst) == "foreign":
                raise ActionError("conflict", "Another object cache drop-in (wp-content/object-cache.php) is in use: "
                                              "switch off the object cache of that plugin first")
            for name, value in wp_constants(site["user"], inst["path"], i, len(installs)).items():
                raw = value in ("true", "false") or value.isdigit()
                wordpress.wp(domain, ["config", "set", name, value, "--type=constant", *(["--raw"] if raw else [])],
                             path=inst["path"], timeout=120)
            if not _has_plugin(site, inst, PLUGIN):
                wordpress.wp(domain, ["plugin", "install", PLUGIN, "--activate"], path=inst["path"], timeout=300)
            else:
                wordpress.wp(domain, ["plugin", "activate", PLUGIN], path=inst["path"], timeout=120, check=False)
            if dropin_state(site, inst) != "ours":
                wordpress.wp(domain, ["redis", "enable"], path=inst["path"], timeout=120, plugins=True)
            row["configured"] = True
        except ActionError as e:
            row["configured"] = False
            row["error"] = e.message
        out.append(row)
    return out


def unconfigure_wordpress(site, domain):
    """Remove our drop-in and constants first, so WordPress never waits for a cache that is gone."""
    out = []
    for inst in wordpress.detect(domain):
        row = {"path": inst["path"]}
        try:
            if dropin_state(site, inst) == "ours":
                proc = wordpress.wp(domain, ["redis", "disable"], path=inst["path"], timeout=120, check=False, plugins=True)
                if proc.returncode != 0:
                    f = os.path.join(wordpress.install_dir(site, inst["path"]), "wp-content", "object-cache.php")
                    wordpress.as_user(site, ["/usr/bin/rm", "-f", f], site["root"], check=False)
            wordpress.wp(domain, ["plugin", "deactivate", PLUGIN], path=inst["path"], timeout=120, check=False)
            for name in WP_CONSTANTS:
                wordpress.wp(domain, ["config", "delete", name, "--type=constant"], path=inst["path"], timeout=60, check=False)
            row["configured"] = False
        except ActionError as e:
            row["error"] = e.message
        out.append(row)
    return out


# ----------------------------------------------------------------- actions

def _status(domain, st=None):
    st = st or read_state(domain)
    if not st:
        return {"domain": domain, "enabled": False, "state": "off"}
    user = st["user"]
    unit = unit_state(user)
    live = stats(user) if unit["state"] == "running" else None
    return {
        "domain": domain, "enabled": bool(st.get("enabled")), "memory_mb": st.get("memory_mb"),
        "socket": socket_path(user), "unit": unit_name(user), **unit, "stats": live,
        "wordpress": st.get("wordpress") or [], "suspended": bool(st.get("suspended")),
    }


@action("objcache.apply")
def objcache_apply(params):
    """{domain, enabled, memory_mb, wordpress: configure WordPress installs (default true)}.
    Writes config and unit, (re)starts or stops the server and (un)configures WordPress."""
    domain = v.domain(params, "domain")
    enabled = v.boolean(params, "enabled")
    memory_mb = v.integer(params, "memory_mb", required=enabled, default=64, minimum=MEMORY_MIN, maximum=MEMORY_MAX)
    want_wp = v.boolean(params, "wordpress", required=False, default=True)
    site = _site_for(domain)
    user = site["user"]
    old = read_state(domain) or {}
    unit = unit_name(user)

    if not enabled:
        wp_rows = unconfigure_wordpress(site, domain) if old.get("wp_managed") else []
        run(["systemctl", "disable", "--now", unit], timeout=60, check=False)
        st = {**old, "domain": domain, "user": user, "enabled": False, "wordpress": wp_rows, "wp_managed": False,
              "updated_at": int(time.time())}
        write_state(st)
        return _status(domain, st)

    os.makedirs(ETC_DIR, mode=0o755, exist_ok=True)
    _write(conf_path(user), render_conf(site, memory_mb), 0o640, group=user)
    _write(os.path.join(SYSTEMD_DIR, unit), render_unit(site, memory_mb), 0o644)
    run(["systemctl", "daemon-reload"], timeout=60)
    st = {"domain": domain, "user": user, "enabled": True, "memory_mb": memory_mb, "suspended": site.get("suspended", False),
          "wordpress": old.get("wordpress") or [], "wp_managed": bool(old.get("wp_managed")), "updated_at": int(time.time())}
    write_state(st)
    if site.get("suspended"):
        run(["systemctl", "disable", "--now", unit], timeout=60, check=False)
        return _status(domain, st)

    run(["systemctl", "enable", unit], timeout=60, check=False)
    run(["systemctl", "reset-failed", unit], timeout=20, check=False)
    run(["systemctl", "restart", unit], timeout=60, check=False)
    if not _wait_ready(user):
        raise ActionError("command_failed", "The object cache did not start, see journalctl -u " + unit)

    if want_wp:
        st["wordpress"] = configure_wordpress(site, domain)
        st["wp_managed"] = True
    elif old.get("wp_managed"):
        st["wordpress"] = unconfigure_wordpress(site, domain)
        st["wp_managed"] = False
    write_state(st)
    return _status(domain, st)


@action("objcache.status")
def objcache_status(params):
    return _status(v.domain(params, "domain"))


@action("objcache.list")
def objcache_list(params):
    """Status of every site with an object cache (the admin overview)."""
    domains = params.get("domains")
    if domains is not None and (not isinstance(domains, list) or len(domains) > 2000):
        raise ActionError("invalid_params", "domains must be a list")
    out = {}
    for st in all_states():
        if domains is not None and st["domain"] not in domains:
            continue
        out[st["domain"]] = _status(st["domain"], st)
    return {"sites": out}


@action("objcache.flush")
def objcache_flush(params):
    """Empty the whole cache of a site (every WordPress install on it)."""
    domain = v.domain(params, "domain")
    st = read_state(domain)
    if not st or not st.get("enabled"):
        raise ActionError("conflict", "The object cache of this website is off")
    before = (stats(st["user"]) or {}).get("keys")
    command(st["user"], "FLUSHALL")
    return {"domain": domain, "flushed": True, "keys_removed": before, "flushed_at": int(time.time())}


@action("objcache.suspend")
def objcache_suspend(params):
    """Stop (suspended=true) or start again the object cache of a website."""
    domain = v.domain(params, "domain")
    suspended = v.boolean(params, "suspended")
    st = read_state(domain)
    if not st:
        return {"domain": domain, "suspended": suspended, "changed": False}
    unit = unit_name(st["user"])
    if suspended:
        run(["systemctl", "disable", "--now", unit], timeout=60, check=False)
    elif st.get("enabled"):
        run(["systemctl", "enable", "--now", unit], timeout=60, check=False)
    st["suspended"] = suspended
    write_state(st)
    return {"domain": domain, "suspended": suspended, "changed": True}


@action("objcache.remove")
def objcache_remove(params):
    """Before a website is removed: undo the WordPress settings, stop and delete everything."""
    domain = v.domain(params, "domain")
    st = read_state(domain)
    if not st:
        return {"domain": domain, "removed": False}
    user = st["user"]
    if st.get("wp_managed"):
        try:
            unconfigure_wordpress(_site_for(domain), domain)
        except ActionError:
            pass
    unit = unit_name(user)
    run(["systemctl", "disable", "--now", unit], timeout=60, check=False)
    for f in (os.path.join(SYSTEMD_DIR, unit), conf_path(user), _state_file(domain)):
        if os.path.exists(f):
            os.unlink(f)
    run(["systemctl", "daemon-reload"], timeout=60, check=False)
    run(["systemctl", "reset-failed", unit], timeout=20, check=False)
    return {"domain": domain, "removed": True}
