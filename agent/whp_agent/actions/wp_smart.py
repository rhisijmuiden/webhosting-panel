"""Smart WordPress updates: the scheduled update of one install, checked with
screenshots.

The panel starts one job per install in the maintenance window (wp.smart_update).
The job:

1. asks WordPress what can be updated, and picks what the mode allows
   ("all": core, plugins and themes; "security": the newest release of the
   installed core branch plus the plugins/themes the panel names because they
   have a known vulnerability; "notify": nothing, it only reports),
2. takes two screenshots of every page and viewport a few seconds apart (what
   changes between those two is "moving" content, such as sliders and dates,
   and is left out of the comparison),
3. runs the normal toolkit update (snapshot first, rollback when the site
   answers 500+),
4. takes the screenshots again and compares them with the first ones; when more
   of the page changed than the threshold allows, it rolls back to the
   snapshot and takes a last screenshot to show that the site looks as before.

Screenshots: headless Chromium (chromium-headless-shell) as the unprivileged user whp-shot in a
sandboxed transient unit (installer module 58-wp-visual.sh). The site is
asked of this server itself (host-resolver rules), like the health check.
Comparison with Pillow (python3-pil): a grid of 16x16 blocks, a block counts
as changed when its mean difference is above a tolerance.

The images (JPEG, 800 px wide) stay in STATE/wordpress/visual/<run>/ until the
panel fetched them (wp.smart_image) and said so (wp.smart_forget); anything
older than two weeks is removed at the next job.
"""

import base64
import json
import os
import re
import secrets
import shutil
import time
from pathlib import Path

from .. import paths, wordpress
from .. import validate as v
from ..jobs import start_job
from ..registry import ActionError, action
from ..run import run
from . import wordpress as wpa

VISUAL = Path(paths.STATE_DIR) / "wordpress" / "visual"
WORK = Path(paths.STATE_DIR) / "wordpress" / "shots"
CHROMIUM = os.environ.get("WHP_CHROMIUM") or next(
    (p for p in ("/usr/bin/chromium-headless-shell", "/usr/bin/chromium") if os.path.exists(p)), "/usr/bin/chromium")
SHOT_USER = "whp-shot"
WORKER = Path(__file__).resolve().parent.parent / "shot_worker.py"
SYSTEMD_RUN = "/usr/bin/systemd-run"
KEEP_DAYS = 14
SETTLE_SECONDS = 4
BLOCK = 16
TOLERANCE = 12          # mean difference (0-255) of a block that counts as changed
THUMB_WIDTH = 800
MAX_PAGES = 5

VIEWPORTS = {
    "desktop": (1366, 900, None),
    "mobile": (390, 844, "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 "
                          "(KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1"),
}
_RUN = re.compile(r"^[a-z0-9]{8,40}$")
_PAGE = re.compile(r"^/[A-Za-z0-9._~%/-]{0,200}$")
_IMAGE = re.compile(r"^p[0-9]-(desktop|mobile)-(before|after|diff|restored)\.jpg$")


# ---------------------------------------------------------------- what can be done

def capabilities():
    """Whether screenshots can be taken here (Chromium, Pillow and the shot user)."""
    try:
        import PIL  # noqa: F401
        pillow = True
    except ImportError:
        pillow = False
    user = run(["getent", "passwd", SHOT_USER], timeout=10, check=False).returncode == 0
    return {"chromium": os.path.exists(CHROMIUM), "pillow": pillow, "user": user,
            "visual": os.path.exists(CHROMIUM) and pillow and user}


@action("wp.smart_check")
def wp_smart_check(params):
    """Can this server compare screenshots? (shown on the admin screen)."""
    return capabilities()


def _core_minor(domain, path):
    """The newest release of the installed core branch, or None."""
    try:
        rows = wordpress.wp_json(domain, ["core", "check-update", "--minor", "--format=json"], path=path, timeout=180)
    except ActionError:
        return None
    if isinstance(rows, list) and rows:
        return rows[0].get("version")
    return None


def plan(domain, path, mode, security, status):
    """What this mode updates, from a wp.status answer. Returns the wp.update selection
    plus a readable list of the pending updates."""
    plugins = [p for p in status.get("plugins", []) if p.get("update")]
    themes = [t for t in status.get("themes", []) if t.get("update")]
    core = status.get("core_update")
    pending = {
        "core": {"from": status.get("version"), "to": core["version"]} if core else None,
        "plugins": [{"name": p["name"], "title": p.get("title"), "from": p.get("version"), "to": p.get("update_version")} for p in plugins],
        "themes": [{"name": t["name"], "title": t.get("title"), "from": t.get("version"), "to": t.get("update_version")} for t in themes],
    }
    if mode == "all":
        sel = {"core": core is not None, "core_minor": False,
               "plugins": [p["name"] for p in plugins], "themes": [t["name"] for t in themes]}
    elif mode == "security":
        minor = _core_minor(domain, path) if core else None
        if minor and minor != status.get("version"):
            pending["core"] = {"from": status.get("version"), "to": minor, "security": True}
        sel = {"core": bool(minor and minor != status.get("version")), "core_minor": True,
               "plugins": [p["name"] for p in plugins if p["name"] in security.get("plugins", [])],
               "themes": [t["name"] for t in themes if t["name"] in security.get("themes", [])]}
    else:
        sel = {"core": False, "core_minor": False, "plugins": [], "themes": []}
    return sel, pending


def _empty(sel):
    return not sel["core"] and not sel["plugins"] and not sel["themes"]


# ---------------------------------------------------------------- screenshots

def _host(url):
    m = re.match(r"^https?://([^/:]+)", url)
    return m.group(1) if m else ""


def shoot(url, viewport, out):
    """One screenshot of url in a viewport, as PNG at out. Returns an error text or None.

    shot_worker.py drives Chromium over the DevTools pipe; it runs as whp-shot in
    a sandboxed transient unit and gets its code on stdin (whp-shot cannot read
    the agent's folder)."""
    width, height, agent = VIEWPORTS[viewport]
    work = WORK / secrets.token_hex(6)
    WORK.mkdir(mode=0o711, parents=True, exist_ok=True)
    work.mkdir(mode=0o700)
    try:
        shutil.chown(work, SHOT_USER, SHOT_USER)
        shot = work / "shot.png"
        argv = [SYSTEMD_RUN, "--quiet", "--wait", "--collect", "--pipe", "--expand-environment=no",
                f"--uid={SHOT_USER}", f"--gid={SHOT_USER}",
                "-p", "ProtectSystem=strict", "-p", f"ReadWritePaths={work}", "-p", "ProtectHome=yes",
                "-p", "PrivateTmp=yes", "-p", "PrivateDevices=yes", "-p", "NoNewPrivileges=yes",
                "-p", "MemoryMax=1536M", "-p", "RuntimeMaxSec=100", "-p", "Nice=10", "-p", "CPUQuota=200%",
                "-E", f"HOME={work}", "--",
                "/usr/bin/python3", "-I", "-", CHROMIUM, url, str(shot), str(width), str(height),
                "1" if agent else "0", _host(url), *([agent] if agent else [])]
        proc = run(argv, timeout=120, check=False, input_text=WORKER.read_text())
        answer = {}
        for line in reversed((proc.stdout or "").splitlines()):
            if line.startswith("{"):
                try:
                    answer = json.loads(line)
                except ValueError:
                    pass
                break
        if not answer.get("ok") or not shot.is_file() or shot.stat().st_size == 0:
            text = answer.get("error") or (proc.stderr or "").strip().splitlines()[-1:]
            return "No screenshot: " + (text if isinstance(text, str) else (text[0][:300] if text else f"exit code {proc.returncode}"))
        shutil.copyfile(shot, out)
        return None
    except ActionError as exc:
        return exc.message
    finally:
        shutil.rmtree(work, ignore_errors=True)


def _page_url(base, page):
    sep = "&" if "?" in page else "?"
    return base + (page if page != "/" else "/") + f"{sep}whp_shot={secrets.token_hex(4)}"


# ---------------------------------------------------------------- comparison

def _grid(a, b):
    """Mean difference per block of two images, as a list of rows (0-255)."""
    from PIL import Image, ImageChops

    # A page that got shorter or longer: the missing part counts as changed.
    w, h = max(a.width, b.width), max(a.height, b.height)
    a, b = _pad(a, w, h), _pad(b, w, h)
    cols, rows = max(1, w // BLOCK), max(1, h // BLOCK)
    diff = ImageChops.difference(a, b).convert("L").resize((cols, rows), Image.Resampling.BOX)
    data = list(diff.tobytes())
    return [data[r * cols:(r + 1) * cols] for r in range(rows)]


def _pad(img, w, h):
    from PIL import Image

    if img.size == (w, h):
        return img
    canvas = Image.new("RGB", (w, h), (255, 255, 255))
    canvas.paste(img, (0, 0))
    return canvas


def _changed(grid):
    return {(r, c) for r, row in enumerate(grid) for c, val in enumerate(row) if val > TOLERANCE}


def _dilate(blocks, rows, cols):
    out = set()
    for r, c in blocks:
        for dr in (-1, 0, 1):
            for dc in (-1, 0, 1):
                if 0 <= r + dr < rows and 0 <= c + dc < cols:
                    out.add((r + dr, c + dc))
    return out


def _blank(img):
    """True for a (nearly) single colour page: white screen, error page without styling."""
    from PIL import ImageStat

    stat = ImageStat.Stat(img.convert("L").resize((64, 64)))
    return stat.stddev[0] < 3


def compare(before, again, after):
    """Percentage of the page that changed between before and after, leaving out the
    blocks that also changed between before and again (moving content)."""
    from PIL import Image

    imgs = [Image.open(p).convert("RGB") for p in (before, again, after)]
    noise_grid = _grid(imgs[0], imgs[1])
    rows, cols = len(noise_grid), len(noise_grid[0])
    noise = _dilate(_changed(noise_grid), rows, cols)
    grid = _grid(imgs[0], imgs[2])
    changed = _changed(grid) - noise
    total = rows * cols - len(noise)
    score = 100.0 if total <= 0 else round(len(changed) / total * 100, 2)
    size_changed = imgs[0].size != imgs[2].size
    blank = _blank(imgs[2]) and not _blank(imgs[0])
    if blank:
        score = 100.0
    return {"score": score, "noise": round(len(noise) / (rows * cols) * 100, 2), "blank": blank,
            "size_changed": size_changed, "changed": changed, "rows": rows, "cols": cols}


def _thumb(src, dst, overlay=None, size=None):
    """JPEG, THUMB_WIDTH wide; overlay = (changed blocks, noise blocks) paints a diff
    (on a canvas of size, so a page that got shorter shows the missing part)."""
    from PIL import Image, ImageDraw

    img = Image.open(src).convert("RGB")
    if size:
        img = _pad(img, *size)
    if overlay is not None:
        changed, noise = overlay
        layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
        draw = ImageDraw.Draw(layer)
        for r, c in noise:
            draw.rectangle((c * BLOCK, r * BLOCK, (c + 1) * BLOCK - 1, (r + 1) * BLOCK - 1), fill=(120, 130, 150, 70))
        for r, c in changed:
            draw.rectangle((c * BLOCK, r * BLOCK, (c + 1) * BLOCK - 1, (r + 1) * BLOCK - 1), fill=(230, 40, 60, 120))
        img = Image.alpha_composite(img.convert("RGBA").point(lambda x: int(x * 0.85) + 38), layer).convert("RGB")
    if img.width > THUMB_WIDTH:
        img = img.resize((THUMB_WIDTH, round(img.height * THUMB_WIDTH / img.width)), Image.Resampling.LANCZOS)
    img.save(dst, "JPEG", quality=72, optimize=True, progressive=True)


# ---------------------------------------------------------------- the job

def _prune():
    if not VISUAL.is_dir():
        return
    limit = time.time() - KEEP_DAYS * 86400
    for d in VISUAL.iterdir():
        try:
            if d.is_dir() and not d.is_symlink() and d.stat().st_mtime < limit:
                shutil.rmtree(d, ignore_errors=True)
        except OSError:
            pass


def _run_dir(run_id):
    VISUAL.mkdir(mode=0o700, parents=True, exist_ok=True)
    d = VISUAL / run_id
    d.mkdir(mode=0o700, exist_ok=True)
    return d


class Shots:
    """The screenshots of one run: raw PNGs in a work folder, JPEGs for the panel."""

    def __init__(self, run_id, base, pages, viewports, log):
        self.dir = _run_dir(run_id)
        self.raw = self.dir / "raw"
        self.raw.mkdir(mode=0o700, exist_ok=True)
        self.base, self.pages, self.viewports, self.log = base, pages, viewports, log
        self.errors = []

    def targets(self):
        for i, page in enumerate(self.pages):
            for vp in self.viewports:
                yield i, page, vp

    def raw_path(self, i, vp, kind):
        return self.raw / f"p{i}-{vp}-{kind}.png"

    def take(self, kind):
        ok = 0
        for i, page, vp in self.targets():
            err = shoot(_page_url(self.base, page), vp, self.raw_path(i, vp, kind))
            if err:
                self.errors.append(f"{page} ({vp}): {err}")
                self.log(f"Screenshot {page} {vp} {kind}: {err}")
            else:
                ok += 1
        return ok

    def compare(self, against="after"):
        out = []
        for i, page, vp in self.targets():
            files = [self.raw_path(i, vp, k) for k in ("before", "again", against)]
            if not all(f.is_file() for f in files):
                continue
            res = compare(*files)
            out.append({"page": page, "viewport": vp, "index": i, **{k: res[k] for k in ("score", "noise", "blank", "size_changed")},
                        "_changed": res["changed"]})
        return out

    def images(self, results, restored=None):
        """Write the JPEGs the panel shows; returns the list of names per page/viewport."""
        from PIL import Image  # noqa: F401  (fails early when Pillow is missing)

        by_key = {(r["index"], r["viewport"]): r for r in results}
        names = []
        for i, page, vp in self.targets():
            entry = {"page": page, "viewport": vp, "images": {}}
            for kind in ("before", "after", "restored"):
                src = self.raw_path(i, vp, kind)
                if src.is_file():
                    name = f"p{i}-{vp}-{kind}.jpg"
                    _thumb(src, self.dir / name)
                    entry["images"][kind] = name
            r = by_key.get((i, vp))
            if r and self.raw_path(i, vp, "after").is_file():
                name = f"p{i}-{vp}-diff.jpg"
                before, again, after = [Image.open(self.raw_path(i, vp, k)).convert("RGB") for k in ("before", "again", "after")]
                noise_grid = _grid(before, again)
                noise = _dilate(_changed(noise_grid), len(noise_grid), len(noise_grid[0]))
                size = (max(before.width, after.width), max(before.height, after.height))
                _thumb(self.raw_path(i, vp, "after"), self.dir / name, (r["_changed"], noise), size)
                entry["images"]["diff"] = name
                entry.update({k: r[k] for k in ("score", "noise", "blank", "size_changed")})
            if restored:
                rr = next((x for x in restored if x["index"] == i and x["viewport"] == vp), None)
                if rr:
                    entry["restored_score"] = rr["score"]
            names.append(entry)
        return names

    def cleanup_raw(self):
        shutil.rmtree(self.raw, ignore_errors=True)


def smart_update(p, log):
    domain, path, mode = p["domain"], p["path"], p["mode"]
    _prune()
    status = wpa.status(domain, path)
    sel, pending = plan(domain, path, mode, p["security"], status)
    result = {"domain": domain, "path": path, "mode": mode, "pending": pending, "updated": None,
              "outcome": "up_to_date", "reason": None, "pages": [], "threshold": p["threshold"],
              "max_score": None, "visual": False, "visual_errors": [], "status": status, "health": None,
              "errors": [], "snapshot": None}
    has_pending = bool(pending["core"] or pending["plugins"] or pending["themes"])
    if mode == "notify":
        result["outcome"] = "pending" if has_pending else "up_to_date"
        return result
    if _empty(sel):
        result["outcome"] = "pending" if has_pending else "up_to_date"
        log("Nothing to update in this mode" + (" (other updates are waiting)" if has_pending else ""))
        return result

    url = wpa._site_url(domain, path)
    shots = None
    if p["visual"]:
        caps = capabilities()
        if caps["visual"]:
            shots = Shots(p["run"], url, p["pages"], p["viewports"], log)
            log(f"Screenshots before the update ({len(p['pages'])} page(s), {', '.join(p['viewports'])})")
            first = shots.take("before")
            time.sleep(SETTLE_SECONDS)
            shots.take("again")
            if first == 0:
                log("No screenshot could be made; only the health check guards this update")
                result["visual_errors"] = shots.errors
                shots = None
        else:
            missing = [k for k in ("chromium", "pillow", "user") if not caps[k]]
            result["visual_errors"] = [f"Screenshots are not available on this server (missing: {', '.join(missing)})"]
            log(result["visual_errors"][0])

    job = {"domain": domain, "path": path, "core": sel["core"], "core_minor": sel["core_minor"],
           "plugins": sel["plugins"] or False, "themes": sel["themes"] or False,
           "translations": True, "snapshot": True, "rollback": True}
    try:
        res = wpa.update(job, log)
    except ActionError as exc:
        details = exc.details or {}
        result.update({"updated": details.get("updated"), "health": details.get("health"),
                       "snapshot": details.get("snapshot"), "errors": [exc.message, *details.get("errors", [])]})
        if details.get("status"):
            result["status"] = details["status"]
        if details.get("rolled_back"):
            result.update({"outcome": "rolled_back", "reason": "health"})
        else:
            result["outcome"] = "failed"
        if shots:
            shots.take("after")
            result["pages"] = shots.images(shots.compare())
            result["visual"] = True
            shots.cleanup_raw()
        return result

    result.update({"updated": res["updated"], "health": res["health"], "snapshot": res["snapshot"],
                   "errors": res["errors"], "status": res.get("status", status), "outcome": "updated"})
    if not shots:
        return result

    log("Screenshots after the update")
    shots.take("after")
    compared = shots.compare()
    result["visual"] = bool(compared)
    result["visual_errors"] = shots.errors
    if compared:
        worst = max(compared, key=lambda r: r["score"])
        result["max_score"] = worst["score"]
        log(f"Largest visual difference: {worst['score']}% ({worst['page']}, {worst['viewport']}); "
            f"threshold {p['threshold']}%")
    restored = None
    if compared and result["max_score"] > p["threshold"] and res["snapshot"]:
        snap = wpa.SNAPSHOTS / wpa._key(domain, path) / res["snapshot"]
        wpa.rollback(domain, path, snap, res["updated"], log)
        run([wpa.WHP_SITE, "cache", domain, "purge"], timeout=120, check=False)
        result.update({"outcome": "rolled_back", "reason": "visual"})
        log("The website looked different after the update and was rolled back")
        shots.take("restored")
        restored = shots.compare("restored")
        try:
            result["status"] = wpa.status(domain, path)
        except ActionError as exc:
            log(f"Status after the rollback failed: {exc.message}")
    result["pages"] = shots.images(compared, restored)
    shots.cleanup_raw()
    return result


def _params(params):
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    run_id = v.string(params, "run", pattern=_RUN.pattern, max_len=40)
    mode = v.choice(params, "mode", ["all", "security", "notify"])
    sec = params.get("security") or {}
    if not isinstance(sec, dict):
        raise ActionError("invalid_params", "security must be an object")
    security = {k: v.list_of(sec, k, lambda s: s if isinstance(s, str) and wpa._SLUG.fullmatch(s) else wpa._bad(k),
                             required=False, max_items=200) for k in ("plugins", "themes")}
    pages = v.list_of(params, "pages", lambda s: s if isinstance(s, str) and _PAGE.fullmatch(s) and ".." not in s
                      else _bad_page(), required=False, max_items=MAX_PAGES) or ["/"]
    viewports = v.list_of(params, "viewports", lambda s: s if s in VIEWPORTS else _bad_viewport(),
                          required=False, max_items=2) or ["desktop", "mobile"]
    threshold = params.get("threshold", 10)
    if not isinstance(threshold, (int, float)) or isinstance(threshold, bool) or not (1 <= threshold <= 90):
        raise ActionError("invalid_params", "threshold must be a number from 1 to 90")
    return {"domain": domain, "path": path, "run": run_id, "mode": mode, "security": security,
            "pages": list(dict.fromkeys(pages)), "viewports": list(dict.fromkeys(viewports)),
            "threshold": float(threshold), "visual": v.boolean(params, "visual", required=False, default=True)}


def _bad_page():
    raise ActionError("invalid_params", "pages must be paths like /contact")


def _bad_viewport():
    raise ActionError("invalid_params", "viewports must be desktop and/or mobile")


@action("wp.smart_update")
def wp_smart_update(params):
    """Job: scheduled update of one install with a screenshot comparison and rollback
    (see the module docstring). mode: all | security | notify."""
    p = _params(params)
    wordpress.require_install(p["domain"], p["path"])
    return start_job("wp.smart_update", smart_update, p)


@action("wp.smart_image")
def wp_smart_image(params):
    """One screenshot of a run, as base64 JPEG."""
    run_id = v.string(params, "run", pattern=_RUN.pattern, max_len=40)
    name = v.string(params, "name", pattern=_IMAGE.pattern, max_len=40)
    f = VISUAL / run_id / name
    if not f.is_file() or f.is_symlink():
        raise ActionError("not_found", "Screenshot not found")
    return {"name": name, "type": "image/jpeg", "data": base64.b64encode(f.read_bytes()).decode()}


@action("wp.smart_forget")
def wp_smart_forget(params):
    """The panel stored the screenshots of a run: remove them here."""
    run_id = v.string(params, "run", pattern=_RUN.pattern, max_len=40)
    d = VISUAL / run_id
    if d.is_dir() and not d.is_symlink():
        shutil.rmtree(d, ignore_errors=True)
    return {"run": run_id, "removed": True}

