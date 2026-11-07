"""Take one screenshot of a web page with headless Chromium (smart WordPress updates).

Runs as the unprivileged user whp-shot in a sandboxed transient unit started by
actions/wp_smart.py, with python3 -I and the standard library only. It talks to
Chromium over the DevTools protocol on a pipe (--remote-debugging-pipe, fd 3 and
4), so it can set the viewport, wait for the page to load, stop animations and
capture the whole page, and it never hangs: everything has a deadline.

Usage: shot_worker.py CHROMIUM URL OUT.png WIDTH HEIGHT MOBILE(0|1) RESOLVE_HOST [USER_AGENT]
Prints one JSON line: {"ok": true, "height": ...} or {"ok": false, "error": "..."}.
"""

import base64
import json
import os
import select
import signal
import subprocess
import sys
import time

MAX_HEIGHT = 5000
LOAD_TIMEOUT = 30
STILL_CSS = ("*,*::before,*::after{animation:none!important;transition:none!important;"
             "caret-color:transparent!important;scroll-behavior:auto!important}")


class Cdp:
    def __init__(self, proc, write_fd, read_fd):
        self.proc, self.w, self.r = proc, write_fd, read_fd
        self.buf = b""
        self.next_id = 0
        self.events = []

    def _read(self, deadline):
        while b"\0" not in self.buf:
            left = deadline - time.monotonic()
            if left <= 0:
                raise TimeoutError("Chromium did not answer in time")
            ready, _, _ = select.select([self.r], [], [], left)
            if ready:
                chunk = os.read(self.r, 1 << 20)
                if not chunk:
                    raise RuntimeError("Chromium stopped")
                self.buf += chunk
        raw, self.buf = self.buf.split(b"\0", 1)
        return json.loads(raw)

    def send(self, method, params=None, session=None, timeout=30):
        self.next_id += 1
        msg = {"id": self.next_id, "method": method, "params": params or {}}
        if session:
            msg["sessionId"] = session
        os.write(self.w, json.dumps(msg).encode() + b"\0")
        deadline = time.monotonic() + timeout
        while True:
            m = self._read(deadline)
            if m.get("id") == self.next_id:
                if "error" in m:
                    raise RuntimeError(f"{method}: {m['error'].get('message')}")
                return m.get("result", {})
            self.events.append(m)

    def wait_event(self, method, timeout):
        deadline = time.monotonic() + timeout
        for i, m in enumerate(self.events):
            if m.get("method") == method:
                del self.events[i]
                return m
        while True:
            m = self._read(deadline)
            if m.get("method") == method:
                return m
            self.events.append(m)


def shoot(chromium, url, out, width, height, mobile, host, agent):
    cmd_r, cmd_w = os.pipe()
    res_r, res_w = os.pipe()
    bare = host[4:] if host.startswith("www.") else host

    def child():
        # Chromium reads commands on fd 3 and writes answers on fd 4. Move both ends
        # out of the way first (one of them may already be 3 or 4), then put them in
        # place as inheritable fds; close_fds stays off below for the same reason.
        a, b = os.dup(cmd_r), os.dup(res_w)
        a, b = os.dup2(a, 30), os.dup2(b, 31)
        os.dup2(a, 3, inheritable=True)
        os.dup2(b, 4, inheritable=True)
        for fd in {cmd_r, cmd_w, res_r, res_w, a, b} - {3, 4}:
            try:
                os.close(fd)
            except OSError:
                pass
        os.setsid()

    argv = [chromium, "--headless", "--remote-debugging-pipe", "--no-sandbox", "--disable-gpu",
            "--hide-scrollbars", "--mute-audio", "--no-first-run", "--no-default-browser-check",
            "--disable-extensions", "--disable-dev-shm-usage", "--disable-background-networking",
            "--disable-component-update", "--disable-sync", "--disable-default-apps", "--ignore-certificate-errors",
            f"--host-resolver-rules=MAP {bare} 127.0.0.1, MAP www.{bare} 127.0.0.1",
            f"--user-data-dir={os.environ.get('HOME', '/tmp')}/profile", "about:blank"]
    proc = subprocess.Popen(argv, preexec_fn=child, close_fds=False, stdin=subprocess.DEVNULL,
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    os.close(cmd_r)
    os.close(res_w)
    cdp = Cdp(proc, cmd_w, res_r)
    try:
        target = cdp.send("Target.createTarget", {"url": "about:blank"})["targetId"]
        s = cdp.send("Target.attachToTarget", {"targetId": target, "flatten": True})["sessionId"]
        cdp.send("Page.enable", session=s)
        cdp.send("Emulation.setDeviceMetricsOverride", {"width": width, "height": height, "deviceScaleFactor": 1,
                                                        "mobile": mobile}, session=s)
        if agent:
            cdp.send("Emulation.setUserAgentOverride", {"userAgent": agent}, session=s)
        cdp.send("Page.addScriptToEvaluateOnNewDocument", {"source": (
            "document.addEventListener('DOMContentLoaded',function(){var s=document.createElement('style');"
            f"s.textContent={json.dumps(STILL_CSS)};document.documentElement.appendChild(s);}});")}, session=s)
        nav = cdp.send("Page.navigate", {"url": url}, session=s, timeout=LOAD_TIMEOUT)
        if nav.get("errorText"):
            raise RuntimeError(f"The page could not be opened: {nav['errorText']}")
        try:
            cdp.wait_event("Page.loadEventFired", LOAD_TIMEOUT)
        except TimeoutError:
            pass  # a page that keeps loading: take what is there
        time.sleep(1.5)  # late images, fonts and scripts
        metrics = cdp.send("Page.getLayoutMetrics", session=s)
        size = metrics.get("cssContentSize") or metrics.get("contentSize") or {}
        full = int(min(MAX_HEIGHT, max(height, size.get("height", height))))
        shot = cdp.send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True,
                                                   "clip": {"x": 0, "y": 0, "width": width, "height": full, "scale": 1}},
                        session=s, timeout=60)
        with open(out, "wb") as fh:
            fh.write(base64.b64decode(shot["data"]))
        return {"ok": True, "height": full}
    finally:
        try:
            cdp.send("Browser.close", timeout=5)
        except Exception:
            pass
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(proc.pid, signal.SIGKILL)
            proc.wait(timeout=5)


def main(argv):
    if len(argv) not in (8, 9):
        print(json.dumps({"ok": False, "error": "usage"}))
        return 2
    chromium, url, out, width, height, mobile, host = argv[1:8]
    agent = argv[8] if len(argv) == 9 else None
    try:
        res = shoot(chromium, url, out, int(width), int(height), mobile == "1", host, agent)
    except Exception as exc:  # report, never hang or trace
        res = {"ok": False, "error": str(exc)[:300] or exc.__class__.__name__}
    print(json.dumps(res))
    return 0 if res["ok"] else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
