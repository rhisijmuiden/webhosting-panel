"""Websites, through the installer's whp-site CLI."""

import json
import re

from .. import validate as v
from ..registry import ActionError, action
from ..run import run

WHP_SITE = "/usr/local/sbin/whp-site"
PHP_VERSIONS = ["8.3", "8.4", "8.5"]
PROFILES = ["php", "wordpress", "laravel"]


def _site(argv, timeout=300):
    proc = run([WHP_SITE, *argv, "--json"], timeout=timeout)
    try:
        return json.loads(proc.stdout or "{}")
    except ValueError:
        raise ActionError("command_failed", "whp-site returned invalid JSON")


@action("site.list")
def site_list(params):
    data = _site(["list"], timeout=60)
    return {"sites": data if isinstance(data, list) else data.get("sites", [])}


@action("site.add")
def site_add(params):
    domain = v.domain(params, "domain")
    argv = ["add", domain, "--php", v.choice(params, "php", PHP_VERSIONS, required=False, default="8.4"),
            "--profile", v.choice(params, "profile", PROFILES, required=False, default="php")]
    for alias in v.list_of(params, "aliases", lambda a: v.domain({"a": a}, "a"), required=False, max_items=50):
        argv += ["--alias", alias]
    if v.boolean(params, "no_alias", required=False, default=False):
        argv.append("--no-alias")
    docroot = v.string(params, "docroot", required=False, max_len=120, pattern=r"[A-Za-z0-9_][A-Za-z0-9_./-]*")
    if docroot:
        if ".." in docroot.split("/"):
            raise ActionError("invalid_params", "docroot may not contain ..")
        argv += ["--docroot", docroot]
    if not v.boolean(params, "cache", required=False, default=True):
        argv.append("--no-cache")
    if not v.boolean(params, "ssl", required=False, default=True):
        argv.append("--no-ssl")
    quota = v.integer(params, "quota_mb", required=False, minimum=0, maximum=10_000_000)
    if quota:
        argv += ["--quota", str(quota)]
    customer = v.integer(params, "customer_id", required=False, minimum=1, maximum=999_999_999)
    if customer:
        argv += ["--owner", f"c{customer}"]
    workers = v.integer(params, "php_workers", required=False, minimum=1, maximum=999)
    if workers:
        argv += ["--workers", str(workers)]
    # A subdomain (test.example.com) of the site example.com: shares its disk quota.
    parent = v.domain(params, "parent", required=False)
    if parent:
        if not domain.endswith("." + parent):
            raise ActionError("invalid_params", f"{domain} is not a subdomain of {parent}")
        argv += ["--parent", parent]
    return _site(argv, timeout=600)


@action("site.show")
def site_show(params):
    return _site(["show", v.domain(params, "domain")], timeout=30)


@action("site.php")
def site_php(params):
    domain = v.domain(params, "domain")
    result = _site(["php", domain, v.choice(params, "php", PHP_VERSIONS)])
    # WP-cron timers start the site's PHP binary directly: point them at the new version.
    try:
        from .wordpress import refresh_cron_units
        refresh_cron_units(domain)
    except ActionError:
        pass
    return result


@action("site.cache")
def site_cache(params):
    state = "on" if v.boolean(params, "enabled") else "off"
    return _site(["cache", v.domain(params, "domain"), state])


@action("site.ssl")
def site_ssl(params):
    return _site(["ssl", v.domain(params, "domain")], timeout=300)


@action("site.quota")
def site_quota(params):
    return _site(["quota", v.domain(params, "domain"), str(v.integer(params, "quota_mb", minimum=0, maximum=10_000_000))])


@action("site.remove")
def site_remove(params):
    argv = ["remove", v.domain(params, "domain")]
    if v.boolean(params, "keep_files", required=False, default=False):
        argv.append("--keep-files")
    return _site(argv, timeout=300)


@action("site.suspend")
def site_suspend(params):
    """Show a 503 page and close SFTP for the site (used when a customer is suspended)."""
    return _site(["suspend", v.domain(params, "domain")], timeout=120)


@action("site.unsuspend")
def site_unsuspend(params):
    return _site(["unsuspend", v.domain(params, "domain")], timeout=120)


@action("site.owner")
def site_owner(params):
    """Run the site in its customer's resource slice (customer_id null: the shared slice)."""
    customer = v.integer(params, "customer_id", required=False, minimum=1, maximum=999_999_999)
    domain = v.domain(params, "domain")
    result = _site(["owner", domain, f"c{customer}" if customer else "-"], timeout=120)
    # WP-cron timers carry the slice in their unit: write them again.
    try:
        from .wordpress import refresh_cron_units
        refresh_cron_units(domain)
    except ActionError:
        pass
    return result


@action("customer.limits")
def customer_limits(params):
    """Resource jail for all sites of one customer. A missing or null value removes that cap."""
    customer = v.integer(params, "customer_id", minimum=1, maximum=999_999_999)
    cpu = v.integer(params, "cpu_percent", required=False, minimum=10, maximum=10_000)
    memory = v.integer(params, "memory_mb", required=False, minimum=64, maximum=10_000_000)
    io = v.integer(params, "io_mbps", required=False, minimum=1, maximum=100_000)
    tasks = v.integer(params, "max_processes", required=False, minimum=16, maximum=100_000)
    return _site(["slice", f"c{customer}",
                  "--cpu", f"{cpu}%" if cpu else "",
                  "--memory", str(memory or ""),
                  "--io", str(io or ""),
                  "--tasks", str(tasks or "")], timeout=120)


# Keys a site may set in its PHP settings, with the form whp-site accepts.
PHP_INI = {
    "memory_limit": r"[1-9][0-9]{0,5}M",
    "max_execution_time": r"[0-9]{1,5}",
    "max_input_time": r"[0-9]{1,5}",
    "max_input_vars": r"[1-9][0-9]{1,6}",
    "post_max_size": r"[1-9][0-9]{0,5}M",
    "upload_max_filesize": r"[1-9][0-9]{0,5}M",
    "display_errors": r"on|off",
}


@action("site.php_settings")
def site_php_settings(params):
    """Replace the site's php.ini values (php_admin_value) and set its number of PHP workers."""
    domain = v.domain(params, "domain")
    settings = params.get("settings") or {}
    if not isinstance(settings, dict) or len(settings) > len(PHP_INI):
        raise ActionError("invalid_params", "settings must be an object")
    argv = ["phpini", domain, "--reset"]
    for key, value in sorted(settings.items()):
        if key not in PHP_INI:
            raise ActionError("invalid_params", f"Unknown PHP setting: {key}")
        if isinstance(value, bool):
            value = "on" if value else "off"
        value = str(value)
        if not re.fullmatch(PHP_INI[key], value):
            raise ActionError("invalid_params", f"Invalid value for {key}")
        argv += ["--set", f"{key}={value}"]
    workers = v.integer(params, "php_workers", required=False, minimum=1, maximum=999)
    if workers:
        argv += ["--workers", str(workers)]
    return _site(argv, timeout=120)
