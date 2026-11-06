"""Mail log: what Postfix did with every message, plus Rspamd's verdict.

The panel calls mail.log_read once a minute with the cursor it got last time
and stores the events (packages/dns-mail, MailLogCollector). The agent only
reads and parses; it keeps no state of its own.

Postfix logs through syslog into the systemd journal (Debian 13 ships no
rsyslog), facility "mail", identifiers "postfix/<daemon>" or
"postfix/<service>/<daemon>" (submission, submissions). One message is
spread over several lines that share its queue id:

  smtpd    ABC: client=host[ip], sasl_method=PLAIN, sasl_username=jan@klant.nl
  pickup   ABC: uid=1005 from=<site>                     (sendmail, e.g. PHP)
  cleanup  ABC: message-id=<...>
  qmgr     ABC: from=<jan@klant.nl>, size=1234, nrcpt=2 (queue active)
  smtp     ABC: to=<x@y.nl>, relay=mx[ip]:25, delay=1, dsn=2.0.0, status=sent (250 ok)
  qmgr     ABC: removed
  smtpd    NOQUEUE: reject: RCPT from host[ip]: 554 5.7.1 ...; from=<a> to=<b> proto=ESMTP helo=<h>

Each line becomes one event; the panel merges them per queue id. The
installer turns on enable_long_queue_ids so ids are not reused.
"""

from __future__ import annotations

import json
import pwd
import re
import subprocess
import urllib.error
import urllib.request

from whp_agent.registry import ActionError, action
from whp_agent.run import SAFE_ENV
from whp_agent.actions import antispam

JOURNALCTL = "journalctl"
RSPAMD_URL = "http://127.0.0.1:11334/history"
DEFAULT_LIMIT = 5000
MAX_LIMIT = 20000
MAX_TEXT = 500
MAX_SYMBOLS = 12

_QID = re.compile(r"^([0-9A-Za-z]{6,20}): (.*)$", re.S)
_CURSOR = re.compile(r"^[A-Za-z0-9=;_+/.:-]{1,512}$")
_HOSTIP = re.compile(r"^(?P<host>[^\[\s]*)\[(?P<ip>[^\]]*)\](?::\d+)?$")
_KV = re.compile(r"(?:^|, )([a-z_-]+)=(<[^>]*>|[^,]*)")
_STATUS = re.compile(r"(?:^|, )status=([a-z-]+)(?: \((.*)\))?$", re.S)
_REJECT = re.compile(
    r"^(?P<kind>reject|milter-reject|reject_warning|discard|hold): (?P<stage>[A-Z-]+) from (?P<client>\S+?): "
    r"(?P<text>.*?); (?P<env>from=<[^>]*>.*)$",
    re.S,
)
_ENVKV = re.compile(r"(from|to|proto|helo)=<?([^>\s]*)>?")
_CODE = re.compile(r"^(?:(\d{3}) )?(?:(\d\.\d{1,3}\.\d{1,3}) )?(.*)$", re.S)
_BOUNCE = re.compile(r"^sender (non-delivery|delivery status|delay status) notification: ([0-9A-Za-z]{6,20})$")


def _short(value: str | None, size: int = MAX_TEXT) -> str | None:
    if value is None:
        return None
    value = value.strip()
    return value[:size] if value else None


def _addr(value: str | None) -> str | None:
    if value is None:
        return None
    value = value.strip().strip("<>").strip()
    return value[:320].lower() if value else ""


def _host_ip(value: str | None) -> tuple[str | None, str | None]:
    m = _HOSTIP.match((value or "").strip())
    if not m:
        return None, None
    return (m["host"] or None), (m["ip"] or None)


def _kv(text: str) -> dict:
    return {k: v for k, v in _KV.findall(text)}


def _user_of(uid: str) -> str | None:
    try:
        return pwd.getpwuid(int(uid)).pw_name
    except (KeyError, ValueError, OverflowError):
        return None


def _service(identifier: str) -> tuple[str, str]:
    """postfix/submission/smtpd -> ("submission", "smtpd"); postfix/smtpd -> ("smtp", "smtpd")."""
    parts = identifier.split("/")
    daemon = parts[-1]
    service = parts[1] if len(parts) == 3 else ("smtp" if daemon == "smtpd" else daemon)
    return service, daemon


def _reject(rest: str) -> dict | None:
    m = _REJECT.match(rest)
    if not m:
        return None
    host, ip = _host_ip(m["client"])
    env = dict(_ENVKV.findall(m["env"]))
    code = _CODE.match(m["text"])
    return {
        "kind": "reject",
        "action": m["kind"],
        "stage": m["stage"],
        "client_host": host,
        "client_ip": ip,
        "code": code[1] if code else None,
        "dsn": code[2] if code else None,
        "reason": _short(code[3] if code else m["text"]),
        "from": _addr(env.get("from")),
        "to": _addr(env.get("to")),
        "helo": _short(env.get("helo"), 255),
    }


def parse_line(identifier: str, message: str) -> dict | None:
    """One Postfix log line -> one event, or None for lines the log does not need."""
    if not identifier.startswith("postfix/") or not message:
        return None
    service, daemon = _service(identifier)
    base = {"service": service, "daemon": daemon}

    if message.startswith("NOQUEUE: "):
        event = _reject(message[9:])
        return {**base, "qid": None, **event} if event else None

    m = _QID.match(message)
    if not m:
        return None
    qid, rest = m[1], m[2].strip()
    base["qid"] = qid

    if daemon == "smtpd" and rest.startswith("client="):
        kv = _kv(rest)
        host, ip = _host_ip(kv.get("client"))
        return {**base, "kind": "client", "client_host": host, "client_ip": ip,
                "sasl_username": _short(kv.get("sasl_username"), 320)}

    if daemon == "pickup" and rest.startswith("uid="):
        kv = dict(re.findall(r"(uid|from)=<?([^>\s]*)>?", rest))
        uid = kv.get("uid", "")
        return {**base, "kind": "pickup", "uid": int(uid) if uid.isdigit() else None,
                "user": _user_of(uid) if uid.isdigit() else None, "from": _addr(kv.get("from"))}

    if daemon == "cleanup":
        if rest.startswith("message-id="):
            return {**base, "kind": "message_id", "message_id": _short(rest[11:].strip().strip("<>"), 255)}
        event = _reject(rest)
        return {**base, **event} if event else None

    if daemon == "qmgr":
        if rest == "removed":
            return {**base, "kind": "removed"}
        if rest.startswith("from="):
            kv = _kv(rest.split(" (", 1)[0])
            event = {**base, "kind": "queued", "from": _addr(kv.get("from"))}
            if kv.get("size", "").isdigit():
                event["size"] = int(kv["size"])
            nrcpt = kv.get("nrcpt", "").split(" ")[0]
            if nrcpt.isdigit():
                event["nrcpt"] = int(nrcpt)
            if kv.get("status") == "expired":
                event = {**event, "kind": "expired"}
            return event
        return None

    if daemon == "bounce":
        b = _BOUNCE.match(rest)
        return {**base, "kind": "bounce", "notice": b[1], "child_qid": b[2]} if b else None

    if rest.startswith("to="):
        status = _STATUS.search(rest)
        if not status:
            return None
        kv = _kv(rest[: status.start()])
        relay = kv.get("relay", "")
        event = {
            **base,
            "kind": "delivery",
            "to": _addr(kv.get("to")),
            "orig_to": _addr(kv.get("orig_to")) or None,
            "relay": _short(relay, 255),
            "local": daemon in ("lmtp", "virtual", "local") or "dovecot" in relay,
            "dsn": _short(kv.get("dsn"), 16),
            "status": status[1],
            "response": _short(status[2]),
        }
        try:
            event["delay"] = round(float(kv.get("delay", "")), 2)
        except ValueError:
            pass
        return event
    return None


def _journal_message(entry: dict) -> str:
    value = entry.get("MESSAGE", "")
    if isinstance(value, list):  # journald sends non-UTF-8 text as a byte list
        try:
            value = bytes(value).decode("utf-8", "replace")
        except (TypeError, ValueError):
            return ""
    return value if isinstance(value, str) else ""


def parse_journal(lines, limit: int) -> dict:
    """journalctl -o json output (an iterable of lines) -> events, last cursor."""
    events, cursor, read, more = [], None, 0, False
    for raw in lines:
        if read >= limit:
            more = True
            break
        try:
            entry = json.loads(raw)
        except ValueError:
            continue
        read += 1
        cursor = entry.get("__CURSOR") or cursor
        event = parse_line(str(entry.get("SYSLOG_IDENTIFIER", "")), _journal_message(entry))
        if event is None:
            continue
        try:
            event["time"] = int(entry.get("__REALTIME_TIMESTAMP", "0")) / 1_000_000
        except ValueError:
            event["time"] = None
        events.append(event)
    return {"events": events, "cursor": cursor, "lines": read, "more": more}


@action("mail.log_read")
def log_read(params: dict) -> dict:
    """Postfix events after a cursor. Without a cursor: the last since_hours hours."""
    cursor = params.get("cursor")
    if cursor is not None and (not isinstance(cursor, str) or not _CURSOR.match(cursor)):
        raise ActionError("invalid_params", "cursor has an invalid format")
    limit = params.get("limit", DEFAULT_LIMIT)
    since = params.get("since_hours", 24)
    for key, value, top in (("limit", limit, MAX_LIMIT), ("since_hours", since, 24 * 31)):
        if isinstance(value, bool) or not isinstance(value, int) or not 1 <= value <= top:
            raise ActionError("invalid_params", f"{key} is out of range")

    argv = [JOURNALCTL, "--no-pager", "--output=json", "SYSLOG_FACILITY=2"]
    argv.append(f"--after-cursor={cursor}" if cursor else f"--since=-{since}h")
    try:
        proc = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                                errors="replace", env=dict(SAFE_ENV), shell=False)
    except FileNotFoundError:
        raise ActionError("command_failed", "journalctl not found")
    try:
        result = parse_journal(proc.stdout, limit)
    finally:
        proc.stdout.close()
        if proc.poll() is None:
            proc.terminate()
        try:
            proc.wait(timeout=10)
        except subprocess.TimeoutExpired:
            proc.kill()
    if result["cursor"] is None:
        result["cursor"] = cursor
    return result


def _secret() -> str | None:
    try:
        for line in antispam.SECRETS_FILE.read_text(encoding="utf-8").splitlines():
            key, _, value = line.partition("=")
            if key.strip() == "RSPAMD_CONTROLLER_PASS" and value.strip():
                return value.strip().strip("'\"")
    except OSError:
        pass
    return None


def parse_history(data) -> list[dict]:
    """Rspamd /history (v2 {"rows": [...]} or a plain list) -> compact rows."""
    rows = data.get("rows", []) if isinstance(data, dict) else data
    out = []
    for row in rows if isinstance(rows, list) else []:
        if not isinstance(row, dict):
            continue
        qid = row.get("qid") if row.get("qid") not in (None, "", "undef") else None
        mid = row.get("message-id") if row.get("message-id") not in (None, "", "undef") else None
        if not qid and not mid:
            continue
        symbols = row.get("symbols") or {}
        if isinstance(symbols, dict):
            ranked = sorted(
                ((name, (s or {}).get("score", 0) if isinstance(s, dict) else 0) for name, s in symbols.items()),
                key=lambda item: -abs(float(item[1] or 0)),
            )
            symbols = [{"name": str(n)[:64], "score": round(float(sc or 0), 2)} for n, sc in ranked[:MAX_SYMBOLS] if sc]
        else:
            symbols = []
        try:
            score = round(float(row.get("score")), 2)
        except (TypeError, ValueError):
            score = None
        try:
            required = round(float(row.get("required_score")), 2)
        except (TypeError, ValueError):
            required = None
        out.append({
            "qid": str(qid)[:20] if qid else None,
            "message_id": _short(str(mid).strip("<>"), 255) if mid else None,
            "time": row.get("unix_time"),
            "score": score,
            "required_score": required,
            "action": _short(str(row.get("action") or ""), 32),
            "user": _short(str(row.get("user") or ""), 320) if row.get("user") not in (None, "", "undef") else None,
            "ip": _short(str(row.get("ip") or ""), 64),
            "subject": _short(str(row.get("subject") or ""), 255),
            "symbols": symbols,
        })
    return out


@action("mail.spam_history")
def spam_history(params: dict) -> dict:
    """Recent Rspamd scan results (history_redis), newest first."""
    request = urllib.request.Request(RSPAMD_URL, headers={"Accept": "application/json"})
    secret = _secret()
    if secret:
        request.add_header("Password", secret)
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            data = json.loads(response.read().decode("utf-8", "replace"))
    except (urllib.error.URLError, OSError, ValueError) as e:
        raise ActionError("command_failed", f"Rspamd history is not available: {e}")
    return {"rows": parse_history(data)}
