"""wp.smart_*: what each mode updates, the screenshot comparison (moving content
left out, blank pages caught) and the job: update kept, rolled back on a visual
difference or a broken site, notify only. Chromium, WP-CLI and the toolkit update
are mocked; the real thing runs in the Debian 13 smoke test (tests/smoke.d/wp-smart-updates.sh).
"""

import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent import jobs, paths  # noqa: E402
from whp_agent.actions import wordpress as wpa  # noqa: E402
from whp_agent.actions import wp_smart as ws  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402

try:
    from PIL import Image, ImageDraw
    PIL = True
except ImportError:  # the CI runner has no Pillow; Debian servers get python3-pil
    PIL = False

STATUS = {
    "version": "6.6.1",
    "core_update": {"version": "6.7.1", "type": "major"},
    "plugins": [
        {"name": "akismet", "title": "Akismet", "version": "5.0", "update": True, "update_version": "5.3"},
        {"name": "contact-form-7", "title": "CF7", "version": "5.8", "update": True, "update_version": "5.9.2"},
        {"name": "hello", "title": "Hello", "version": "1.7", "update": False, "update_version": None},
    ],
    "themes": [{"name": "astra", "title": "Astra", "version": "4.0", "update": True, "update_version": "4.1"}],
}


def wait(job):
    for _ in range(400):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(0.02)
    raise AssertionError("job did not finish")


def page(path, blocks=(), slider=None, blank=False):
    """A fake web page: header, text blocks, optional 'slider' that differs per call."""
    img = Image.new("RGB", (400, 320), "white")
    if blank:
        img.save(path)
        return
    d = ImageDraw.Draw(img)
    d.rectangle((0, 0, 400, 40), fill=(20, 40, 90))
    for i, colour in enumerate(blocks or [(200, 120, 30)] * 4):
        d.rectangle((20, 60 + i * 60, 380, 100 + i * 60), fill=colour)
    if slider is not None:
        d.rectangle((300, 0, 400, 40), fill=slider)
    img.save(path)


class PlanTest(unittest.TestCase):
    def test_all_takes_everything_that_is_outdated(self):
        sel, pending = ws.plan("klant.nl", "", "all", {}, STATUS)
        self.assertEqual(sel, {"core": True, "core_minor": False, "plugins": ["akismet", "contact-form-7"], "themes": ["astra"]})
        self.assertEqual(pending["core"], {"from": "6.6.1", "to": "6.7.1"})
        self.assertEqual([p["name"] for p in pending["plugins"]], ["akismet", "contact-form-7"])

    def test_security_takes_core_branch_and_vulnerable_parts_only(self):
        with mock.patch.object(ws, "_core_minor", return_value="6.6.2"):
            sel, pending = ws.plan("klant.nl", "", "security", {"plugins": ["contact-form-7", "not-installed"], "themes": []}, STATUS)
        self.assertEqual(sel, {"core": True, "core_minor": True, "plugins": ["contact-form-7"], "themes": []})
        self.assertEqual(pending["core"]["to"], "6.6.2")

    def test_security_without_a_minor_release_leaves_core_alone(self):
        with mock.patch.object(ws, "_core_minor", return_value=None):
            sel, _ = ws.plan("klant.nl", "", "security", {}, STATUS)
        self.assertTrue(ws._empty(sel))

    def test_notify_changes_nothing(self):
        sel, pending = ws.plan("klant.nl", "", "notify", {}, STATUS)
        self.assertTrue(ws._empty(sel))
        self.assertIsNotNone(pending["core"])


class ParamsTest(unittest.TestCase):
    def base(self, **over):
        return {"domain": "klant.nl", "run": "abcdef1234", "mode": "all", **over}

    def test_defaults(self):
        p = ws._params(self.base())
        self.assertEqual(p["pages"], ["/"])
        self.assertEqual(p["viewports"], ["desktop", "mobile"])
        self.assertEqual(p["threshold"], 10.0)
        self.assertTrue(p["visual"])

    def test_rejects_bad_input(self):
        for bad in ({"run": "../x"}, {"mode": "everything"}, {"pages": ["contact"]}, {"pages": ["/../etc"]},
                    {"pages": ["/a?b=1"]}, {"pages": ["/" + str(i) for i in range(6)]}, {"threshold": 0},
                    {"threshold": True}, {"viewports": ["tablet"]}, {"security": {"plugins": ["Bad Slug"]}}):
            with self.subTest(bad=bad), self.assertRaises(ActionError):
                ws._params(self.base(**bad))

    def test_image_names_are_strict(self):
        with tempfile.TemporaryDirectory() as tmp, mock.patch.object(ws, "VISUAL", Path(tmp)):
            for name in ("../x.jpg", "p0-desktop-before.png", "raw/p0-desktop-before.png"):
                with self.subTest(name=name), self.assertRaises(ActionError):
                    ws.wp_smart_image({"run": "abcdef1234", "name": name})


@unittest.skipUnless(PIL, "Pillow is not installed")
class CompareTest(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())

    def f(self, name):
        return self.tmp / name

    def test_same_page_scores_zero(self):
        for n in ("a", "b", "c"):
            page(self.f(n + ".png"))
        self.assertEqual(ws.compare(self.f("a.png"), self.f("b.png"), self.f("c.png"))["score"], 0.0)

    def test_moving_content_is_left_out(self):
        page(self.f("a.png"), slider=(255, 0, 0))
        page(self.f("b.png"), slider=(0, 255, 0))
        page(self.f("c.png"), slider=(0, 0, 255))
        res = ws.compare(self.f("a.png"), self.f("b.png"), self.f("c.png"))
        self.assertEqual(res["score"], 0.0)
        self.assertGreater(res["noise"], 0)

    def test_a_broken_layout_scores_high(self):
        page(self.f("a.png"))
        page(self.f("b.png"))
        page(self.f("c.png"), blocks=[(255, 255, 255), (10, 200, 10), (255, 255, 255), (255, 255, 255)])
        self.assertGreater(ws.compare(self.f("a.png"), self.f("b.png"), self.f("c.png"))["score"], 20)

    def test_a_white_screen_scores_100(self):
        page(self.f("a.png"))
        page(self.f("b.png"))
        page(self.f("c.png"), blank=True)
        res = ws.compare(self.f("a.png"), self.f("b.png"), self.f("c.png"))
        self.assertTrue(res["blank"])
        self.assertEqual(res["score"], 100.0)


class JobTest(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.patches = [
            mock.patch.object(paths, "JOBS_DIR", str(self.tmp / "jobs")),
            mock.patch.object(ws, "VISUAL", self.tmp / "visual"),
            mock.patch.object(ws, "SETTLE_SECONDS", 0),
            mock.patch.object(ws.wordpress, "require_install", return_value=({}, str(self.tmp))),
            mock.patch.object(wpa, "status", side_effect=lambda d, p: dict(STATUS)),
            mock.patch.object(wpa, "_site_url", return_value="https://klant.nl"),
            mock.patch.object(wpa, "_key", return_value="7-root"),
            mock.patch.object(ws, "run"),
            mock.patch.object(ws, "_core_minor", return_value="6.6.2"),
        ]
        for p in self.patches:
            p.start()
        self.rollback = mock.patch.object(wpa, "rollback").start()
        self.patches.append(mock.patch.stopall)
        self.updates = []
        self.after_broken = False
        self.rolled = False

    def tearDown(self):
        mock.patch.stopall()

    def fake_update(self, job, log):
        self.updates.append(job)
        return {"updated": {"core": None, "plugins": [{"name": "akismet", "old_version": "5.0", "new_version": "5.3"}],
                            "themes": [], "translations": True},
                "errors": [], "health": {"before": 200, "after": 200}, "snapshot": "20261107-031500",
                "status": dict(STATUS, plugins=[])}

    def fake_shoot(self, url, viewport, out):
        kind = Path(out).stem.split("-")[-1]
        self.assertIn("whp_shot=", url)
        broken = self.after_broken and kind == "after"
        page(out, blocks=[(255, 255, 255)] * 2 + [(0, 0, 0)] * 2 if broken else None)
        return None

    def start(self, **params):
        caps = {"chromium": True, "pillow": True, "user": True, "visual": True}
        with mock.patch.object(ws, "capabilities", return_value=caps), mock.patch.object(ws, "shoot", side_effect=self.fake_shoot), \
                mock.patch.object(wpa, "update", side_effect=self.fake_update):
            return wait(ws.wp_smart_update({"domain": "klant.nl", "run": "run0000001", "mode": "all", **params}))

    @unittest.skipUnless(PIL, "Pillow is not installed")
    def test_update_is_kept_when_the_site_looks_the_same(self):
        state = self.start(viewports=["desktop"])
        res = state["result"]
        self.assertEqual(state["status"], "succeeded", state)
        self.assertEqual(res["outcome"], "updated")
        self.assertEqual(res["max_score"], 0.0)
        self.assertFalse(self.rollback.called)
        self.assertEqual(self.updates[0]["plugins"], ["akismet", "contact-form-7"])
        self.assertTrue(self.updates[0]["snapshot"] and self.updates[0]["rollback"])
        imgs = res["pages"][0]["images"]
        self.assertEqual(sorted(imgs), ["after", "before", "diff"])
        for name in imgs.values():
            self.assertTrue((self.tmp / "visual/run0000001" / name).is_file())
        self.assertFalse((self.tmp / "visual/run0000001/raw").exists())
        data = ws.wp_smart_image({"run": "run0000001", "name": imgs["diff"]})
        self.assertEqual(data["type"], "image/jpeg")
        ws.wp_smart_forget({"run": "run0000001"})
        self.assertFalse((self.tmp / "visual/run0000001").exists())

    @unittest.skipUnless(PIL, "Pillow is not installed")
    def test_visual_difference_rolls_back(self):
        self.after_broken = True
        res = self.start(viewports=["desktop"], threshold=10)["result"]
        self.assertEqual(res["outcome"], "rolled_back")
        self.assertEqual(res["reason"], "visual")
        self.assertGreater(res["max_score"], 10)
        self.rollback.assert_called_once()
        self.assertTrue(str(self.rollback.call_args.args[2]).endswith("7-root/20261107-031500"))
        entry = res["pages"][0]
        self.assertIn("restored", entry["images"])
        self.assertEqual(entry["restored_score"], 0.0)

    @unittest.skipUnless(PIL, "Pillow is not installed")
    def test_a_high_threshold_keeps_the_update(self):
        self.after_broken = True
        res = self.start(viewports=["desktop"], threshold=90)["result"]
        self.assertEqual(res["outcome"], "updated")
        self.assertFalse(self.rollback.called)

    def test_broken_site_rolled_back_by_the_health_check(self):
        def broken(job, log):
            raise ActionError("command_failed", "The site broke after the update and was rolled back",
                              {"updated": {"plugins": []}, "health": {"before": 200, "after": 500}, "rolled_back": True,
                               "snapshot": "20261107-031500", "errors": []})
        caps = {"chromium": False, "pillow": False, "user": False, "visual": False}
        with mock.patch.object(ws, "capabilities", return_value=caps), mock.patch.object(wpa, "update", side_effect=broken):
            res = wait(ws.wp_smart_update({"domain": "klant.nl", "run": "run0000002", "mode": "all"}))["result"]
        self.assertEqual(res["outcome"], "rolled_back")
        self.assertEqual(res["reason"], "health")
        self.assertEqual(res["health"]["after"], 500)
        self.assertIn("missing: chromium, pillow, user", res["visual_errors"][0])

    def test_security_mode_updates_only_the_vulnerable_plugin(self):
        with mock.patch.object(wpa, "update", side_effect=self.fake_update):
            res = wait(ws.wp_smart_update({"domain": "klant.nl", "run": "run0000003", "mode": "security", "visual": False,
                                           "security": {"plugins": ["contact-form-7"]}}))["result"]
        self.assertEqual(res["outcome"], "updated")
        job = self.updates[0]
        self.assertEqual((job["core"], job["core_minor"], job["plugins"], job["themes"]), (True, True, ["contact-form-7"], False))

    def test_notify_only_reports(self):
        with mock.patch.object(wpa, "update", side_effect=self.fake_update):
            res = wait(ws.wp_smart_update({"domain": "klant.nl", "run": "run0000004", "mode": "notify"}))["result"]
        self.assertEqual(res["outcome"], "pending")
        self.assertEqual(self.updates, [])
        self.assertEqual(len(res["pending"]["plugins"]), 2)

    def test_nothing_to_do(self):
        with mock.patch.object(wpa, "status", return_value={"version": "6.7.1", "core_update": None, "plugins": [], "themes": []}), \
                mock.patch.object(wpa, "update", side_effect=self.fake_update):
            res = wait(ws.wp_smart_update({"domain": "klant.nl", "run": "run0000005", "mode": "all"}))["result"]
        self.assertEqual(res["outcome"], "up_to_date")
        self.assertEqual(self.updates, [])


class ShootTest(unittest.TestCase):
    def test_chromium_runs_sandboxed_as_the_shot_user_against_this_server(self):
        calls = []

        def fake_run(argv, timeout=60, check=True, input_text=None, **kw):
            calls.append((argv, input_text))
            i = argv.index("-")
            Path(argv[i + 3]).write_bytes(b"png")
            return mock.Mock(returncode=0, stdout='{"ok": true, "height": 1600}\n', stderr="")

        with tempfile.TemporaryDirectory() as tmp, mock.patch.object(ws, "WORK", Path(tmp) / "shots"), \
                mock.patch.object(ws, "run", side_effect=fake_run), mock.patch.object(ws.shutil, "chown"):
            out = Path(tmp) / "x.png"
            self.assertIsNone(ws.shoot("https://www.klant.nl/?whp_shot=1", "mobile", out))
            self.assertEqual(out.read_bytes(), b"png")
            self.assertEqual(list((Path(tmp) / "shots").iterdir()), [])
        argv, code = calls[0]
        self.assertIn("--uid=whp-shot", argv)
        self.assertIn("NoNewPrivileges=yes", argv)
        self.assertIn("ProtectSystem=strict", argv)
        tail = argv[argv.index("-") + 1:]
        self.assertEqual(tail[:2], [ws.CHROMIUM, "https://www.klant.nl/?whp_shot=1"])
        self.assertEqual(tail[3:7], ["390", "844", "1", "www.klant.nl"])
        self.assertIn("iPhone", tail[7])
        self.assertIn("remote-debugging-pipe", code)

    def test_a_failed_screenshot_is_reported(self):
        with tempfile.TemporaryDirectory() as tmp, mock.patch.object(ws, "WORK", Path(tmp) / "shots"), \
                mock.patch.object(ws, "run", return_value=mock.Mock(returncode=1, stdout='{"ok": false, "error": "The page could not be opened: net::ERR_CONNECTION_REFUSED"}', stderr="")), \
                mock.patch.object(ws.shutil, "chown"):
            err = ws.shoot("https://klant.nl/", "desktop", Path(tmp) / "x.png")
        self.assertIn("ERR_CONNECTION_REFUSED", err)


class WorkerTest(unittest.TestCase):
    """shot_worker.py against a fake Chromium that speaks the DevTools pipe protocol."""

    FAKE = r"""#!/usr/bin/env python3
import base64, json, os, sys
PNG = base64.b64encode(b"\x89PNG fake").decode()
buf = b""
while True:
    chunk = os.read(3, 65536)
    if not chunk:
        break
    buf += chunk
    while b"\0" in buf:
        raw, buf = buf.split(b"\0", 1)
        m = json.loads(raw)
        res = {}
        if m["method"] == "Target.createTarget": res = {"targetId": "T"}
        if m["method"] == "Target.attachToTarget": res = {"sessionId": "S"}
        if m["method"] == "Page.navigate":
            os.write(4, json.dumps({"method": "Page.loadEventFired", "sessionId": "S", "params": {}}).encode() + b"\0")
            res = {"frameId": "F"}
        if m["method"] == "Page.getLayoutMetrics": res = {"cssContentSize": {"height": 9000}}
        if m["method"] == "Page.captureScreenshot":
            res = {"data": PNG}
            open(os.environ["HOME"] + "/clip.json", "w").write(json.dumps(m["params"]["clip"]))
        os.write(4, json.dumps({"id": m["id"], "result": res}).encode() + b"\0")
        if m["method"] == "Browser.close": sys.exit(0)
"""

    def test_worker_drives_the_pipe_and_caps_the_height(self):
        import json
        import subprocess

        with tempfile.TemporaryDirectory() as tmp:
            fake = Path(tmp) / "chromium"
            fake.write_text(self.FAKE)
            fake.chmod(0o755)
            out = Path(tmp) / "shot.png"
            proc = subprocess.run([sys.executable, "-I", "-", str(fake), "https://klant.nl/", str(out), "1366", "2000", "0", "klant.nl"],
                                  input=ws.WORKER.read_text(), capture_output=True, text=True, timeout=60, env={"HOME": tmp, "PATH": "/usr/bin:/bin"})
            self.assertEqual(json.loads(proc.stdout.strip()), {"ok": True, "height": 5000}, proc.stderr)
            self.assertEqual(out.read_bytes(), b"\x89PNG fake")
            self.assertEqual(json.loads((Path(tmp) / "clip.json").read_text())["height"], 5000)


if __name__ == "__main__":
    unittest.main()
