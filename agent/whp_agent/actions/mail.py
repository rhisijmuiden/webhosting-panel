"""Mail routing, DKIM keys and mail endpoint certificates (phase 3).

Actions:
  mail.set_routing            which domains have mailboxes here (RHIS) or elsewhere (Microsoft 365, other)
  mail.dkim_generate          create (or replace) the DKIM key of a domain, return the public key
  mail.dkim_get               return the public key of a domain
  mail.issue_endpoint_cert    Let's Encrypt cert for autoconfig/autodiscover/mta-sts.<domain> (job)

Files this module owns:
  /etc/postfix/whp/virtual_domains                       domains Postfix delivers locally
  /var/lib/snappymail/_data_/_default_/domains/<d>.json  webmail login per RHIS domain
  /var/lib/webhosting-panel/mail/domains.json            state for webmail/autoconfig
  /var/lib/rspamd/dkim/<domain>.whp.key                  DKIM private keys (Rspamd signs when present)
"""

from __future__ import annotations

import json
import os
import pwd
import grp
import re
import tempfile
from pathlib import Path

from whp_agent import validate as v
from whp_agent.jobs import start_job
from whp_agent.registry import ActionError, action
from whp_agent.run import run

PROVIDERS = ["rhis", "microsoft365", "external"]
STS_MODES = ["testing", "enforce", "none"]
DKIM_SELECTOR = "whp"

POSTFIX_DOMAINS = Path("/etc/postfix/whp/virtual_domains")
SNAPPYMAIL_DOMAINS = Path("/var/lib/snappymail/_data_/_default_/domains")
SNAPPYMAIL_USER = "snappymail"
STATE_FILE = Path("/var/lib/webhosting-panel/mail/domains.json")
DKIM_DIR = Path("/var/lib/rspamd/dkim")
RSPAMD_USER = "_rspamd"
ACME_WEBROOT = Path("/var/lib/webhosting-panel/acme")
MANAGED_MARK = "managed-by-whp"

_HOST_RE = re.compile(r"^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")


# ---------------------------------------------------------------- helpers


def _host(value: object, field: str) -> str:
    if not isinstance(value, str) or not _HOST_RE.match(value.strip().lower().rstrip(".")):
        raise ActionError("invalid_params", f"{field} must be a host name")
    return value.strip().lower().rstrip(".")


def _write_atomic(path: Path, content: str, mode: int = 0o644, owner: str | None = None) -> bool:
    """Write only when the content changed. Returns True when the file was written."""
    if path.exists() and path.read_text(encoding="utf-8") == content:
        return False
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=path.parent, prefix=f".{path.name}.")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(content)
        os.chmod(tmp, mode)
        if owner is not None:
            try:
                os.chown(tmp, pwd.getpwnam(owner).pw_uid, grp.getgrnam(owner).gr_gid)
            except KeyError:
                pass
        os.replace(tmp, path)
    except BaseException:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
    return True


def parse_routing(params: dict) -> dict:
    """Validate mail.set_routing params into a normalised plan (pure, unit tested)."""
    mail_host = _host(params.get("mail_host"), "mail_host")
    brand = v.string(params, "brand", max_len=40) if "brand" in params else "RHIS"
    raw = params.get("domains")
    if not isinstance(raw, list) or len(raw) > 5000:
        raise ActionError("invalid_params", "domains must be a list")
    domains: dict[str, dict] = {}
    for i, item in enumerate(raw):
        if not isinstance(item, dict):
            raise ActionError("invalid_params", f"domains[{i}] must be an object")
        domain = v.domain(item, "domain")
        provider = v.choice(item, "provider", PROVIDERS)
        sts_mode = item.get("mta_sts_mode")
        if sts_mode is not None and sts_mode not in STS_MODES:
            raise ActionError("invalid_params", f"domains[{i}].mta_sts_mode must be one of {STS_MODES}")
        entry: dict = {"provider": provider}
        if sts_mode is not None and provider != "external":
            mx = [mail_host] if provider == "rhis" else ["*.mail.protection.outlook.com"]
            entry["mta_sts"] = {"mode": sts_mode, "mx": mx, "max_age": 604800}
        domains[domain] = entry
    return {"mail_host": mail_host, "brand": brand, "domains": domains}


def render_postfix_domains(plan: dict) -> str:
    lines = [f"# {MANAGED_MARK}: written by mail.set_routing, do not edit", ""]
    lines += [f"{d} OK" for d, e in sorted(plan["domains"].items()) if e["provider"] == "rhis"]
    return "\n".join(lines) + "\n"


def render_snappymail_domain() -> str:
    """SnappyMail domain config: IMAP/Sieve on loopback, SMTP submission with auth."""
    ssl = {
        "verify_peer": False,
        "verify_peer_name": False,
        "allow_self_signed": True,
        "SNI_enabled": True,
        "disable_compression": True,
        "security_level": 1,
    }
    sasl = ["SCRAM-SHA-256", "PLAIN", "LOGIN"]
    conf = {
        "IMAP": {
            "host": "127.0.0.1", "port": 143, "type": 0, "timeout": 300,
            "shortLogin": False, "lowerLogin": True, "sasl": sasl, "ssl": ssl,
            "disabled_capabilities": [], "use_expunge_all_on_delete": False,
            "fast_simple_search": True, "force_select": False,
            "message_all_headers": False, "message_list_limit": 10000, "search_filter": "",
        },
        "SMTP": {
            # Submission over loopback with STARTTLS; the traffic never leaves the host.
            "host": "127.0.0.1", "port": 587, "type": 2, "timeout": 60,
            "shortLogin": False, "lowerLogin": True, "sasl": sasl, "ssl": ssl,
            "useAuth": True, "setSender": False, "usePhpMail": False,
        },
        "Sieve": {
            "host": "127.0.0.1", "port": 4190, "type": 0, "timeout": 10,
            "shortLogin": False, "lowerLogin": True, "sasl": sasl, "ssl": ssl, "enabled": True,
        },
        "whiteList": "",
        MANAGED_MARK: True,
    }
    return json.dumps(conf, indent=4) + "\n"


def endpoint_names(domain: str, entry: dict | None) -> list[str]:
    """Names that point at this server for a domain and need a certificate."""
    if entry is None or entry["provider"] == "external":
        return []
    names = []
    if entry["provider"] == "rhis":
        names += [f"autoconfig.{domain}", f"autodiscover.{domain}"]
    if "mta_sts" in entry:
        names.append(f"mta-sts.{domain}")
    return names


def _read_state() -> dict:
    try:
        return json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {"domains": {}}


# ---------------------------------------------------------------- actions


@action("mail.set_routing")
def set_routing(params: dict) -> dict:
    """Replace the full routing table. Send every domain each time."""
    plan = parse_routing(params)
    changed: list[str] = []

    if _write_atomic(POSTFIX_DOMAINS, render_postfix_domains(plan), 0o644):
        changed.append(str(POSTFIX_DOMAINS))

    if SNAPPYMAIL_DOMAINS.is_dir():
        wanted = {d for d, e in plan["domains"].items() if e["provider"] == "rhis"}
        body = render_snappymail_domain()
        for d in sorted(wanted):
            if _write_atomic(SNAPPYMAIL_DOMAINS / f"{d}.json", body, 0o640, SNAPPYMAIL_USER):
                changed.append(f"webmail:{d}")
        for f in SNAPPYMAIL_DOMAINS.glob("*.json"):
            if f.stem in wanted:
                continue
            try:
                ours = json.loads(f.read_text(encoding="utf-8")).get(MANAGED_MARK) is True
            except (OSError, ValueError):
                ours = False
            if ours:
                f.unlink()
                changed.append(f"webmail-removed:{f.stem}")

    if _write_atomic(STATE_FILE, json.dumps(plan, indent=2, sort_keys=True) + "\n", 0o644):
        changed.append(str(STATE_FILE))

    if str(POSTFIX_DOMAINS) in changed:
        run(["postfix", "reload"], timeout=30)

    return {
        "changed": changed,
        "local_domains": sorted(d for d, e in plan["domains"].items() if e["provider"] == "rhis"),
    }


def _dkim_paths(domain: str) -> tuple[Path, Path]:
    key = DKIM_DIR / f"{domain}.{DKIM_SELECTOR}.key"
    return key, key.with_suffix(".pub")


def _public_key(pub: Path) -> str:
    pem = pub.read_text(encoding="ascii")
    return "".join(line for line in pem.splitlines() if line and not line.startswith("-----"))


@action("mail.dkim_generate")
def dkim_generate(params: dict) -> dict:
    domain = v.domain(params, "domain")
    replace = v.boolean(params, "replace") if "replace" in params else False
    bits = params.get("bits", 2048)
    if bits not in (2048, 3072):
        raise ActionError("invalid_params", "bits must be 2048 or 3072")
    key, pub = _dkim_paths(domain)
    if key.exists() and not replace:
        raise ActionError("conflict", f"{domain} already has a DKIM key; pass replace=true to rotate it")

    DKIM_DIR.mkdir(parents=True, exist_ok=True)
    tmp = key.with_name(f".{key.name}.new")
    run(["openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", f"rsa_keygen_bits:{bits}", "-out", str(tmp)], timeout=60)
    run(["openssl", "pkey", "-in", str(tmp), "-pubout", "-out", str(pub)], timeout=30)
    os.chmod(tmp, 0o640)
    try:
        os.chown(tmp, 0, grp.getgrnam(RSPAMD_USER).gr_gid)
    except KeyError:
        pass
    os.replace(tmp, key)
    os.chmod(pub, 0o644)
    return {"domain": domain, "selector": DKIM_SELECTOR, "algorithm": "rsa", "bits": bits, "public_key": _public_key(pub)}


@action("mail.dkim_get")
def dkim_get(params: dict) -> dict:
    domain = v.domain(params, "domain")
    key, pub = _dkim_paths(domain)
    if not key.exists():
        raise ActionError("not_found", f"No DKIM key for {domain}")
    if not pub.exists():
        run(["openssl", "pkey", "-in", str(key), "-pubout", "-out", str(pub)], timeout=30)
    return {"domain": domain, "selector": DKIM_SELECTOR, "algorithm": "rsa", "public_key": _public_key(pub)}


def _issue_endpoint_cert(params: dict) -> dict:
    domain = params["domain"]
    names = params["names"]
    ACME_WEBROOT.mkdir(parents=True, exist_ok=True)
    argv = ["certbot", "certonly", "--webroot", "-w", str(ACME_WEBROOT), "--non-interactive",
            "--agree-tos", "--keep-until-expiring", "--expand", "--cert-name", f"mail-endpoints.{domain}"]
    if params.get("email"):
        argv += ["-m", params["email"]]
    else:
        argv += ["--register-unsafely-without-email"]
    for n in names:
        argv += ["-d", n]
    run(argv, timeout=300)
    run(["systemctl", "reload", "nginx"], timeout=30)
    return {"domain": domain, "names": names}


@action("mail.issue_endpoint_cert")
def issue_endpoint_cert(params: dict) -> dict:
    domain = v.domain(params, "domain")
    names = endpoint_names(domain, _read_state().get("domains", {}).get(domain))
    if not names:
        raise ActionError("not_found", f"{domain} has no mail endpoints on this server; run mail.set_routing first")
    email = params.get("email")
    if email is not None and (not isinstance(email, str) or not re.match(r"^[^@\s]+@[^@\s]+\.[a-z]{2,}$", email, re.I)):
        raise ActionError("invalid_params", "email must be an address")
    return start_job("mail.issue_endpoint_cert", _issue_endpoint_cert, {"domain": domain, "names": names, "email": email})
