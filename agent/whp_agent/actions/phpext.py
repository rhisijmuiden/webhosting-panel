"""Extra PHP extensions per PHP version.

Two sources:
- Debian packages (php8.4-<name>, from Debian and the Sury repository). This is
  the preferred way: apt keeps them up to date with the security updates.
- PECL (pecl.php.net) for extensions that have no package. They are compiled
  for one PHP version with that version's php-config; the panel shows them as
  "PECL" so it is clear they are not updated by apt.

After a change the PHP-FPM units of every site on that PHP version are reloaded.
"""

import glob
import os
import re

from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run

PHP_VERSIONS = ["8.3", "8.4", "8.5"]
NAME = r"[a-z][a-z0-9_]{1,40}"
SITES_DB = os.path.join(os.environ.get("WHP_STATE_DIR", "/var/lib/webhosting-panel"), "sites")
APT_ENV = {"DEBIAN_FRONTEND": "noninteractive"}
# Packages of every PHP version that are not extensions (or that the panel needs).
NOT_EXTENSIONS = {"cli", "fpm", "cgi", "common", "dev", "phpdbg", "embed", "dbg", "opcache", "readline", "xsl"}
CORE = {"cli", "fpm", "common", "opcache", "readline"}


def _version(params):
    return v.choice(params, "php", PHP_VERSIONS)


def _name(params):
    return v.string(params, "name", max_len=40, pattern=NAME)


def _loaded(version):
    proc = run([f"/usr/bin/php{version}", "-m"], timeout=30, check=False)
    return sorted({line.strip().lower() for line in proc.stdout.splitlines() if line.strip() and not line.startswith("[")})


def _installed_packages(version):
    proc = run(["dpkg-query", "-W", "-f", "${Package} ${Status}\\n", f"php{version}-*"], timeout=30, check=False)
    out = set()
    for line in proc.stdout.splitlines():
        parts = line.split()
        if len(parts) >= 4 and parts[-1] == "installed":
            out.add(parts[0][len(f"php{version}-"):])
    return out


def _pecl_installed(version):
    """PECL extensions are recorded by the ini file this module writes."""
    out = set()
    for path in glob.glob(f"/etc/php/{version}/mods-available/*.ini"):
        try:
            with open(path) as fh:
                if fh.readline().startswith("; Managed by whp-agent: PECL"):
                    out.add(os.path.basename(path)[:-4])
        except OSError:
            continue
    return out


def _enabled(version, name):
    return os.path.exists(f"/etc/php/{version}/fpm/conf.d/20-{name}.ini") or bool(
        glob.glob(f"/etc/php/{version}/fpm/conf.d/*-{name}.ini"))


def _available(version):
    proc = run(["apt-cache", "search", "--names-only", f"^php{version}-"], timeout=60, check=False)
    rows = []
    for line in proc.stdout.splitlines():
        pkg, _, desc = line.partition(" - ")
        pkg = pkg.strip()
        if not pkg.startswith(f"php{version}-"):
            continue
        name = pkg[len(f"php{version}-"):]
        if name in NOT_EXTENSIONS or not re.fullmatch(r"[a-z0-9_.+-]+", name):
            continue
        rows.append({"name": name, "package": pkg, "description": desc.strip()})
    return rows


def reload_sites(version, log=None):
    """Reload the PHP-FPM unit of every site that runs this PHP version."""
    count = 0
    for path in glob.glob(os.path.join(SITES_DB, "*.env")):
        data = {}
        with open(path) as fh:
            for line in fh:
                key, sep, value = line.rstrip("\n").partition("=")
                if sep:
                    data[key] = value.strip('"')
        if data.get("SITE_PHP") != version or data.get("SITE_SUSPENDED") == "yes":
            continue
        site_id = data.get("SITE_ID", "")
        if not re.fullmatch(r"[a-z0-9_]{1,64}", site_id):
            continue
        run(["systemctl", "reload-or-restart", f"whp-site-{site_id}.service"], timeout=60, check=False)
        count += 1
    if log:
        log(f"Reloaded PHP {version} of {count} website(s)")
    return count


@action("php.extensions.list")
def ext_list(params):
    version = _version(params)
    installed = _installed_packages(version)
    pecl = _pecl_installed(version)
    loaded = set(_loaded(version))
    rows = []
    for row in _available(version):
        row.update({"source": "apt", "installed": row["name"] in installed,
                    "loaded": row["name"] in loaded or row["name"].replace("-", "_") in loaded,
                    "core": row["name"] in CORE})
        rows.append(row)
    for name in sorted(pecl):
        rows.append({"name": name, "package": None, "description": "PECL", "source": "pecl",
                     "installed": True, "loaded": name in loaded, "core": False})
    return {"php": version, "extensions": rows, "loaded": sorted(loaded)}


@action("php.extensions.search_pecl")
def ext_search_pecl(params):
    """Search pecl.php.net. Returns name, latest version and summary."""
    term = v.string(params, "q", max_len=40, pattern=r"[A-Za-z0-9_]{2,40}")
    proc = run(["pecl", "search", term], timeout=60, check=False)
    rows = []
    for line in proc.stdout.splitlines():
        m = re.match(r"^([A-Za-z0-9_]+)\s+(\S+)\s+(?:\S+\s+)?(.*)$", line.strip())
        if not m or m.group(1) in ("Package", "Matched") or line.startswith("="):
            continue
        rows.append({"name": m.group(1).lower(), "version": m.group(2), "summary": m.group(3)[:200]})
    return {"results": rows[:50]}


def _install_apt(params, log):
    version, name = params["php"], params["name"]
    pkg = f"php{version}-{name}"
    log(f"apt-get install {pkg}")
    proc = run(["apt-get", "install", "-y", "--no-install-recommends", pkg], timeout=1200, env=APT_ENV, check=False)
    log(proc.stdout[-4000:])
    if proc.returncode != 0:
        raise ActionError("command_failed", f"apt-get install {pkg} failed", {"stderr": proc.stderr[-2000:]})
    reload_sites(version, log)
    return {"php": version, "name": name, "source": "apt", "installed": True}


def _install_pecl(params, log):
    version, name = params["php"], params["name"]
    log(f"Build tools and php{version}-dev")
    proc = run(["apt-get", "install", "-y", "--no-install-recommends", f"php{version}-dev", "php-pear",
                "build-essential", "pkg-config"], timeout=1200, env=APT_ENV, check=False)
    if proc.returncode != 0:
        raise ActionError("command_failed", "Could not install the build tools", {"stderr": proc.stderr[-2000:]})
    # Build for this PHP version only; -f also rebuilds when another version has it.
    argv = ["pecl", "-d", f"php_suffix={version}", "install", "-f", name]
    log(" ".join(argv))
    proc = run(argv, timeout=1800, check=False, input_text="\n" * 20)
    log(proc.stdout[-6000:])
    if proc.returncode != 0 or "install ok" not in proc.stdout:
        raise ActionError("command_failed", f"pecl install {name} failed", {"stderr": proc.stderr[-2000:]})
    # Forget it in the PECL registry, so the next PHP version can build it too.
    run(["pecl", "uninstall", "-r", name], timeout=120, check=False)
    ext_dir = run([f"/usr/bin/php-config{version}", "--extension-dir"], timeout=30).stdout.strip()
    if not os.path.exists(os.path.join(ext_dir, f"{name}.so")):
        raise ActionError("command_failed", f"{name}.so was not built in {ext_dir}")
    zend = name in ("xdebug", "ioncube_loader")
    with open(f"/etc/php/{version}/mods-available/{name}.ini", "w") as fh:
        fh.write(f"; Managed by whp-agent: PECL {name} for PHP {version}\n"
                 f"; priority=30\n{'zend_extension' if zend else 'extension'}={name}.so\n")
    run(["phpenmod", "-v", version, name], timeout=60)
    test = run([f"/usr/bin/php{version}", "-m"], timeout=30, check=False)
    if name not in test.stdout.lower():
        run(["phpdismod", "-v", version, name], timeout=60, check=False)
        raise ActionError("command_failed", f"PHP {version} does not load {name}: it was disabled again",
                          {"stderr": test.stderr[-2000:]})
    reload_sites(version, log)
    return {"php": version, "name": name, "source": "pecl", "installed": True}


@action("php.extensions.install")
def ext_install(params):
    version, name = _version(params), _name(params)
    source = v.choice(params, "source", ["apt", "pecl"], required=False, default="apt")
    if source == "apt" and name not in {r["name"] for r in _available(version)}:
        raise ActionError("not_found", f"There is no package php{version}-{name}")
    return start_job("php.extensions.install", _install_pecl if source == "pecl" else _install_apt,
                     {"php": version, "name": name})


def _remove(params, log):
    version, name = params["php"], params["name"]
    if name in _pecl_installed(version):
        run(["phpdismod", "-v", version, name], timeout=60, check=False)
        os.remove(f"/etc/php/{version}/mods-available/{name}.ini")
        ext_dir = run([f"/usr/bin/php-config{version}", "--extension-dir"], timeout=30, check=False).stdout.strip()
        if ext_dir:
            try:
                os.remove(os.path.join(ext_dir, f"{name}.so"))
            except FileNotFoundError:
                pass
    else:
        proc = run(["apt-get", "remove", "-y", f"php{version}-{name}"], timeout=600, env=APT_ENV, check=False)
        log(proc.stdout[-4000:])
        if proc.returncode != 0:
            raise ActionError("command_failed", f"apt-get remove php{version}-{name} failed", {"stderr": proc.stderr[-2000:]})
    reload_sites(version, log)
    return {"php": version, "name": name, "installed": False}


@action("php.extensions.remove")
def ext_remove(params):
    version, name = _version(params), _name(params)
    if name in CORE or name in ("mysql", "xml", "mbstring", "curl", "intl", "zip", "gd"):
        raise ActionError("invalid_params", f"{name} is part of the base installation and cannot be removed")
    return start_job("php.extensions.remove", _remove, {"php": version, "name": name})


@action("php.extensions.toggle")
def ext_toggle(params):
    """Switch an installed extension on or off for one PHP version (phpenmod/phpdismod)."""
    version, name = _version(params), _name(params)
    enabled = v.boolean(params, "enabled")
    if not os.path.exists(f"/etc/php/{version}/mods-available/{name}.ini"):
        raise ActionError("not_found", f"{name} is not installed for PHP {version}")
    if not enabled and name in CORE:
        raise ActionError("invalid_params", f"{name} cannot be switched off")
    run(["phpenmod" if enabled else "phpdismod", "-v", version, name], timeout=60)
    reload_sites(version)
    return {"php": version, "name": name, "enabled": _enabled(version, name)}
