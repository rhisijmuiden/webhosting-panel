#!/usr/bin/env python3
"""Client databases end to end on a real server: create, extra users, password reset,
phpMyAdmin single sign-on (one-time token), drop. Run as root by tests/smoke.sh."""
import http.cookiejar
import json
import os
import ssl
import subprocess
import sys
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
DOMAIN = "dbsmoke.test"


def agent(action, params=None, ok=True):
    p = subprocess.run([sys.executable, os.path.join(HERE, "agent_call.py"), action, json.dumps(params or {})],
                       capture_output=True, text=True)
    if (p.returncode == 0) != ok:
        sys.exit(f"{action}: expected {'success' if ok else 'failure'}, got {p.stdout} {p.stderr}")
    return json.loads(p.stdout or "null")


def login(user, password, sql="SELECT 1"):
    return subprocess.run(["mariadb", "--protocol=socket", "-u", user, f"-p{password}", "-e", sql],
                          capture_output=True, text=True).returncode == 0


created = agent("db.create", {"domain": DOMAIN, "name": "shop"})
name = created["name"]
assert name == "dbsmoke_test_shop", created
assert login(name, created["password"], f"CREATE TABLE {name}.t (id INT)"), "main user cannot create a table"
agent("db.create", {"domain": DOMAIN, "name": "shop"}, ok=False)  # name taken

ro = agent("db.user_create", {"domain": DOMAIN, "name": "report", "databases": [name], "readonly": True})
assert login(ro["user"], ro["password"], f"SELECT * FROM {name}.t"), "read-only user cannot read"
assert not login(ro["user"], ro["password"], f"CREATE TABLE {name}.x (id INT)"), "read-only user could write"
new = agent("db.password", {"domain": DOMAIN, "user": ro["user"], "password": "Smoke 'quoted' \\ pass"})
assert login(ro["user"], new["password"]), "new password does not work"
assert not login(ro["user"], ro["password"]), "old password still works"
agent("db.password", {"domain": "other.test", "user": ro["user"]}, ok=False)

listed = agent("db.list", {"domain": DOMAIN})
assert [d["name"] for d in listed["databases"]] == [name], listed
assert [u["user"] for u in listed["databases"][0]["users"]] == [name, ro["user"]], listed

# phpMyAdmin: the token logs in once, straight into the database.
sso = agent("db.sso", {"domain": DOMAIN, "name": name, "lang": "nl"})
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx), urllib.request.HTTPCookieProcessor(jar))
url = f"https://127.0.0.1:8443{sso['path']}?token={sso['token']}"
with opener.open(url, timeout=60) as r:
    page = r.read().decode(errors="replace")
    final = r.geturl()
assert "/phpmyadmin/index.php" in final, final
assert name in page and 'name="pma_username"' not in page, page[:2000]
try:
    urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx)).open(url, timeout=30)
    sys.exit("a used token worked a second time")
except urllib.error.HTTPError as e:
    assert e.code == 403, e.code
# The rest of phpMyAdmin is not reachable without the panel.
try:
    urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx)).open("https://127.0.0.1:8443/phpmyadmin/libraries/classes/Config.php", timeout=30)
    sys.exit("phpMyAdmin internals are reachable")
except urllib.error.HTTPError as e:
    assert e.code in (403, 404), e.code

dropped = agent("db.drop", {"domain": DOMAIN, "name": name})
assert dropped["dropped_users"] == [ro["user"]], dropped
assert not login(name, created["password"]), "main user still exists"
print("ok")
