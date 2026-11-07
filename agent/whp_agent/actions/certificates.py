"""SSL certificates per website: own (uploaded) certificates, wildcard Let's
Encrypt certificates through Cloudflare DNS-01, and certificate details for
the panel's expiry warnings. The work is done by the installer's whp-site."""

import json
import os
import re
import shutil
import tempfile
from datetime import datetime, timezone

from .. import validate as v
from ..registry import ActionError, action
from ..run import run
from .sites import WHP_SITE

CLOUDFLARE_INI = "/etc/webhosting-panel/ssl/cloudflare.ini"
MAX_PEM = 64 * 1024
_PEM_BLOCK = re.compile(r"-----BEGIN ([A-Z0-9 ]+)-----\r?\n[A-Za-z0-9+/=\r\n]+-----END \1-----")
_TOKEN = re.compile(r"[A-Za-z0-9_-]{20,200}")


def _site(argv, timeout=120):
    proc = run([WHP_SITE, *argv, "--json"], timeout=timeout)
    try:
        return json.loads(proc.stdout or "{}")
    except ValueError:
        raise ActionError("command_failed", "whp-site returned invalid JSON")


def _pem(params, key, kinds, required=True):
    """A PEM text with only blocks of the given kinds (no other text that could be an option)."""
    value = params.get(key)
    if value in (None, ""):
        if required:
            raise ActionError("invalid_params", f"Missing parameter: {key}")
        return None
    if not isinstance(value, str) or len(value) > MAX_PEM or "\x00" in value:
        raise ActionError("invalid_params", f"{key} must be PEM text of at most {MAX_PEM} bytes")
    blocks = list(_PEM_BLOCK.finditer(value))
    if not blocks or any(b.group(1) not in kinds for b in blocks):
        raise ActionError("invalid_params", f"{key} is not a PEM {' or '.join(sorted(kinds))}")
    rest = _PEM_BLOCK.sub("", value)
    if rest.strip():
        raise ActionError("invalid_params", f"{key} contains text outside the PEM blocks")
    return "\n".join(b.group(0).replace("\r", "") for b in blocks) + "\n"


def _date(text):
    text = re.sub(r"\s+", " ", text.strip())
    return datetime.strptime(text, "%b %d %H:%M:%S %Y GMT").replace(tzinfo=timezone.utc)


def read_certificate(path):
    """Details of the first certificate in a PEM file, or None when it cannot be read."""
    if not path or not os.path.isfile(path):
        return None
    proc = run(["openssl", "x509", "-in", path, "-noout", "-subject", "-issuer", "-startdate", "-enddate",
                "-serial", "-fingerprint", "-sha256", "-ext", "subjectAltName", "-nameopt", "RFC2253"],
               timeout=20, check=False)
    if proc.returncode != 0:
        return None
    info = {"subject": None, "issuer": None, "not_before": None, "not_after": None, "serial": None,
            "fingerprint_sha256": None, "names": []}
    lines = proc.stdout.splitlines()
    for i, line in enumerate(lines):
        if line.startswith("subject="):
            info["subject"] = line[8:].strip()
        elif line.startswith("issuer="):
            info["issuer"] = line[7:].strip()
        elif line.startswith("notBefore="):
            info["not_before"] = _date(line[10:])
        elif line.startswith("notAfter="):
            info["not_after"] = _date(line[9:])
        elif line.startswith("serial="):
            info["serial"] = line[7:].strip()
        elif "Fingerprint=" in line:
            info["fingerprint_sha256"] = line.split("=", 1)[1].strip()
        elif "Subject Alternative Name" in line and i + 1 < len(lines):
            info["names"] = [n.strip()[4:] for n in lines[i + 1].split(",") if n.strip().startswith("DNS:")]
    cn = re.search(r"(?:^|,)CN=([^,]+)", info["subject"] or "")
    if not info["names"] and cn:
        info["names"] = [cn.group(1)]
    now = datetime.now(timezone.utc)
    after = info["not_after"]
    with open(path, encoding="ascii", errors="replace") as f:
        chain = f.read().count("BEGIN CERTIFICATE") - 1
    return {
        **info,
        "common_name": cn.group(1) if cn else None,
        "not_before": info["not_before"].isoformat() if info["not_before"] else None,
        "not_after": after.isoformat() if after else None,
        "days_left": int((after - now).total_seconds() // 86400) if after else None,
        "expired": bool(after and after <= now),
        "self_signed": bool(info["subject"] and info["subject"] == info["issuer"]),
        "chain_length": max(chain, 0),
    }


def _with_details(entry):
    entry = dict(entry)
    entry["certificate"] = read_certificate(entry.pop("cert_file", None))
    entry.pop("key_file", None)
    return entry


@action("ssl.info")
def ssl_info(params):
    """The certificate a website serves: source (ssl), names, issuer, validity."""
    return _with_details(_site(["cert", v.domain(params, "domain")], timeout=30))


@action("ssl.list")
def ssl_list(params):
    """Certificates of every website on the server (for the daily expiry check)."""
    data = _site(["cert", "--all"], timeout=120)
    return {"certificates": [_with_details(c) for c in data.get("certificates", [])]}


@action("ssl.custom")
def ssl_custom(params):
    """Install an own certificate: PEM certificate, private key and optional intermediates."""
    domain = v.domain(params, "domain")
    cert = _pem(params, "certificate", {"CERTIFICATE"})
    key = _pem(params, "private_key", {"PRIVATE KEY", "EC PRIVATE KEY", "RSA PRIVATE KEY"})
    chain = _pem(params, "chain", {"CERTIFICATE"}, required=False)
    if cert.count("BEGIN CERTIFICATE") > 1:
        # A full chain pasted as the certificate: first is the site, the rest the chain.
        blocks = [b.group(0) for b in _PEM_BLOCK.finditer(cert)]
        cert = blocks[0] + "\n"
        chain = "\n".join(blocks[1:]) + "\n" + (chain or "")
    if key.count("BEGIN") != 1:
        raise ActionError("invalid_params", "private_key must hold exactly one key")
    tmp = tempfile.mkdtemp(prefix="whp-ssl-")
    try:
        argv = ["ssl", domain]
        for name, text, opt in (("cert.pem", cert, "--custom-cert"), ("key.pem", key, "--custom-key"),
                                ("chain.pem", chain, "--custom-chain")):
            if not text:
                continue
            path = os.path.join(tmp, name)
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w") as f:
                f.write(text)
            argv += [opt, path]
        _site(argv, timeout=120)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
    return ssl_info({"domain": domain})


@action("ssl.wildcard")
def ssl_wildcard(params):
    """Wildcard Let's Encrypt certificate (domain + *.domain) through Cloudflare DNS-01."""
    domain = v.domain(params, "domain")
    if params.get("cloudflare_token"):
        _write_cloudflare(params)
    _site(["ssl", domain, "--wildcard"], timeout=600)
    return ssl_info({"domain": domain})


@action("ssl.letsencrypt")
def ssl_letsencrypt(params):
    """Back to the normal automatic Let's Encrypt certificate (HTTP-01)."""
    domain = v.domain(params, "domain")
    _site(["ssl", domain, "--letsencrypt"], timeout=300)
    return ssl_info({"domain": domain})


def _write_cloudflare(params):
    token = params.get("cloudflare_token")
    if not isinstance(token, str) or not _TOKEN.fullmatch(token):
        raise ActionError("invalid_params", "cloudflare_token must be a Cloudflare API token")
    os.makedirs(os.path.dirname(CLOUDFLARE_INI), mode=0o700, exist_ok=True)
    tmp = CLOUDFLARE_INI + ".new"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as f:
        f.write("# Managed by the Webhosting Panel: DNS-01 challenges for wildcard certificates.\n")
        f.write(f"dns_cloudflare_api_token = {token}\n")
    os.chmod(tmp, 0o600)
    os.replace(tmp, CLOUDFLARE_INI)


@action("ssl.cloudflare")
def ssl_cloudflare(params):
    """Store (or remove, with an empty token) the Cloudflare API token that certbot renewals use."""
    if params.get("cloudflare_token") in (None, ""):
        try:
            os.remove(CLOUDFLARE_INI)
        except FileNotFoundError:
            pass
        return {"stored": False}
    _write_cloudflare(params)
    return {"stored": True}
