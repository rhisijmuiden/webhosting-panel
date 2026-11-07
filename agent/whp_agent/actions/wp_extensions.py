"""WordPress plugins and themes: list, activate/deactivate, delete, install from
WordPress.org and switch WordPress' own auto-updates per plugin or theme.

Everything runs through wordpress.wp(), so WP-CLI starts as the site user.
Activating a plugin or theme is followed by a health check: when the front page
answered fine before and gives a server error afterwards, the change is undone
straight away (plugin deactivated, previous theme back).

Actions:
  wp.ext_list     {domain, path}                       -> {plugins, themes}
  wp.ext_apply    {domain, path, kind, op, slugs[]}    -> {done, errors, rolled_back, health, plugins, themes}
  wp.ext_search   {domain, path, kind, query, page}    -> {results, total, pages}
  wp.ext_install  {domain, path, kind, slug, activate} -> like wp.ext_apply
"""

import json
import re

from .. import validate as v
from .. import wordpress
from ..registry import ActionError, action
from ..run import run
from . import wordpress as toolkit

KINDS = ("plugin", "theme")
OPS = {
    "plugin": ("activate", "deactivate", "delete", "auto_update_on", "auto_update_off"),
    "theme": ("activate", "delete", "auto_update_on", "auto_update_off"),
}
FIELDS = "--fields=name,title,status,version,update,update_version,auto_update"
_SLUG = re.compile(r"^[a-z0-9][a-z0-9._-]{0,99}$")
_TAGS = re.compile(r"<[^>]+>")
_SHOWING = re.compile(r"Showing \d+ of (\d+)")
PER_PAGE = 24


def _target(params):
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    wordpress.require_install(domain, path)
    return domain, path


def _slug(value, key="slug"):
    if not isinstance(value, str) or not _SLUG.fullmatch(value):
        raise ActionError("invalid_params", f"{key} must be a WordPress.org slug")
    return value


def lists(domain, path):
    """Installed plugins and themes. Uses the update information WordPress already has,
    so it does not ask WordPress.org again (wp.status does that)."""
    out = {}
    for kind in KINDS:
        rows = wordpress.wp_json(domain, [kind, "list", "--format=json", FIELDS, "--skip-update-check"],
                                 path=path, timeout=120)
        out[f"{kind}s"] = toolkit._items(rows, kind)
    return out


def _purge(domain):
    run([toolkit.WHP_SITE, "cache", domain, "purge"], timeout=120, check=False)


@action("wp.ext_list")
def wp_ext_list(params):
    domain, path = _target(params)
    return {"domain": domain, "path": path, **lists(domain, path)}


def apply(domain, path, kind, op, slugs, current=None):
    """Run one operation on one or more plugins/themes. Returns what happened per slug."""
    current = current if current is not None else lists(domain, path)
    items = {i["name"]: i for i in current[f"{kind}s"]}
    done, errors = [], []
    unknown = [s for s in slugs if s not in items]
    for s in unknown:
        errors.append({"slug": s, "error": f"{kind} {s} is not installed"})
    slugs = [s for s in slugs if s in items]

    if op == "delete":
        keep = []
        for s in slugs:
            st = items[s]["status"]
            if st in ("active", "active-network"):
                errors.append({"slug": s, "error": f"{s} is active: deactivate it first" if kind == "plugin"
                               else f"{s} is the active theme: activate another theme first"})
            elif kind == "theme" and st == "parent":
                errors.append({"slug": s, "error": f"{s} is the parent of the active theme"})
            else:
                keep.append(s)
        slugs = keep
    if kind == "theme" and op == "activate" and len(slugs) > 1:
        raise ActionError("invalid_params", "Only one theme can be active")

    if not slugs:
        return {"done": done, "errors": errors, "rolled_back": False, "health": None, **current}

    url = toolkit._site_url(domain, path) if op == "activate" else None
    before = toolkit.health(url) if url else None
    previous_theme = next((t["name"] for t in current["themes"] if t["status"] == "active"), None)

    argv = {
        "activate": [kind, "activate"],
        "deactivate": [kind, "deactivate"],
        "delete": [kind, "delete"],
        "auto_update_on": [kind, "auto-updates", "enable"],
        "auto_update_off": [kind, "auto-updates", "disable"],
    }[op]
    # One by one: a plugin that fails to activate must not stop the others.
    for s in slugs:
        proc = wordpress.wp(domain, [*argv, s], path=path, timeout=300, check=False)
        if proc.returncode == 0:
            done.append(s)
        else:
            errors.append({"slug": s, "error": wordpress.explain(proc)})

    rolled_back = False
    health = None
    if op in ("activate", "deactivate", "delete") and done:
        _purge(domain)
    if url and done:
        after = toolkit.health(url)
        health = {"before": before, "after": after}
        if toolkit._healthy(before) and not toolkit._healthy(after):
            # The site broke: undo the activation.
            if kind == "plugin":
                wordpress.wp(domain, ["plugin", "deactivate", *done], path=path, timeout=300, check=False)
            elif previous_theme:
                wordpress.wp(domain, ["theme", "activate", previous_theme], path=path, timeout=300, check=False)
            _purge(domain)
            rolled_back = True
            health["after_rollback"] = toolkit.health(url)
            for s in done:
                errors.append({"slug": s, "error": f"The website answered HTTP {after} after activating {s}, so it was undone"})
            done = []
    return {"done": done, "errors": errors, "rolled_back": rolled_back, "health": health, **lists(domain, path)}


@action("wp.ext_apply")
def wp_ext_apply(params):
    domain, path = _target(params)
    kind = v.choice(params, "kind", KINDS)
    op = v.choice(params, "op", OPS[kind])
    slugs = v.list_of(params, "slugs", lambda s: _slug(s, "slugs"), max_items=200)
    if not slugs:
        raise ActionError("invalid_params", "slugs must not be empty")
    return {"domain": domain, "path": path, "kind": kind, "op": op, **apply(domain, path, kind, op, list(dict.fromkeys(slugs)))}


def _author(value):
    if isinstance(value, dict):
        value = value.get("display_name") or value.get("user_nicename") or ""
    return _TAGS.sub("", str(value or "")).strip()[:120]


def _result(kind, r):
    icons = r.get("icons") if isinstance(r.get("icons"), dict) else {}
    return {
        "slug": r.get("slug", ""),
        "name": _TAGS.sub("", str(r.get("name") or r.get("slug") or ""))[:200],
        "version": str(r.get("version") or "")[:40],
        "author": _author(r.get("author")),
        "rating": int(r.get("rating") or 0),
        "num_ratings": int(r.get("num_ratings") or 0),
        "active_installs": int(r.get("active_installs") or 0),
        "description": _TAGS.sub("", str(r.get("short_description") or r.get("description") or ""))[:400],
        "requires": str(r.get("requires") or "")[:20] or None,
        "tested": str(r.get("tested") or "")[:20] or None,
        "requires_php": str(r.get("requires_php") or "")[:20] or None,
        "image": (icons.get("2x") or icons.get("1x") or icons.get("svg") or icons.get("default")
                  if kind == "plugin" else r.get("screenshot_url")) or None,
    }


@action("wp.ext_search")
def wp_ext_search(params):
    """Search WordPress.org (through WP-CLI, so the site's WordPress version is used)."""
    domain, path = _target(params)
    kind = v.choice(params, "kind", KINDS)
    query = v.string(params, "query", max_len=100).strip()
    if len(query) < 2:
        raise ActionError("invalid_params", "query must be at least 2 characters")
    page = v.integer(params, "page", required=False, default=1, minimum=1, maximum=50)
    fields = ("name,slug,version,author,rating,num_ratings,active_installs,short_description,requires,tested,requires_php,icons"
              if kind == "plugin" else "name,slug,version,author,rating,num_ratings,description,screenshot_url,requires,requires_php")
    proc = wordpress.wp(domain, [kind, "search", query, f"--per-page={PER_PAGE}", f"--page={page}",
                                 f"--fields={fields}", "--format=json"], path=path, timeout=90)
    out = proc.stdout or ""
    rows = []
    for line in reversed(out.splitlines()):
        line = line.strip()
        if line[:1] == "[":
            try:
                rows = json.loads(line)
            except ValueError:
                rows = []
            break
    m = _SHOWING.search(out)
    total = int(m.group(1)) if m else len(rows)
    results = [_result(kind, r) for r in rows if isinstance(r, dict) and _SLUG.fullmatch(str(r.get("slug", "")))]
    return {"kind": kind, "query": query, "page": page, "total": total,
            "pages": max(1, -(-total // PER_PAGE)), "results": results}


@action("wp.ext_install")
def wp_ext_install(params):
    """Install a plugin or theme from WordPress.org by its slug (never a URL or zip)."""
    domain, path = _target(params)
    kind = v.choice(params, "kind", KINDS)
    slug = _slug(params.get("slug"))
    activate = v.boolean(params, "activate", required=False, default=False)
    current = lists(domain, path)
    if any(i["name"] == slug for i in current[f"{kind}s"]):
        raise ActionError("conflict", f"{kind} {slug} is already installed")
    proc = wordpress.wp(domain, [kind, "install", slug], path=path, timeout=600, check=False)
    if proc.returncode != 0:
        raise ActionError("command_failed", f"Installing {slug} failed: {wordpress.explain(proc)}")
    # Translations for the languages the site already has.
    wordpress.wp(domain, ["language", kind, "update", slug], path=path, timeout=300, check=False)
    result = {"done": [slug], "errors": [], "rolled_back": False, "health": None, **lists(domain, path)}
    if activate:
        act = apply(domain, path, kind, "activate", [slug], current=result)
        result = {**act, "done": [slug], "activated": act["done"] == [slug]}
    return {"domain": domain, "path": path, "kind": kind, "op": "install", "slug": slug, **result}
