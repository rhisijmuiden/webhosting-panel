"""Domain aliases: extra domains that serve (or redirect to) an existing site.

Through `whp-site alias ...`: every alias has its own Nginx server block and its
own Let's Encrypt certificate (cert name = the alias), so an alias whose DNS
does not point here yet never blocks the main site's certificate.
"""

from .. import validate as v
from ..registry import ActionError, action
from .sites import _site

MODES = ["serve", "redirect"]


def _pair(params):
    site = v.domain(params, "domain")
    alias = v.domain(params, "alias")
    if alias in (site, "www." + site):
        raise ActionError("invalid_params", "An alias must differ from the site itself")
    return site, alias


@action("site.alias_list")
def alias_list(params):
    return _site(["alias", "list", v.domain(params, "domain")], timeout=30)


@action("site.alias_add")
def alias_add(params):
    """{domain, alias, mode: serve|redirect, www: bool, ssl: bool}"""
    site, alias = _pair(params)
    argv = ["alias", "add", site, alias]
    if v.choice(params, "mode", MODES, required=False, default="serve") == "redirect":
        argv.append("--redirect")
    if not v.boolean(params, "www", required=False, default=True):
        argv.append("--no-www")
    if not v.boolean(params, "ssl", required=False, default=True):
        argv.append("--no-ssl")
    return _site(argv, timeout=600)


@action("site.alias_mode")
def alias_mode(params):
    site, alias = _pair(params)
    return _site(["alias", "mode", site, alias, v.choice(params, "mode", MODES)], timeout=120)


@action("site.alias_ssl")
def alias_ssl(params):
    site, alias = _pair(params)
    return _site(["alias", "ssl", site, alias], timeout=300)


@action("site.alias_remove")
def alias_remove(params):
    """keep_cert: keep the Let's Encrypt certificate (the alias becomes a website of its own)."""
    site, alias = _pair(params)
    argv = ["alias", "remove", site, alias]
    if v.boolean(params, "keep_cert", required=False, default=False):
        argv.append("--keep-cert")
    return _site(argv, timeout=120)
