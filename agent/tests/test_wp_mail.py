"""wp.mail_*: settings file, log parsing, apply/remove and the test message, with
WP-CLI and the site user mocked out. The real path (WordPress -> Postfix loopback
service -> Rspamd DKIM -> mailbox) runs in the Debian 13 smoke test (tests/wp_mail_smoke.py).
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from subprocess import CompletedProcess
from unittest import mock

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from whp_agent.actions import site_clone  # noqa: E402
from whp_agent.actions import wp_mail as wm  # noqa: E402
from whp_agent.actions import wp_staging  # noqa: E402
from whp_agent.registry import ActionError  # noqa: E402


class HelperTest(unittest.TestCase):
    def test_config_php_round_trip_and_no_php_injection(self):
        cfg = {"from_email": "noreply@klant.nl", "from_name": "'); system('id'); //", "domains": ["klant.nl"]}
        php = wm.config_php(cfg)
        self.assertNotIn("system(", php)
        self.assertIn("defined('ABSPATH') || exit;", php)
        self.assertEqual(wm.parse_config(php), cfg)
        self.assertIsNone(wm.parse_config("<?php return [];"))
        self.assertIsNone(wm.parse_config(None))

    def test_email_validation(self):
        self.assertEqual(wm.email({"to": "Jan@Klant.NL"}, "to"), "Jan@klant.nl")
        for bad in ("jan", "jan@", "a b@klant.nl", "jan@klant", "x@-bad.nl"):
            with self.assertRaises(ActionError, msg=bad):
                wm.email({"to": bad}, "to")

    def test_build_config_binds_the_install_folder(self):
        site = {"domain": "klant.nl", "root": "/var/www/vhosts/klant.nl"}
        cfg = wm.build_config(site, "/var/www/vhosts/klant.nl/public/blog", "blog", "info@shop.nl", "Klant", True, ["shop.nl"])
        self.assertEqual(cfg["abspath"], os.path.realpath("/var/www/vhosts/klant.nl/public/blog"))
        self.assertEqual(cfg["site"], "klant.nl/blog")
        self.assertEqual(cfg["domains"], ["klant.nl", "shop.nl"])
        self.assertEqual(cfg["port"], wm.SMTP_PORT)
        self.assertEqual(cfg["log"], "/var/www/vhosts/klant.nl/logs/wp-mail.log")

    def test_parse_log_newest_first_per_install(self):
        lines = [
            json.dumps({"t": 1, "install": "", "ok": True, "to": ["a@x.nl"], "subject": "one", "queue": "Q1"}),
            "garbage",
            json.dumps({"t": 2, "install": "blog", "ok": True, "to": ["b@x.nl"], "subject": "blog"}),
            json.dumps({"t": 3, "install": "", "ok": False, "to": ["c@x.nl"], "subject": "two", "error": "SMTP connect() failed"}),
        ]
        out = wm.parse_log("\n".join(lines), "")
        self.assertEqual([e["time"] for e in out], [3, 1])
        self.assertEqual(out[0]["error"], "SMTP connect() failed")
        self.assertEqual(out[1]["queue_id"], "Q1")
        self.assertEqual(len(wm.parse_log("\n".join(lines * 40), "", limit=5)), 5)

    def test_parse_test_output_skips_notices(self):
        out = wm.parse_test_output('PHP Notice: something\n{"ok":true,"queue_id":"ABC","routed":true}\n')
        self.assertEqual(out["queue_id"], "ABC")
        with self.assertRaises(ActionError):
            wm.parse_test_output("Fatal error: x")

    def test_clone_and_staging_push_leave_mail_settings_out(self):
        self.assertIn("wp-content/mu-plugins/whp-mail.php", site_clone.EXCLUDES)
        self.assertIn("wp-content/mu-plugins/whp-mail/", site_clone.EXCLUDES)
        argv = wp_staging.rsync_push_argv("/s", "/l", "u", False)
        self.assertIn("--exclude=/wp-content/mu-plugins/whp-mail.php", argv)
        self.assertIn("--exclude=/wp-content/mu-plugins/whp-mail/", argv)

    def test_plugin_code_is_bound_and_routes_through_loopback(self):
        code = Path(wm.PLUGIN_SRC).read_text()
        self.assertIn("realpath(ABSPATH)", code)
        self.assertIn("defined('WHP_STAGING')", code)
        self.assertIn("'127.0.0.1'", code)
        self.assertIn("PHP_INT_MAX", code)
        self.assertIn("addReplyTo", code)


@unittest.skipUnless(shutil.which("php"), "php not installed")
class PluginPhpTest(unittest.TestCase):
    """Run the mu-plugin against a stub PHPMailer: sender rewrite, Reply-To, SMTP settings, copies."""

    HARNESS = r"""<?php
define('ABSPATH', getenv('WP_DIR') . '/');
$hooks = [];
function add_action($h, $cb, $prio = 10) { global $hooks; $hooks[$h][] = $cb; }
class Mailer {
    public $From = ''; public $FromName = ''; public $Sender = ''; public $Mailer = 'mail'; public $Host = ''; public $Port = 25;
    public $SMTPAuth = true; public $Username = 'x'; public $Password = 'y'; public $SMTPSecure = 'tls'; public $SMTPAutoTLS = true;
    public $SMTPOptions = ['x' => 1]; public $Timeout = 300; public $action_function = ''; public $reply = []; public $headers = [];
    public function isSMTP() { $this->Mailer = 'smtp'; }
    public function getReplyToAddresses() { return $this->reply; }
    public function addReplyTo($a, $n = '') { $this->reply[$a] = [$a, $n]; }
    public function addCustomHeader($n, $v) { $this->headers[] = [$n, $v]; }
}
require getenv('PLUGIN');
$out = [];
foreach (json_decode(getenv('CASES'), true) as [$from, $name]) {
    $m = new Mailer(); $m->From = $from; $m->FromName = $name;
    foreach ($hooks['phpmailer_init'] ?? [] as $cb) { $cb($m); }
    if (is_callable($m->action_function)) { ($m->action_function)(true, [['to@x.nl', '']], [], [], 'Hello', 'body', $m->From, ['smtp_transaction_id' => 'QID1']); }
    $out[] = ['from' => $m->From, 'name' => $m->FromName, 'sender' => $m->Sender, 'mailer' => $m->Mailer, 'host' => $m->Host . ':' . $m->Port,
              'auth' => $m->SMTPAuth, 'tls' => $m->SMTPAutoTLS, 'reply' => array_keys($m->reply), 'headers' => $m->headers];
}
echo json_encode(['routed' => defined('WHP_MAIL'), 'cases' => $out]);
"""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        self.wp = base / "public"
        (self.wp / "wp-content" / "mu-plugins" / "whp-mail").mkdir(parents=True)
        self.plugin = self.wp / wm.PLUGIN
        self.plugin.write_text(Path(wm.PLUGIN_SRC).read_text())
        (base / "logs").mkdir()
        self.log = base / "logs" / "wp-mail.log"
        self.harness = base / "harness.php"
        self.harness.write_text(self.HARNESS)
        self.cfg = {"abspath": os.path.realpath(self.wp), "site": "klant.nl", "path": "", "from_email": "noreply@klant.nl",
                    "from_name": "Klant BV", "force_from": True, "domains": ["klant.nl", "shop.nl"], "port": 10587, "log": str(self.log)}

    def tearDown(self):
        self.tmp.cleanup()

    def run_php(self, cases, wp_dir=None):
        (self.wp / wm.CONFIG).write_text(wm.config_php(self.cfg))
        env = dict(os.environ, WP_DIR=str(wp_dir or self.wp), PLUGIN=str(self.plugin), CASES=json.dumps(cases))
        proc = subprocess.run(["php", "-n", str(self.harness)], env=env, capture_output=True, text=True, timeout=30)
        self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)
        return json.loads(proc.stdout)

    def test_sender_rewrite_reply_to_and_loopback_smtp(self):
        out = self.run_php([["wordpress@klant.nl", "WordPress"], ["visitor@gmail.com", "Visitor"], ["orders@shop.nl", "Shop"]])
        self.assertTrue(out["routed"])
        default, visitor, own = out["cases"]
        self.assertEqual((default["from"], default["name"], default["sender"]), ("noreply@klant.nl", "Klant BV", "noreply@klant.nl"))
        self.assertEqual((visitor["from"], visitor["name"], visitor["reply"]), ("noreply@klant.nl", "Visitor", ["visitor@gmail.com"]))
        self.assertEqual((own["from"], own["sender"], own["reply"]), ("orders@shop.nl", "orders@shop.nl", []))
        for c in out["cases"]:
            self.assertEqual((c["mailer"], c["host"], c["auth"], c["tls"]), ("smtp", "127.0.0.1:10587", False, False))
            self.assertIn(["X-Website", "klant.nl"], c["headers"])
        entries = [json.loads(line) for line in self.log.read_text().splitlines()]
        self.assertEqual(len(entries), 3)
        self.assertEqual(entries[0]["queue"], "QID1")
        self.assertEqual(entries[0]["to"], ["to@x.nl"])

    def test_force_from_off_keeps_foreign_sender(self):
        self.cfg["force_from"] = False
        out = self.run_php([["visitor@gmail.com", "Visitor"]])
        self.assertEqual(out["cases"][0]["from"], "visitor@gmail.com")
        self.assertEqual(out["cases"][0]["mailer"], "smtp")

    def test_copy_in_another_folder_does_nothing(self):
        other = Path(self.tmp.name) / "copy"
        shutil.copytree(self.wp, other)
        self.plugin = other / wm.PLUGIN
        (other / wm.CONFIG).write_text(wm.config_php(self.cfg))  # still bound to the original folder
        env = dict(os.environ, WP_DIR=str(other), PLUGIN=str(self.plugin), CASES=json.dumps([["wordpress@copy.nl", "WordPress"]]))
        proc = subprocess.run(["php", "-n", str(self.harness)], env=env, capture_output=True, text=True, timeout=30)
        out = json.loads(proc.stdout)
        self.assertFalse(out["routed"])
        self.assertEqual(out["cases"][0]["mailer"], "mail")
        self.assertEqual(out["cases"][0]["from"], "wordpress@copy.nl")


class ActionTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        root = base / "vhosts" / "klant.nl"
        self.doc = root / "public"
        (self.doc / "wp-includes").mkdir(parents=True)
        (self.doc / "wp-content").mkdir()
        (self.doc / "wp-config.php").write_text("<?php")
        (self.doc / "wp-includes" / "version.php").write_text("<?php\n$wp_version = '6.8.1';\n")
        (root / "logs").mkdir()
        self.site = {"domain": "klant.nl", "id": "7", "user": "s_klant_nl", "php": "8.4", "root": str(root),
                     "docroot": str(self.doc), "ssl": "active", "aliases": [], "suspended": False, "slice": "whp-sites.slice"}
        self.dkim = base / "dkim"
        self.dkim.mkdir()
        (self.dkim / "klant.nl.whp.key").write_text("key")
        self.calls = []

        def as_user(site, argv, cwd, input_text=None, timeout=300, check=True):
            # Do what the shell line would do, in the temp folder.
            self.calls.append(argv)
            if argv[0] == "/bin/rm":
                for rel in argv[3:]:
                    p = Path(cwd) / rel
                    shutil.rmtree(p) if p.is_dir() else p.unlink(missing_ok=True)
            else:
                script = argv[2]
                target = script.split("cat > ")[1].split(".tmp")[0]
                if "mkdir -p" in script:
                    (Path(cwd) / wm.CONFIG_DIR).mkdir(parents=True, exist_ok=True)
                (Path(cwd) / target).parent.mkdir(parents=True, exist_ok=True)
                (Path(cwd) / target).write_text(input_text)
            return CompletedProcess(argv, 0, "", "")

        self.patches = [
            mock.patch.object(wm.wordpress, "site_info", return_value=self.site),
            mock.patch.object(wm.wordpress, "as_user", side_effect=as_user),
            mock.patch.object(wm, "smtp_available", return_value=True),
            mock.patch.object(wm, "DKIM_DIR", str(self.dkim)),
        ]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()
        self.tmp.cleanup()

    def test_apply_status_and_remove(self):
        out = wm.mail_apply({"domain": "klant.nl", "enabled": True, "from_name": "Klant", "domains": ["shop.nl"]})
        self.assertTrue(out["enabled"])
        self.assertTrue(out["plugin_current"])
        self.assertEqual(out["from_email"], "noreply@klant.nl")
        self.assertEqual(out["dkim"], {"klant.nl": True, "shop.nl": False})
        cfg = wm.parse_config((self.doc / wm.CONFIG).read_text())
        self.assertEqual(cfg["abspath"], os.path.realpath(self.doc))
        (Path(self.site["root"]) / "logs" / "wp-mail.log").write_text(json.dumps({"t": 5, "install": "", "ok": True, "to": ["x@y.nl"]}) + "\n")
        self.assertEqual(wm.mail_status({"domain": "klant.nl"})["log"][0]["to"], ["x@y.nl"])

        out = wm.mail_apply({"domain": "klant.nl", "enabled": False})
        self.assertFalse(out["enabled"])
        self.assertFalse((self.doc / wm.PLUGIN).exists())
        self.assertFalse((self.doc / wm.CONFIG_DIR).exists())

    def test_sender_must_be_an_own_domain(self):
        with self.assertRaises(ActionError) as e:
            wm.mail_apply({"domain": "klant.nl", "enabled": True, "from_email": "info@gmail.com"})
        self.assertEqual(e.exception.code, "invalid_params")

    def test_staging_copy_is_never_switched_on(self):
        (self.doc / "wp-content" / "mu-plugins").mkdir()
        (self.doc / wm.STAGING_PLUGIN).write_text("<?php")
        with self.assertRaises(ActionError) as e:
            wm.mail_apply({"domain": "klant.nl", "enabled": True})
        self.assertEqual(e.exception.code, "conflict")
        self.assertTrue(wm.mail_status({"domain": "klant.nl"})["staging"])

    def test_no_website_smtp_service_refuses(self):
        with mock.patch.object(wm, "smtp_available", return_value=False):
            with self.assertRaises(ActionError) as e:
                wm.mail_apply({"domain": "klant.nl", "enabled": True})
        self.assertIn("10587", str(e.exception))

    def test_copied_settings_count_as_off(self):
        (self.doc / wm.CONFIG_DIR).mkdir(parents=True)
        (self.doc / wm.PLUGIN).write_text(Path(wm.PLUGIN_SRC).read_text())
        (self.doc / wm.CONFIG).write_text(wm.config_php({"abspath": "/var/www/vhosts/other.nl/public", "from_email": "x@other.nl"}))
        out = wm.mail_status({"domain": "klant.nl"})
        self.assertFalse(out["enabled"])
        self.assertTrue(out["copied"])

    def test_test_message_runs_wp_mail_with_plugins(self):
        result = '{"ok":true,"error":"","queue_id":"4Xk","from":"noreply@klant.nl","mailer":"smtp","routed":true,"staging":false}'
        with mock.patch.object(wm.wordpress, "wp", return_value=CompletedProcess([], 0, "notice\n" + result + "\n", "")) as wp:
            out = wm.mail_test({"domain": "klant.nl", "to": "jan@example.org"})
        self.assertTrue(out["ok"])
        self.assertEqual(out["queue_id"], "4Xk")
        args, kwargs = wp.call_args
        self.assertEqual(args[1][:3], ["eval-file", "-", "jan@example.org"])
        self.assertTrue(kwargs["plugins"])
        self.assertIn("wp_mail(", kwargs["input_text"])


if __name__ == "__main__":
    unittest.main()
