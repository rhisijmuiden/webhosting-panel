"""WordPress plugin sets: a fixed list of plugins and themes put on a new WordPress.

The panel admin defines sets (for example "RHIS standard") and picks one when
WordPress or WooCommerce is installed through the app installer. The panel
sends the set with apps.install; the install job applies it right after
WordPress itself is ready, with the same WP-CLI runner (site user, systemd-run).

A set never breaks an install: a plugin or theme that cannot be installed is
reported and skipped, WordPress itself stays. The report goes back in the job
result (plugin_set) so the panel can show what was added.

    {
      "name": "RHIS standard",
      "plugins": [{"source": "wordfence", "activate": true},
                  {"source": "https://example.com/pro-plugin.zip", "activate": false}],
      "themes": [{"source": "generatepress", "activate": true}],
      "remove_default_content": true,     # "Hello world!" post and "Sample page"
      "remove_inactive_themes": true      # the bundled Twenty* themes not in use
    }

Sources are a wordpress.org slug or an https link to a zip (for paid plugins
the admin hosts). Nothing goes through a shell: every value is one argv item,
and a source can never start with "-".
"""

import os
import re
import urllib.parse

from . import validate as v
from .registry import ActionError

MAX_PLUGINS = 40
MAX_THEMES = 10
SLUG_RE = re.compile(r"[a-z0-9][a-z0-9_-]{0,99}")
URL_RE = re.compile(r"https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?/[^\s\"'<>\\]{1,480}")
WP_LOCALE = {"nl": "nl_NL"}


def _source(value, key):
    if not isinstance(value, str):
        raise ActionError("invalid_params", f"{key} must be a string")
    if SLUG_RE.fullmatch(value):
        return value
    if URL_RE.fullmatch(value):
        path = urllib.parse.urlsplit(value).path
        if path.lower().endswith(".zip"):
            return value
    raise ActionError("invalid_params", f"{key} must be a wordpress.org slug or an https link to a .zip file")


def _items(spec, key, maximum):
    items = spec.get(key) or []
    if not isinstance(items, list) or len(items) > maximum:
        raise ActionError("invalid_params", f"plugin_set.{key} must be a list of at most {maximum} items")
    out, seen = [], set()
    for i, item in enumerate(items):
        if not isinstance(item, dict):
            raise ActionError("invalid_params", f"plugin_set.{key}[{i}] must be an object")
        source = _source(item.get("source"), f"plugin_set.{key}[{i}].source")
        if source in seen:
            continue
        seen.add(source)
        out.append({"source": source, "activate": bool(item.get("activate", False))})
    return out


def validate(spec):
    """The plugin_set parameter of apps.install, cleaned; None when there is none."""
    if spec in (None, {}, ""):
        return None
    if not isinstance(spec, dict):
        raise ActionError("invalid_params", "plugin_set must be an object")
    name = v.string(spec, "name", required=False, default="", max_len=80) or "Plugin set"
    plugins = _items(spec, "plugins", MAX_PLUGINS)
    themes = _items(spec, "themes", MAX_THEMES)
    if sum(1 for t in themes if t["activate"]) > 1:
        raise ActionError("invalid_params", "plugin_set.themes: only one theme can be activated")
    out = {
        "name": name,
        "plugins": plugins,
        "themes": themes,
        "remove_default_content": bool(spec.get("remove_default_content", False)),
        "remove_inactive_themes": bool(spec.get("remove_inactive_themes", False)),
    }
    if not plugins and not themes and not out["remove_default_content"] and not out["remove_inactive_themes"]:
        return None
    return out


def label(source):
    """What people see for a source: the slug, or the file name of a link without .zip."""
    if source.startswith("https://"):
        base = os.path.basename(urllib.parse.urlsplit(source).path)
        return re.sub(r"\.zip$", "", base, flags=re.I) or source
    return source


def _error(exc):
    details = getattr(exc, "details", None) or {}
    text = "\n".join(str(details.get(k) or "") for k in ("stderr", "stdout"))
    m = re.search(r"(?m)^(?:Error|Warning): (.+)$", text)
    return (m.group(1).strip() if m else str(exc))[:300]


def apply(wp, spec, locale, log):
    """Install the set with wp(args, timeout=...) and return the report. Never raises for one item."""
    report = {"name": spec["name"], "items": [], "warnings": []}

    def step(kind, source, args, timeout=600):
        name = label(source)
        entry = {"type": kind, "name": name, "source": source, "ok": True,
                 "activated": "--activate" in args}
        try:
            wp(args, timeout=timeout)
        except Exception as exc:  # one failing plugin must not stop the rest
            entry.update(ok=False, activated=False, error=_error(exc))
            log(f"Could not install {kind} {name}: {entry['error']}")
        report["items"].append(entry)
        return entry

    if spec["themes"]:
        log(f"Plugin set {spec['name']}: installing {len(spec['themes'])} theme(s)")
    for theme in spec["themes"]:
        step("theme", theme["source"],
             ["theme", "install", theme["source"], *(["--activate"] if theme["activate"] else [])])

    if spec["plugins"]:
        log(f"Plugin set {spec['name']}: installing {len(spec['plugins'])} plugin(s)")
    for plugin in spec["plugins"]:
        step("plugin", plugin["source"],
             ["plugin", "install", plugin["source"], *(["--activate"] if plugin["activate"] else [])])

    wp_locale = WP_LOCALE.get(locale)
    if wp_locale and any(i["ok"] for i in report["items"]):
        log("Installing the Dutch translations of the plugins and themes")
        for kind in ("plugin", "theme"):
            try:
                wp(["language", kind, "install", "--all", wp_locale], timeout=600)
            except Exception as exc:
                report["warnings"].append(f"Translations ({kind}s): {_error(exc)}")

    if spec["remove_default_content"]:
        log("Removing the example post and page")
        try:
            # A fresh install: 1 = "Hello world!" (with its comment), 2 = "Sample Page".
            # The privacy policy draft (3) stays: sites need one anyway.
            wp(["post", "delete", "1", "2", "--force"], timeout=120)
        except Exception as exc:
            report["warnings"].append(f"Example content: {_error(exc)}")

    if spec["remove_inactive_themes"]:
        try:
            proc = wp(["theme", "list", "--status=inactive", "--field=name"], timeout=120)
            inactive = [n.strip() for n in (proc.stdout or "").splitlines() if SLUG_RE.fullmatch(n.strip())]
            if inactive:
                log(f"Removing unused themes: {', '.join(inactive)}")
                wp(["theme", "delete", *inactive], timeout=300)
                report["removed_themes"] = inactive
        except Exception as exc:
            report["warnings"].append(f"Unused themes: {_error(exc)}")

    failed = sum(1 for i in report["items"] if not i["ok"])
    report["failed"] = failed
    log(f"Plugin set {spec['name']} applied" + (f", {failed} item(s) failed" if failed else ""))
    return report
