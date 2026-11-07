"""End to end check of WordPress search & replace and change domain on a real install
(run by tests/smoke.d/wp-search-replace.sh as root, on the wp.test install of wp_smoke.py).

Usage: python3 tests/wp_search_replace_smoke.py wp.test wpsr.test
"""

import json
import os
import subprocess
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import jobs, wordpress  # noqa: E402
from whp_agent.actions import wordpress as wpa  # noqa: E402
from whp_agent.actions import wp_search_replace as sr  # noqa: E402

domain, alias = sys.argv[1], sys.argv[2]


def step(name, ok, detail=""):
    print(("ok   " if ok else "FAIL ") + name + (f"  {detail}" if detail and not ok else ""))
    if not ok:
        sys.exit(1)


def wait(job):
    for _ in range(900):
        state = jobs.read(job["job"])
        if state["status"] != "running":
            return state
        time.sleep(1)
    raise SystemExit("job did not finish")


def wp(*args, **kw):
    return wordpress.wp(domain, list(args), timeout=300, **kw).stdout.strip()


def option_json(name):
    return json.loads(wp("option", "get", name, "--format=json"))


home = wp("option", "get", "home")
step("starts on its own address", home == f"http://{domain}", home)
post = wp("post", "create", "--post_status=publish", "--post_title=SR smoke", "--porcelain",
          f'--post_content=Welkom bij Smoke SR Bedrijf. <a href="http://{domain}/contact">Contact</a> '
          f'<a href="https://www.{domain}/x">www</a> <a href="http://{domain}.au/">ander</a>')
wp("option", "update", "whp_sr_test", json.dumps({"url": f"http://{domain}/logo.png", "n": 1}), "--format=json")
wp("post", "meta", "update", post, "whp_sr_json", json.dumps({"img": f"http://{domain}/img.png"}))
step("JSON meta is stored escaped", "\\/" in wp("post", "meta", "get", post, "whp_sr_json"))

# --- text search & replace
params = {"domain": domain, "mode": "text", "search": "Smoke SR Bedrijf", "replace": "Nieuw Bedrijf BV"}
state = wait(sr.wp_sr_preview(params))
step("text preview succeeds", state["status"] == "succeeded", json.dumps(state)[-1500:])
res = state["result"]
step("preview counts the post", res["total"] >= 1 and any(t["table"].endswith("posts") for t in res["tables"]), res)
step("preview shows a sample", any("Nieuw Bedrijf BV" in s["new"] and "Smoke SR Bedrijf" in s["old"] for s in res["samples"]), res["samples"])
step("dry run changed nothing", "Smoke SR Bedrijf" in wp("post", "get", post, "--field=post_content"))
step("no log file left behind", not [f for f in os.listdir(os.path.join(wordpress.site_info(domain)["root"], "tmp")) if f.startswith("whp-sr-")])

state = wait(sr.wp_sr_apply(params))
step("text apply succeeds", state["status"] == "succeeded", json.dumps(state)[-1500:])
step("text replaced", "Nieuw Bedrijf BV" in wp("post", "get", post, "--field=post_content"))
step("snapshot made", state["result"]["snapshot"] and any(s["id"] == state["result"]["snapshot"]
                                                            for s in wpa.wp_snapshots({"domain": domain})["snapshots"]), state["result"])

# --- change domain to an alias of the website
proc = subprocess.run(["whp-site", "alias", "add", domain, alias, "--no-ssl", "--json"], capture_output=True, text=True, timeout=300)
step("alias added", proc.returncode == 0, proc.stderr)
info = sr.wp_sr_addresses({"domain": domain})
step("alias is offered as address", alias in [h["host"] for h in info["hosts"]], info)

dparams = {"domain": domain, "mode": "domain", "host": alias, "scheme": "http"}
state = wait(sr.wp_sr_preview(dparams))
step("domain preview succeeds", state["status"] == "succeeded", json.dumps(state)[-1500:])
step("domain preview counts links", state["result"]["total"] >= 4 and state["result"]["new_home"] == f"http://{alias}", state["result"])

state = wait(sr.wp_sr_apply(dparams))
step("domain change succeeds", state["status"] == "succeeded", json.dumps(state)[-2500:])
step("site still answers", state["result"]["health"]["after"] == 200, state["result"]["health"])
step("home and siteurl moved", wp("option", "get", "home") == f"http://{alias}" and wp("option", "get", "siteurl") == f"http://{alias}")
content = wp("post", "get", post, "--field=post_content")
step("links in content moved (also www and https)", f'href="http://{alias}/contact"' in content and f'href="http://{alias}/x"' in content, content)
step("another domain that only starts the same is left alone", f"http://{domain}.au/" in content, content)
step("serialized option still unserializes with the new address", option_json("whp_sr_test") == {"url": f"http://{alias}/logo.png", "n": 1})
meta = wp("post", "meta", "get", post, "whp_sr_json")
step("JSON-escaped address moved", json.loads(meta) == {"img": f"http://{alias}/img.png"}, meta)
code = subprocess.run(["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "--resolve", f"{alias}:80:127.0.0.1", f"http://{alias}/"],
                      capture_output=True, text=True, timeout=60).stdout
step("website answers on the new address", code == "200", code)

state = wait(sr.wp_sr_apply({"domain": domain, "mode": "domain", "host": domain, "scheme": "http"}))
step("change back to the main domain", state["status"] == "succeeded" and wp("option", "get", "home") == f"http://{domain}",
     json.dumps(state)[-1500:])

state = wait(sr.wp_sr_preview({"domain": domain, "mode": "domain", "host": "elders.test", "scheme": "http"}))
step("a domain the site does not serve is refused", state["status"] == "failed" and state["error"]["code"] == "forbidden", state)

wp("post", "delete", post, "--force")
wp("option", "delete", "whp_sr_test")
subprocess.run(["whp-site", "alias", "remove", domain, alias, "--json"], capture_output=True, timeout=300)
print("search & replace smoke done")
