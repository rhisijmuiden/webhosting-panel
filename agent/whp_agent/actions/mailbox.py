"""Mailboxes and aliases (phase 3).

The panel is the source of truth: mail.mailboxes_sync gets the complete list
and rewrites the maps, so the server can never drift from the panel.

Files this module owns (formats from installer/modules/60-mail.sh):
  /etc/dovecot/users                   user@domain:{ARGON2ID}hash::::::userdb_quota_rule=*:storage=<n>M
  /etc/postfix/whp/virtual_mailboxes   address  domain/user/
  /etc/postfix/whp/virtual_aliases     address  target[,target]
  /etc/postfix/whp/sender_login        address  login[,login]   (who may send as an address)
  /var/vmail/<domain>/<user>           vmail:vmail 0700, created up front

Passwords arrive hashed by the panel (PHP password_hash with PASSWORD_ARGON2ID,
which Dovecot's ARGON2ID scheme reads). Plain passwords never reach the agent.
"""

from __future__ import annotations

import json
import os
import re
import shutil
from pathlib import Path

from whp_agent.registry import ActionError, action
from whp_agent.run import run
from whp_agent.actions.mail import STATE_FILE, _write_atomic

DOVECOT_USERS = Path("/etc/dovecot/users")
POSTFIX_DIR = Path("/etc/postfix/whp")
VMAIL_ROOT = Path("/var/vmail")
VMAIL_UID = 5000
VMAIL_GID = 5000
TRASH = Path("/var/vmail/.deleted")
MAX_MAILBOXES = 50000

_LOCAL = re.compile(r"^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$")
_DOMAIN = re.compile(r"^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$")
_HASH = re.compile(r"^\{ARGON2ID\}\$argon2id\$v=19\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+/]+\$[A-Za-z0-9+/]+$")
# Hashes taken over from another server by the migration (Plesk stores SHA512-CRYPT).
# Dovecot checks them as they are; the panel stores Argon2id at the next password change.
_CRYPT = re.compile(r"^\{(SHA512-CRYPT|SHA256-CRYPT|MD5-CRYPT)\}\$(6|5|1)\$(rounds=\d+\$)?[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{22,86}$")
HEADER = "# Managed by the Webhosting Panel (mail.mailboxes_sync). Do not edit.\n"


def _address(value: object, field: str, allow_catchall: bool = False) -> str:
    if not isinstance(value, str):
        raise ActionError("invalid_params", f"{field} must be an email address")
    value = value.strip().lower()
    local, sep, domain = value.rpartition("@")
    if not sep or not _DOMAIN.match(domain) or not (_LOCAL.match(local) or (allow_catchall and local == "")):
        raise ActionError("invalid_params", f"{field} is not a valid address: {value!r}")
    return value


def _local_domains() -> set[str]:
    try:
        state = json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return set()
    return {d for d, e in state.get("domains", {}).items() if e.get("provider") == "rhis"}


def parse_sync(params: dict, local_domains: set[str]) -> dict:
    """Validate mail.mailboxes_sync params (pure, unit tested)."""
    raw_boxes = params.get("mailboxes")
    raw_aliases = params.get("aliases", [])
    if not isinstance(raw_boxes, list) or not isinstance(raw_aliases, list):
        raise ActionError("invalid_params", "mailboxes and aliases must be lists")
    if len(raw_boxes) > MAX_MAILBOXES or len(raw_aliases) > MAX_MAILBOXES:
        raise ActionError("invalid_params", "too many entries")

    boxes: dict[str, dict] = {}
    skipped: list[str] = []
    for i, b in enumerate(raw_boxes):
        if not isinstance(b, dict):
            raise ActionError("invalid_params", f"mailboxes[{i}] must be an object")
        email = _address(b.get("email"), f"mailboxes[{i}].email")
        if email in boxes:
            raise ActionError("invalid_params", f"duplicate mailbox {email}")
        pw = b.get("password_hash")
        if not isinstance(pw, str) or not (_HASH.match(pw) or _CRYPT.match(pw)):
            raise ActionError("invalid_params", f"mailboxes[{i}].password_hash must be an {{ARGON2ID}} or migrated crypt hash")
        quota = b.get("quota_mb", 0)
        if isinstance(quota, bool) or not isinstance(quota, int) or not 0 <= quota <= 10_000_000:
            raise ActionError("invalid_params", f"mailboxes[{i}].quota_mb must be 0 (unlimited) or more")
        enabled = b.get("enabled", True)
        if not isinstance(enabled, bool):
            raise ActionError("invalid_params", f"mailboxes[{i}].enabled must be true or false")
        send_as = b.get("send_as", [])
        if not isinstance(send_as, list):
            raise ActionError("invalid_params", f"mailboxes[{i}].send_as must be a list")
        if email.split("@", 1)[1] not in local_domains:
            skipped.append(email)
            continue
        boxes[email] = {
            "password_hash": pw,
            "quota_mb": quota,
            "enabled": enabled,
            "send_as": sorted({_address(a, f"mailboxes[{i}].send_as") for a in send_as} - {email}),
        }

    aliases: dict[str, list[str]] = {}
    for i, a in enumerate(raw_aliases):
        if not isinstance(a, dict):
            raise ActionError("invalid_params", f"aliases[{i}] must be an object")
        # "@domain" is a catch-all.
        src = _address(a.get("address"), f"aliases[{i}].address", allow_catchall=True)
        targets = a.get("targets")
        if not isinstance(targets, list) or not targets or len(targets) > 100:
            raise ActionError("invalid_params", f"aliases[{i}].targets must be a list of 1-100 addresses")
        if src.split("@", 1)[1] not in local_domains:
            skipped.append(src)
            continue
        if src in boxes:
            raise ActionError("conflict", f"{src} is both a mailbox and an alias")
        aliases[src] = sorted({_address(t, f"aliases[{i}].targets") for t in targets})

    return {"mailboxes": boxes, "aliases": aliases, "skipped": sorted(skipped)}


def render(plan: dict) -> dict[str, str]:
    users, mailboxes, aliases = [HEADER], [HEADER], [HEADER]
    logins: dict[str, set[str]] = {}
    for email, b in sorted(plan["mailboxes"].items()):
        local, domain = email.split("@", 1)
        extra = []
        if b["quota_mb"]:
            extra.append(f"userdb_quota_rule=*:storage={b['quota_mb']}M")
        if not b["enabled"]:
            # Still receives mail, cannot log in.
            extra.append("nologin=y")
            extra.append("reason=Mailbox_disabled")
        users.append(f"{email}:{b['password_hash']}::::::{' '.join(extra)}\n")
        mailboxes.append(f"{email} {domain}/{local}/\n")
        logins.setdefault(email, set()).add(email)
        for addr in b["send_as"]:
            logins.setdefault(addr, set()).add(email)
    for src, targets in sorted(plan["aliases"].items()):
        aliases.append(f"{src} {','.join(targets)}\n")
    # Postfix reads virtual_alias_maps before the mailboxes, so a catch-all
    # ("@domain") would also take the mail of every existing mailbox. An
    # identity entry per mailbox keeps their mail where it belongs.
    catch_all = {src[1:] for src in plan["aliases"] if src.startswith("@")}
    for email in sorted(plan["mailboxes"]):
        if email.split("@", 1)[1] in catch_all and email not in plan["aliases"]:
            aliases.append(f"{email} {email}\n")
    sender = [HEADER] + [f"{addr} {','.join(sorted(who))}\n" for addr, who in sorted(logins.items())]
    return {
        "dovecot_users": "".join(users),
        "virtual_mailboxes": "".join(mailboxes),
        "virtual_aliases": "".join(aliases),
        "sender_login": "".join(sender),
    }


def _ensure_home(email: str) -> bool:
    local, domain = email.split("@", 1)
    home = VMAIL_ROOT / domain / local
    if home.exists():
        return False
    for d in (VMAIL_ROOT / domain, home):
        d.mkdir(mode=0o700, exist_ok=True)
        try:
            os.chown(d, VMAIL_UID, VMAIL_GID)
        except PermissionError:
            pass
    return True


@action("mail.mailboxes_sync")
def mailboxes_sync(params: dict) -> dict:
    """Replace all mailboxes and aliases. Send the complete list every time.

    Removing a mailbox here stops delivery and login but keeps its mail on
    disk; mail.mailbox_purge deletes the files.
    """
    plan = parse_sync(params, _local_domains())
    files = render(plan)
    changed = []
    if _write_atomic(DOVECOT_USERS, files["dovecot_users"], 0o640, "dovecot"):
        changed.append(str(DOVECOT_USERS))
    postfix_changed = False
    for name in ("virtual_mailboxes", "virtual_aliases", "sender_login"):
        if _write_atomic(POSTFIX_DIR / name, files[name], 0o644):
            changed.append(str(POSTFIX_DIR / name))
            postfix_changed = True
    created = [e for e in sorted(plan["mailboxes"]) if _ensure_home(e)]
    if postfix_changed:
        run(["postfix", "reload"], timeout=30)
    # passwd-file is re-read by Dovecot on change; flush the auth cache so new passwords work at once.
    if str(DOVECOT_USERS) in changed:
        run(["doveadm", "auth", "cache", "flush"], timeout=30, check=False)
    return {
        "changed": changed,
        "created_homes": created,
        "mailboxes": len(plan["mailboxes"]),
        "aliases": len(plan["aliases"]),
        "skipped_not_local": plan["skipped"],
    }


@action("mail.mailbox_usage")
def mailbox_usage(params: dict) -> dict:
    """Storage used per mailbox, from Dovecot quota (for the panel's usage bars)."""
    proc = run(["doveadm", "-f", "json", "quota", "get", "-A"], timeout=120, check=False)
    usage: dict[str, dict] = {}
    try:
        rows = json.loads(proc.stdout or "[]")
    except ValueError:
        rows = []
    for r in rows:
        if r.get("type") == "STORAGE":
            usage[r.get("username", "")] = {
                "used_kb": int(r.get("value") or 0),
                "limit_kb": int(r["limit"]) if str(r.get("limit", "-")).isdigit() else None,
            }
    return {"usage": usage}


@action("mail.mailbox_purge")
def mailbox_purge(params: dict) -> dict:
    """Move the mail of a mailbox that no longer exists to /var/vmail/.deleted (not deleted outright, so it can be restored)."""
    email = _address(params.get("email"), "email")
    users = DOVECOT_USERS.read_text(encoding="utf-8") if DOVECOT_USERS.exists() else ""
    if any(line.split(":", 1)[0] == email for line in users.splitlines()):
        raise ActionError("conflict", f"{email} still exists; remove it with mail.mailboxes_sync first")
    local, domain = email.split("@", 1)
    home = VMAIL_ROOT / domain / local
    if not home.is_dir():
        raise ActionError("not_found", f"No mail stored for {email}")
    TRASH.mkdir(mode=0o700, exist_ok=True)
    target = TRASH / f"{domain}__{local}__{os.urandom(4).hex()}"
    shutil.move(str(home), str(target))
    return {"email": email, "moved_to": str(target)}
