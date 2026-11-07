"""WordPress mail through the mail server of this server (mu-plugin whp-mail).

    wp.mail_status {domain, path?}      is it on, with which sender, DKIM keys, recent mail
    wp.mail_apply  {domain, path?, enabled, from_email?, from_name?, force_from?, domains?}
                                        install/update or remove the mu-plugin
    wp.mail_test   {domain, path?, to, subject?, body?}
                                        send a test message with wp_mail() and say what happened

How it works:

* wp-content/mu-plugins/whp-mail.php (agent/whp_agent/data/whp-mail.php) turns
  WordPress' mailer into SMTP to 127.0.0.1:10587, a Postfix service that only
  listens on loopback (installer/modules/61-website-mail.sh). Rspamd signs the
  message there with the DKIM key of the From domain, like all mail of this server.
  SMTP instead of PHP's sendmail also works where setgid postdrop is blocked
  (WP-CLI and the system WP-cron run with NoNewPrivileges).
* The plugin makes the From address one of the website's own domains (contact
  forms that send "from" the visitor get the visitor as Reply-To), with the
  envelope sender in the same domain, so SPF, DKIM and DMARC line up.
* Its settings live in wp-content/mu-plugins/whp-mail/config.php and are bound
  to the folder of the install: a clone, staging copy or restore somewhere else
  carries the files along, but there they do nothing. A staging copy (mu-plugin
  whp-staging.php) holds all mail back and the plugin is never turned on there.
* Every message is written to <site root>/logs/wp-mail.log (one JSON line, by the
  site's own PHP); wp.mail_status shows the latest lines.
"""

from __future__ import annotations

import base64
import json
import os
import re
import socket
import stat

from .. import validate as v
from .. import wordpress
from ..registry import ActionError, action

PLUGIN_SRC = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data", "whp-mail.php")
PLUGIN = "wp-content/mu-plugins/whp-mail.php"
CONFIG_DIR = "wp-content/mu-plugins/whp-mail"
CONFIG = CONFIG_DIR + "/config.php"
STAGING_PLUGIN = "wp-content/mu-plugins/whp-staging.php"
SMTP_HOST = "127.0.0.1"
SMTP_PORT = int(os.environ.get("WHP_WEBSITE_SMTP_PORT", "10587"))
DKIM_DIR = os.environ.get("WHP_DKIM_DIR", "/var/lib/rspamd/dkim")
LOG_NAME = "wp-mail.log"
MAX_LOG_READ = 256 * 1024
_LOCAL = re.compile(r"^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]{1,64}$")
_CONFIG_B64 = re.compile(r"base64_decode\('([A-Za-z0-9+/=]*)'\)")


# --------------------------------------------------------------------------- pure helpers (unit tested)

def email(params, key, required=True):
    value = v.string(params, key, required=required, max_len=254)
    if value is None:
        return None
    local, _, dom = value.strip().rpartition("@")
    if not _LOCAL.fullmatch(local) or ".." in local or local.startswith(".") or local.endswith("."):
        raise ActionError("invalid_params", f"{key} is not a valid email address")
    try:
        dom = v.domain({"d": dom}, "d")
    except ActionError:
        raise ActionError("invalid_params", f"{key} is not a valid email address")
    return f"{local}@{dom}"


def config_php(cfg: dict) -> str:
    """The settings file: PHP that returns an array (base64 JSON, so nothing in it is ever PHP code)."""
    data = base64.b64encode(json.dumps(cfg, separators=(",", ":"), sort_keys=True).encode()).decode()
    return ("<?php\n// Managed by the hosting panel (website mail). Changes here are overwritten.\n"
            "defined('ABSPATH') || exit;\n"
            f"return json_decode(base64_decode('{data}'), true);\n")


def parse_config(text: str | None) -> dict | None:
    m = _CONFIG_B64.search(text or "")
    if not m:
        return None
    try:
        data = json.loads(base64.b64decode(m.group(1)))
    except ValueError:
        return None
    return data if isinstance(data, dict) else None


def build_config(site: dict, wp_dir: str, path: str, from_email: str, from_name: str, force_from: bool,
                 domains: list[str]) -> dict:
    sender_domain = from_email.rpartition("@")[2]
    allowed = sorted({site["domain"], *domains})
    if sender_domain not in allowed:
        raise ActionError("invalid_params", "from_email must be an address of one of the website's domains")
    return {
        "abspath": os.path.realpath(wp_dir),
        "site": site["domain"] + (f"/{path}" if path else ""),
        "path": path,
        "from_email": from_email,
        "from_name": from_name,
        "force_from": bool(force_from),
        "domains": allowed,
        "port": SMTP_PORT,
        "log": os.path.join(site["root"], "logs", LOG_NAME),
    }


def parse_log(text: str, path: str, limit: int = 30) -> list[dict]:
    """The newest entries of one install, newest first; broken lines are skipped."""
    out = []
    for line in reversed(text.splitlines()):
        try:
            entry = json.loads(line)
        except ValueError:
            continue
        if not isinstance(entry, dict) or entry.get("install", "") != path:
            continue
        out.append({
            "time": int(entry.get("t") or 0),
            "ok": bool(entry.get("ok")),
            "to": [str(a) for a in (entry.get("to") or [])][:20],
            "from": str(entry.get("from") or ""),
            "subject": str(entry.get("subject") or "")[:150],
            "queue_id": str(entry.get("queue") or "") or None,
            "error": str(entry.get("error") or "") or None,
        })
        if len(out) >= limit:
            break
    return out


def smtp_available(host: str = SMTP_HOST, port: int = SMTP_PORT, timeout: float = 3.0) -> bool:
    try:
        with socket.create_connection((host, port), timeout=timeout) as s:
            s.settimeout(timeout)
            return s.recv(4).startswith(b"220")
    except OSError:
        return False


def _read_root(path: str, limit: int) -> str | None:
    """Tail of a regular file, read without following symlinks."""
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return None
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode):
            return None
        if st.st_size > limit:
            os.lseek(fd, st.st_size - limit, os.SEEK_SET)
        with os.fdopen(fd, "rb", closefd=False) as fh:
            return fh.read(limit).decode("utf-8", "replace")
    finally:
        os.close(fd)


def _plugin_code() -> str:
    with open(PLUGIN_SRC, encoding="utf-8") as fh:
        return fh.read()


# --------------------------------------------------------------------------- actions

def _target(params):
    domain = v.domain(params, "domain")
    path = wordpress.clean_path(v.string(params, "path", required=False, default="", max_len=260))
    site, d = wordpress.require_install(domain, path)
    if site["suspended"]:
        raise ActionError("conflict", f"Website {domain} is suspended")
    return site, d, path


def _status(site, d, path) -> dict:
    cfg = parse_config(_read_root(os.path.join(d, CONFIG), 64 * 1024))
    plugin = _read_root(os.path.join(d, PLUGIN), 256 * 1024)
    installed = plugin is not None and cfg is not None
    copied = installed and os.path.realpath(d) != os.path.realpath(str(cfg.get("abspath") or "/nonexistent"))
    domains = sorted({site["domain"], *((cfg or {}).get("domains") or [])})
    log = _read_root(os.path.join(site["root"], "logs", LOG_NAME), MAX_LOG_READ) or ""
    return {
        "domain": site["domain"],
        "path": path,
        "enabled": installed and not copied,
        "copied": bool(copied),
        "plugin_current": plugin is not None and plugin == _plugin_code(),
        "staging": os.path.lexists(os.path.join(d, STAGING_PLUGIN)),
        "smtp": smtp_available(),
        "port": SMTP_PORT,
        "from_email": (cfg or {}).get("from_email"),
        "from_name": (cfg or {}).get("from_name"),
        "force_from": bool((cfg or {}).get("force_from", True)),
        "domains": domains,
        "dkim": {dom: os.path.isfile(os.path.join(DKIM_DIR, f"{dom}.whp.key")) for dom in domains},
        "log": parse_log(log, path),
    }


@action("wp.mail_status")
def mail_status(params):
    site, d, path = _target(params)
    return _status(site, d, path)


@action("wp.mail_apply")
def mail_apply(params):
    site, d, path = _target(params)
    enabled = v.boolean(params, "enabled")
    if not enabled:
        wordpress.as_user(site, ["/bin/rm", "-rf", "--", PLUGIN, CONFIG_DIR], cwd=d, timeout=60)
        return _status(site, d, path)

    if os.path.lexists(os.path.join(d, STAGING_PLUGIN)):
        raise ActionError("conflict", "This is a staging copy: its mail is held back and stays that way")
    if not smtp_available():
        raise ActionError("conflict", "The mail server of this server does not accept website mail (port "
                          f"{SMTP_PORT}). Update the server first.")
    from_email = email(params, "from_email", required=False) or f"noreply@{site['domain']}"
    from_name = (v.string(params, "from_name", required=False, default="", max_len=120) or "").strip()
    force_from = v.boolean(params, "force_from", required=False, default=True)
    domains = v.list_of(params, "domains", lambda x: v.domain({"d": x}, "d"), required=False, max_items=50) or []
    cfg = build_config(site, d, path, from_email, from_name, force_from, domains)

    # Written by the site user (the folder is theirs), the content comes over stdin.
    wordpress.as_user(site, ["/bin/sh", "-c", f"mkdir -p {CONFIG_DIR} && cat > {CONFIG}.tmp && mv -f {CONFIG}.tmp {CONFIG}"],
                      cwd=d, input_text=config_php(cfg), timeout=60)
    wordpress.as_user(site, ["/bin/sh", "-c", f"cat > {PLUGIN}.tmp && mv -f {PLUGIN}.tmp {PLUGIN}"],
                      cwd=d, input_text=_plugin_code(), timeout=60)
    return _status(site, d, path)


# Runs inside WordPress (wp eval-file -), with all plugins loaded: the real path a message takes.
TEST_CODE = r"""<?php
$whp = ['error' => '', 'queue' => null];
add_action('wp_mail_failed', function ($e) use (&$whp) { $whp['error'] = $e->get_error_message(); });
add_action('phpmailer_init', function ($m) use (&$whp) {
    $prev = $m->action_function;
    $m->action_function = function (...$a) use (&$whp, $prev) {
        $whp['queue'] = is_array($a[7] ?? null) ? ($a[7]['smtp_transaction_id'] ?? null) : null;
        if (is_callable($prev)) { $prev(...$a); }
    };
}, PHP_INT_MAX);
$ok = wp_mail($args[0], $args[1], $args[2]);
global $phpmailer;
echo "\n" . json_encode([
    'ok' => (bool) $ok,
    'error' => $whp['error'],
    'queue_id' => $whp['queue'],
    'from' => isset($phpmailer) ? $phpmailer->From : null,
    'mailer' => isset($phpmailer) ? $phpmailer->Mailer : null,
    'host' => isset($phpmailer) ? ($phpmailer->Mailer === 'smtp' ? $phpmailer->Host . ':' . $phpmailer->Port : null) : null,
    'message_id' => isset($phpmailer) ? $phpmailer->getLastMessageID() : null,
    'routed' => defined('WHP_MAIL'),
    'staging' => defined('WHP_STAGING'),
]) . "\n";
"""


def parse_test_output(stdout: str) -> dict:
    for line in reversed((stdout or "").splitlines()):
        line = line.strip()
        if line.startswith("{"):
            try:
                data = json.loads(line)
            except ValueError:
                break
            if isinstance(data, dict) and "ok" in data:
                return data
    raise ActionError("command_failed", "WordPress did not report the result of the test message",
                      {"stdout": (stdout or "")[-1000:]})


@action("wp.mail_test")
def mail_test(params):
    site, d, path = _target(params)
    to = email(params, "to")
    subject = (v.string(params, "subject", required=False, default="", max_len=200) or "").strip() \
        or f"Test message from {site['domain']}"
    body = params.get("body") or f"This test message was sent by WordPress on {site['domain']} from the hosting panel."
    if not isinstance(body, str) or len(body) > 5000 or "\x00" in body:
        raise ActionError("invalid_params", "body must be a text of at most 5000 characters")
    proc = wordpress.wp(site["domain"], ["eval-file", "-", to, subject, body], path=path, input_text=TEST_CODE,
                        timeout=120, check=False, plugins=True)
    if proc.returncode != 0 and "{" not in (proc.stdout or ""):
        raise wordpress.failure("eval-file", proc)
    result = parse_test_output(proc.stdout)
    if result.get("staging"):
        result["error"] = result.get("error") or "This is a staging copy: its mail is held back"
    return {"to": to, **result}
