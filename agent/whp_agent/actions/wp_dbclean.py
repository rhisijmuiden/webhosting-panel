"""WordPress database cleanup: post revisions, auto drafts, trashed posts, spam and
trashed comments, transients, orphaned meta rows and table optimisation.

wp.dbclean_preview shows what would be removed (nothing is changed). wp.dbclean_run
is a job: it makes a toolkit snapshot first (restorable on the install page), cleans,
purges the caches and puts the database back when the site stops answering.

The work itself is data/whp-dbclean.php, run with `wp eval-file -` as the site user.
"""

import json
import time
from pathlib import Path

from .. import validate as v
from .. import wordpress
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run
from . import wordpress as toolkit

SCRIPT = Path(__file__).resolve().parent.parent / "data" / "whp-dbclean.php"
ITEMS = ["revisions", "auto_drafts", "trashed_posts", "spam_comments", "trashed_comments",
         "expired_transients", "all_transients", "orphaned_meta", "optimize"]
# What a cleanup removes when nobody chose: everything that is safe on any site.
DEFAULT_ITEMS = ["revisions", "auto_drafts", "trashed_posts", "spam_comments", "trashed_comments",
                 "expired_transients", "orphaned_meta", "optimize"]
KEEP_REVISIONS = [0, 1, 2, 3, 5, 10, 20]
MARKER = "WHPJSON:"


def _keep(params):
    keep = v.integer(params, "keep_revisions", required=False, default=0, minimum=0, maximum=100)
    if keep not in KEEP_REVISIONS:
        raise ActionError("invalid_params", f"keep_revisions must be one of: {', '.join(map(str, KEEP_REVISIONS))}")
    return keep


def _items(params):
    if "items" not in params:
        return list(DEFAULT_ITEMS)
    items = v.list_of(params, "items", lambda s: s if s in ITEMS else _bad(s), max_items=len(ITEMS))
    if not items:
        raise ActionError("invalid_params", "Choose at least one thing to clean")
    return [i for i in ITEMS if i in items]


def _bad(value):
    raise ActionError("invalid_params", f"Unknown cleanup item: {str(value)[:40]}")


def call(domain, path, mode, options, timeout):
    """Run the cleanup script and return its JSON answer."""
    proc = wordpress.wp(domain, ["eval-file", "-", mode, json.dumps(options)], path=path,
                        input_text=SCRIPT.read_text(), timeout=timeout)
    for line in reversed((proc.stdout or "").splitlines()):
        if line.startswith(MARKER):
            try:
                return json.loads(line[len(MARKER):])
            except ValueError:
                break
    raise ActionError("command_failed", "The database cleanup gave no answer",
                      {"stdout": (proc.stdout or "")[-1000:], "stderr": (proc.stderr or "")[-1000:]})


@action("wp.dbclean_preview")
def wp_dbclean_preview(params):
    """What a cleanup would remove, per item (count, about how many bytes, a few examples)."""
    domain, path = toolkit._domain_path(params)
    wordpress.require_install(domain, path)
    data = call(domain, path, "preview", {"keep_revisions": _keep(params)}, timeout=300)
    return {"domain": domain, "path": path, "checked_at": int(time.time()), **data}


def clean(params, log):
    domain, path = params["domain"], params["path"]
    wordpress.require_install(domain, path)
    url = toolkit._site_url(domain, path)
    before = toolkit.health(url)
    log(f"{url} answers HTTP {before} before the cleanup")
    snap = toolkit.snapshot(domain, path, log)
    log("Cleaning: " + ", ".join(params["items"]) + (f" (keeping {params['keep_revisions']} revisions per post)"
                                                       if "revisions" in params["items"] else ""))
    try:
        result = call(domain, path, "clean", {"items": params["items"], "keep_revisions": params["keep_revisions"]},
                      timeout=3600)
    finally:
        run([toolkit.WHP_SITE, "cache", domain, "purge"], timeout=120, check=False)
    for item, n in result.get("removed", {}).items():
        log(f"  {item}: {n}")
    log(f"Database {result.get('db_size_before', 0)} -> {result.get('db_size_after', 0)} bytes")

    after = toolkit.health(url)
    log(f"{url} answers HTTP {after} after the cleanup")
    out = {"domain": domain, "path": path, "items": params["items"], "keep_revisions": params["keep_revisions"],
           "snapshot": snap.name, "health": {"before": before, "after": after}, "rolled_back": False, **result}
    if toolkit._healthy(before) and not toolkit._healthy(after):
        log("The site stopped working: putting the database back")
        wordpress.wp(domain, ["db", "import", str(snap / "database.sql"), "--quiet"], path=path, timeout=1800)
        run([toolkit.WHP_SITE, "cache", domain, "purge"], timeout=120, check=False)
        out["rolled_back"] = True
        out["health"]["after"] = toolkit.health(url)
        raise ActionError("command_failed", "The site broke after the cleanup and the database was put back", out)
    return out


@action("wp.dbclean_run")
def wp_dbclean_run(params):
    """Job: snapshot, clean the chosen items, purge caches, put the database back when the site breaks."""
    domain, path = toolkit._domain_path(params)
    wordpress.require_install(domain, path)
    return start_job("wp.dbclean_run", clean, {"domain": domain, "path": path, "items": _items(params),
                                               "keep_revisions": _keep(params)})
