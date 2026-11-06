"""Websites, through the installer's whp-site CLI."""

import json

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
    return _site(argv, timeout=600)


@action("site.show")
def site_show(params):
    return _site(["show", v.domain(params, "domain")], timeout=30)


@action("site.php")
def site_php(params):
    return _site(["php", v.domain(params, "domain"), v.choice(params, "php", PHP_VERSIONS)])


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
