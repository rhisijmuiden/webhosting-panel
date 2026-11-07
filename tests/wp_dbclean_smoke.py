"""End to end check of the WordPress database cleanup on a real install (run by
tests/smoke.d/wp-dbclean.sh as root, after wp_smoke.py made wp.test).

Usage: python3 tests/wp_dbclean_smoke.py wp.test
"""

import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))

from whp_agent import jobs, wordpress  # noqa: E402
from whp_agent.actions import wp_dbclean  # noqa: E402

domain = sys.argv[1]


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


def count(sql):
    out = wordpress.wp(domain, ["db", "query", sql, "--skip-column-names"], timeout=60).stdout.strip().splitlines()
    return int(out[-1]) if out else 0


# Something to clean: revisions, a trashed page, spam, an expired and a fresh transient, an orphaned meta row.
wordpress.wp(domain, ["eval", r"""
$id = wp_insert_post(['post_title' => 'Cleanup smoke', 'post_content' => 'v0', 'post_status' => 'publish', 'post_type' => 'page']);
for ($i = 1; $i <= 5; $i++) { wp_update_post(['ID' => $id, 'post_content' => str_repeat('v', 500 * $i)]); }
$t = wp_insert_post(['post_title' => 'Old page', 'post_status' => 'publish', 'post_type' => 'page']); wp_trash_post($t);
for ($i = 0; $i < 3; $i++) { wp_insert_comment(['comment_post_ID' => $id, 'comment_author' => "spam$i", 'comment_content' => 'x', 'comment_approved' => 'spam']); }
set_transient('whp_smoke_old', 'x', 1); set_transient('whp_smoke_fresh', 'x', 3600);
global $wpdb; $wpdb->insert($wpdb->postmeta, ['post_id' => 987654321, 'meta_key' => 'whp_orphan', 'meta_value' => '1']);
echo 'seeded';
"""], timeout=120)
time.sleep(2)

p = wp_dbclean.wp_dbclean_preview({"domain": domain, "keep_revisions": 2})
items = p["items"]
step("preview counts revisions beyond the 2 newest", items["revisions"]["count"] >= 3, items["revisions"])
step("preview sees the trashed page, spam and the orphaned row",
     items["trashed_posts"]["count"] >= 1 and items["spam_comments"]["count"] >= 3 and items["orphaned_meta"]["count"] >= 1, items)
step("preview sees the expired transient", items["expired_transients"]["count"] >= 1, items["expired_transients"])
step("preview changes nothing", count("SELECT COUNT(*) FROM wp_comments WHERE comment_approved='spam'") >= 3)

state = wait(wp_dbclean.wp_dbclean_run({"domain": domain, "keep_revisions": 2}))
step("cleanup job succeeds", state["status"] == "succeeded", state.get("error"))
res = state["result"]
step("a snapshot was made first", bool(res["snapshot"]) and os.path.isfile(
    os.path.join(str(wp_dbclean.toolkit.SNAPSHOTS), wp_dbclean.toolkit._key(domain, ""), res["snapshot"], "database.sql")), res)
step("revisions removed, the 2 newest kept",
     count("SELECT COUNT(*) FROM wp_posts p JOIN wp_posts r ON r.post_parent = p.ID AND r.post_type='revision' WHERE p.post_title='Cleanup smoke'") == 2)
step("spam and trash are gone", count("SELECT COUNT(*) FROM wp_comments WHERE comment_approved='spam'") == 0
     and count("SELECT COUNT(*) FROM wp_posts WHERE post_status='trash'") == 0)
step("orphaned meta is gone", count("SELECT COUNT(*) FROM wp_postmeta WHERE post_id=987654321") == 0)
step("the expired transient is gone, the fresh one stays",
     count("SELECT COUNT(*) FROM wp_options WHERE option_name='_transient_whp_smoke_old'") == 0
     and count("SELECT COUNT(*) FROM wp_options WHERE option_name='_transient_whp_smoke_fresh'") == 1)
step("tables were optimized", res["removed"].get("optimize", 0) >= 1, res["removed"])
step("the site still answers", 200 <= res["health"]["after"] < 500, res["health"])
print("database cleanup smoke test passed")
