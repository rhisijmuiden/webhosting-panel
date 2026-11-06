"""Spam quarantine, sender allow/block lists and DANE (phase 3).

Scanning itself is set up by the installer: Rspamd with greylisting, Bayes
(in Valkey), RBLs and ClamAV. Rspamd adds "X-Spam: Yes" from score 6 and an
"X-Spam-Level" header with one "*" per point; above 15 mail is refused.

This module decides where a scanned message lands, in a Sieve script that
Dovecot runs before the user's own filters (see mail/antispam/):

  allow-listed sender            -> Inbox, even when it looks like spam
  block-listed sender            -> Junk
  score >= quarantine threshold  -> "Quarantine" folder (released from the panel)
  X-Spam: Yes                    -> Junk

Files this module owns:
  /var/lib/webhosting-panel/mail/sieve/before.sieve   (+ compiled .svbin)
  /var/lib/webhosting-panel/mail/spam-policy.json     what the panel sent last
"""

from __future__ import annotations

import base64
import email.header
import email.parser
import email.policy
import hashlib
import json
import re
from pathlib import Path

from whp_agent.registry import ActionError, action
from whp_agent.run import run
from whp_agent.actions.mail import _write_atomic
from whp_agent.actions.mailbox import DOVECOT_USERS, _address

SIEVE_DIR = Path("/var/lib/webhosting-panel/mail/sieve")
POLICY_FILE = Path("/var/lib/webhosting-panel/mail/spam-policy.json")
SECRETS_FILE = Path("/etc/webhosting-panel/secrets.env")
LETSENCRYPT_RENEWAL = Path("/etc/letsencrypt/renewal")
QUARANTINE = "Quarantine"
JUNK = "Junk"
MAX_RULES = 1000
MAX_LIST = 500
DEFAULT_THRESHOLD = 10

_DOMAIN = re.compile(r"^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$")


def _domain(value: object, field: str) -> str:
    if not isinstance(value, str) or not _DOMAIN.match(value.strip().lower()):
        raise ActionError("invalid_params", f"{field} must be a domain")
    return value.strip().lower()


def _sender(value: object, field: str) -> tuple[str, str]:
    """An allow/block entry: a full address, or "@domain" / "domain" for a whole domain."""
    if not isinstance(value, str):
        raise ActionError("invalid_params", f"{field} must be a string")
    value = value.strip().lower()
    if value.startswith("@"):
        return "domain", _domain(value[1:], field)
    if "@" not in value:
        return "domain", _domain(value, field)
    return "address", _address(value, field)


def _threshold(value: object, field: str) -> int | None:
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, int) or not 6 <= value <= 50:
        raise ActionError("invalid_params", f"{field} must be a whole score from 6 to 50, or null")
    return value


def parse_policy(params: dict) -> dict:
    """Validate mail.spam_policy_sync params (pure, unit tested)."""
    raw = params.get("domains")
    if not isinstance(raw, list):
        raise ActionError("invalid_params", "domains must be a list")
    if len(raw) > 50000:
        raise ActionError("invalid_params", "too many domains")
    default = _threshold(params.get("default_quarantine_score", DEFAULT_THRESHOLD), "default_quarantine_score")
    domains: dict[str, dict] = {}
    for i, d in enumerate(raw):
        if not isinstance(d, dict):
            raise ActionError("invalid_params", f"domains[{i}] must be an object")
        name = _domain(d.get("domain"), f"domains[{i}].domain")
        if name in domains:
            raise ActionError("invalid_params", f"duplicate domain {name}")
        lists = {}
        for kind in ("allow", "block"):
            entries = d.get(kind, [])
            if not isinstance(entries, list) or len(entries) > MAX_RULES:
                raise ActionError("invalid_params", f"domains[{i}].{kind} must be a list of at most {MAX_RULES}")
            addresses, sender_domains = set(), set()
            for j, e in enumerate(entries):
                t, v = _sender(e, f"domains[{i}].{kind}[{j}]")
                (addresses if t == "address" else sender_domains).add(v)
            lists[kind] = {"addresses": sorted(addresses), "domains": sorted(sender_domains)}
        domains[name] = {
            "quarantine_score": _threshold(d.get("quarantine_score", default), f"domains[{i}].quarantine_score"),
            "junk": d.get("junk", True) is not False,
            **lists,
        }
    return {"default_quarantine_score": default, "domains": domains}


def _q(value: str) -> str:
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def _qlist(values: list[str]) -> str:
    return "[" + ", ".join(_q(v) for v in values) + "]"


def _sender_test(entry: dict) -> str | None:
    tests = []
    if entry["addresses"]:
        tests.append(f'address :all :is "from" {_qlist(entry["addresses"])}')
    if entry["domains"]:
        tests.append(f'address :domain :is "from" {_qlist(entry["domains"])}')
    if not tests:
        return None
    return tests[0] if len(tests) == 1 else "anyof (" + ", ".join(tests) + ")"


def _score_test(score: int) -> str:
    # One "*" per point in X-Spam-Level, so "at least N stars" is "score >= N".
    return 'header :regex "X-Spam-Level" ' + _q("^\\*{" + str(score) + ",}")


def _spam_rules(score: int | None, junk: bool, indent: str) -> list[str]:
    out = []
    if score is not None:
        out += [f"{indent}if {_score_test(score)} {{", f'{indent}    fileinto :create "{QUARANTINE}";', f"{indent}    stop;", f"{indent}}}"]
    if junk:
        out += [f'{indent}if header :is "X-Spam" "Yes" {{', f'{indent}    fileinto :create "{JUNK}";', f"{indent}    stop;", f"{indent}}}"]
    return out


def render_sieve(plan: dict) -> str:
    lines = [
        "# Managed by the Webhosting Panel (mail.spam_policy_sync). Do not edit.",
        'require ["fileinto", "mailbox", "envelope", "regex"];',
        "",
    ]
    first = True
    for name, d in sorted(plan["domains"].items()):
        lines.append(("if" if first else "elsif") + f' envelope :domain :is "to" {_q(name)} {{')
        first = False
        allow = _sender_test(d["allow"])
        block = _sender_test(d["block"])
        if allow:
            lines += [f"    if {allow} {{", "        keep;", "        stop;", "    }"]
        if block:
            lines += [f"    if {block} {{", f'        fileinto :create "{JUNK}";', "        stop;", "    }"]
        lines += _spam_rules(d["quarantine_score"], d["junk"], "    ")
        lines.append("}")
    default = _spam_rules(plan["default_quarantine_score"], True, "    ")
    if first:
        lines += [line[4:] for line in default]
    else:
        lines += ["else {", *default, "}"]
    return "\n".join(lines) + "\n"


@action("mail.spam_policy_sync")
def spam_policy_sync(params: dict) -> dict:
    """Replace the spam routing for all domains. Send the complete list every time."""
    plan = parse_policy(params)
    script = render_sieve(plan)
    SIEVE_DIR.mkdir(parents=True, exist_ok=True)
    target = SIEVE_DIR / "before.sieve"
    changed = _write_atomic(target, script, 0o644)
    if changed or not (SIEVE_DIR / "before.svbin").exists():
        # A global script must be precompiled: Dovecot cannot write next to it as the mail user.
        run(["sievec", str(target)], timeout=60)
    _write_atomic(POLICY_FILE, json.dumps(plan, indent=2, sort_keys=True) + "\n", 0o640)
    return {"changed": changed, "domains": len(plan["domains"])}


def _users_of(domain: str) -> list[str]:
    try:
        text = DOVECOT_USERS.read_text(encoding="utf-8")
    except OSError:
        return []
    users = []
    for line in text.splitlines():
        user = line.split(":", 1)[0]
        if user.endswith("@" + domain) and "nologin=y" not in line:
            users.append(user)
    return sorted(users)


def _decode(value: str | None) -> str:
    if not value:
        return ""
    try:
        return str(email.header.make_header(email.header.decode_header(value)))[:300]
    except (ValueError, LookupError):
        return value[:300]


def parse_fetch(user: str, stdout: str) -> list[dict]:
    """Turn `doveadm -f json fetch ... hdr` output into list rows (pure, unit tested)."""
    try:
        rows = json.loads(stdout or "[]")
    except ValueError:
        return []
    parser = email.parser.HeaderParser(policy=email.policy.compat32)
    out = []
    for r in rows if isinstance(rows, list) else []:
        headers = parser.parsestr(r.get("hdr", ""))
        level = headers.get("X-Spam-Level", "") or ""
        out.append({
            "user": user,
            "uid": int(r.get("uid") or 0),
            "guid": r.get("guid", ""),
            "date": r.get("date.received", ""),
            "size": int(r.get("size.physical") or 0),
            "from": _decode(headers.get("From")),
            "to": _decode(headers.get("To")),
            "subject": _decode(headers.get("Subject")),
            "score": level.count("*"),
            "virus": "CLAM_VIRUS" in (headers.get("X-Spamd-Result", "") or ""),
        })
    return out


def _uid(value: object) -> str:
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise ActionError("invalid_params", "uid must be a positive number")
    return str(value)


def _owned(user: str, domain: str | None) -> str:
    user = _address(user, "user")
    if domain is not None and not user.endswith("@" + domain):
        raise ActionError("invalid_params", "user is not in this domain")
    if user not in _users_of(user.split("@", 1)[1]):
        raise ActionError("not_found", f"No mailbox {user}")
    return user


@action("mail.quarantine_list")
def quarantine_list(params: dict) -> dict:
    """Messages in the Quarantine folder of every mailbox in a domain (or one mailbox)."""
    domain = _domain(params.get("domain"), "domain")
    users = [_owned(params["user"], domain)] if params.get("user") else _users_of(domain)
    messages: list[dict] = []
    for user in users:
        proc = run(
            ["doveadm", "-f", "json", "fetch", "-u", user, "uid guid date.received size.physical hdr", "mailbox", QUARANTINE],
            timeout=60, check=False,
        )
        messages += parse_fetch(user, proc.stdout)[-MAX_LIST:]
    messages.sort(key=lambda m: m["date"], reverse=True)
    return {"domain": domain, "messages": messages[:MAX_LIST], "truncated": len(messages) > MAX_LIST}


def _rspamc_args() -> list[str]:
    args = ["rspamc", "-h", "127.0.0.1:11334"]
    try:
        for line in SECRETS_FILE.read_text(encoding="utf-8").splitlines():
            key, _, value = line.partition("=")
            if key.strip() == "RSPAMD_CONTROLLER_PASS" and value.strip():
                args += ["-P", value.strip().strip("'\"")]
    except OSError:
        pass
    return args


def _learn(user: str, uid: str, kind: str) -> bool:
    proc = run(["doveadm", "-f", "json", "fetch", "-u", user, "text", "mailbox", QUARANTINE, "uid", uid], timeout=60, check=False)
    try:
        text = json.loads(proc.stdout or "[]")[0]["text"]
    except (ValueError, IndexError, KeyError, TypeError):
        return False
    return run([*_rspamc_args(), kind], timeout=60, check=False, input_text=text).returncode == 0


@action("mail.quarantine_release")
def quarantine_release(params: dict) -> dict:
    """Move a quarantined message to the inbox; learn it as ham so the next one passes."""
    domain = _domain(params.get("domain"), "domain")
    user = _owned(params.get("user"), domain)
    uid = _uid(params.get("uid"))
    learned = _learn(user, uid, "learn_ham") if params.get("learn", True) else False
    run(["doveadm", "move", "-u", user, "INBOX", "mailbox", QUARANTINE, "uid", uid], timeout=60)
    return {"user": user, "uid": int(uid), "learned": learned}


@action("mail.quarantine_delete")
def quarantine_delete(params: dict) -> dict:
    """Delete quarantined messages; optionally learn them as spam first."""
    domain = _domain(params.get("domain"), "domain")
    user = _owned(params.get("user"), domain)
    uids = params.get("uids")
    if not isinstance(uids, list) or not 1 <= len(uids) <= MAX_LIST:
        raise ActionError("invalid_params", f"uids must be a list of 1 to {MAX_LIST} numbers")
    uids = [_uid(u) for u in uids]
    learned = 0
    if params.get("learn", False):
        learned = sum(_learn(user, u, "learn_spam") for u in uids)
    run(["doveadm", "expunge", "-u", user, "mailbox", QUARANTINE, "uid", ",".join(uids)], timeout=60)
    return {"user": user, "deleted": len(uids), "learned": learned}


def spki_sha256(pem: str) -> str:
    """SHA-256 of a certificate's public key (TLSA 3 1 1), from PEM text."""
    proc = run(["openssl", "x509", "-noout", "-pubkey"], timeout=30, input_text=pem)
    body = "".join(line for line in proc.stdout.splitlines() if line and not line.startswith("-----"))
    return hashlib.sha256(base64.b64decode(body)).hexdigest()


@action("mail.tlsa")
def tlsa(params: dict) -> dict:
    """TLSA "3 1 1" value for the certificate Postfix presents on port 25 (DANE).

    The value only stays valid when renewals keep the key: the installer
    issues the mail host certificate with certbot --reuse-key.
    """
    cert = run(["postconf", "-h", "smtpd_tls_cert_file"], timeout=30).stdout.strip()
    if not cert:
        raise ActionError("not_found", "Postfix has no TLS certificate configured")
    try:
        pem = Path(cert).read_text(encoding="ascii")
    except OSError as e:
        raise ActionError("not_found", f"Cannot read {cert}: {e.strerror}")
    first = pem.split("-----END CERTIFICATE-----", 1)[0] + "-----END CERTIFICATE-----\n"
    end = run(["openssl", "x509", "-noout", "-enddate"], timeout=30, input_text=first).stdout.strip()
    return {"usage": 3, "selector": 1, "matching_type": 1, "certificate": spki_sha256(first),
            "cert_file": cert, "not_after": end.partition("=")[2], "reuse_key": reuse_key(cert)}


def reuse_key(cert_file: str) -> bool | None:
    """True when certbot keeps the key on renewal (so the TLSA value stays valid); None when not a certbot cert."""
    m = re.match(r"^/etc/letsencrypt/live/([^/]+)/", cert_file)
    if not m:
        return None
    try:
        conf = (LETSENCRYPT_RENEWAL / f"{m.group(1)}.conf").read_text(encoding="utf-8")
    except OSError:
        return None
    return re.search(r"^\s*reuse_key\s*=\s*true\s*$", conf, re.I | re.M) is not None
