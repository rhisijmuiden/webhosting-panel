"""Server migration (Plesk today; the actions are source independent).

  migrate.key           create/show the panel's SSH key for source servers
  migrate.connect       test the login, record the host key, detect the source type
  migrate.forget_host   drop a recorded host key (after a reinstall of the source)
  migrate.inventory     job: everything on the source, see docs/migration.md
  migrate.credentials   mailbox passwords of one domain (straight to the panel, never in a job file)
  migrate.transfer      job: copy one domain (files, databases, mailboxes, certificate)

The panel creates the customer, website and mailboxes first; transfer only
copies data into what already exists here.
"""

from __future__ import annotations

import json
import re

from .. import validate as v
from ..jobs import start_job
from ..migration import plesk, transfer
from ..migration.ssh import Connection, ensure_key
from ..registry import ActionError, action
from ..run import run

SOURCES = {"plesk": plesk}
_LOCAL = re.compile(r"^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$")


def _conn(params: dict) -> Connection:
    return Connection(params)


def _source(params: dict):
    kind = v.choice(params, "type", list(SOURCES), required=False, default="plesk")
    return SOURCES[kind]


@action("migrate.key")
def migrate_key(params):
    return ensure_key()


@action("migrate.connect")
def migrate_connect(params):
    ensure_key()
    conn = _conn(params)
    info = _source(params).detect(conn, first_contact=True)
    info["host_key"] = conn.host_key_fingerprint()
    if not info.get("rsync"):
        info["warning"] = "rsync is not installed on the source server. Install it there first (apt install rsync / dnf install rsync)."
    return info


@action("migrate.forget_host")
def migrate_forget_host(params):
    conn = _conn(params)
    conn.forget_host_key()
    return {"host": conn.host, "forgotten": True}


@action("migrate.inventory")
def migrate_inventory(params):
    conn = _conn(params)
    source = _source(params)

    def work(p, log):
        return source.inventory(conn, log)

    return start_job("migrate.inventory", work, params)


@action("migrate.credentials")
def migrate_credentials(params):
    conn = _conn(params)
    domain = v.domain(params, "domain")
    return {"domain": domain, "mailboxes": _source(params).mail_credentials(conn, domain)}


def _site(domain: str) -> dict:
    proc = run([transfer.WHP_SITE, "show", domain, "--json"], timeout=60)
    try:
        return json.loads(proc.stdout or "{}")
    except ValueError:
        raise ActionError("command_failed", "whp-site returned invalid JSON")


def parse_transfer(params: dict) -> dict:
    """Validate migrate.transfer params (pure, unit tested)."""
    plan = {"domain": v.domain(params, "domain"), "files": None, "databases": [], "mail": None, "ssl": False}
    files = params.get("files")
    if files is not None:
        if not isinstance(files, dict):
            raise ActionError("invalid_params", "files must be an object")
        target = v.string(files, "target", pattern=r"[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}", max_len=64)
        plan["files"] = {"target": target, "delete": v.boolean(files, "delete", required=False, default=True)}
    for i, db in enumerate(v.list_of(params, "databases", lambda x: x, required=False, max_items=200)):
        if not isinstance(db, dict):
            raise ActionError("invalid_params", f"databases[{i}] must be an object")
        name = v.string(db, "name", pattern=r"[A-Za-z0-9_]{1,64}", max_len=64)
        target = v.string(db, "target", required=False, default=name, pattern=r"[A-Za-z0-9_]{1,64}", max_len=64)
        users = v.list_of(db, "users", lambda u: v.string({"u": u}, "u", pattern=r"[A-Za-z0-9_.-]{1,80}", max_len=80),
                          required=False, max_items=50)
        plan["databases"].append({"name": name, "target": target, "users": users})
    mail = params.get("mail")
    if mail is not None:
        if not isinstance(mail, dict):
            raise ActionError("invalid_params", "mail must be an object")
        boxes = v.list_of(mail, "mailboxes", lambda b: b, max_items=5000)
        for b in boxes:
            if not isinstance(b, str) or not _LOCAL.match(b):
                raise ActionError("invalid_params", f"Invalid mailbox name {b!r}")
        plan["mail"] = {"mode": v.choice(mail, "mode", ["mirror", "add"], required=False, default="mirror"), "mailboxes": boxes}
    plan["ssl"] = v.boolean(params, "ssl", required=False, default=False)
    return plan


@action("migrate.transfer")
def migrate_transfer(params):
    conn = _conn(params)
    source = _source(params)
    plan = parse_transfer(params)
    domain = plan["domain"]
    site = _site(domain) if plan["files"] or plan["ssl"] else None

    def work(p, log):
        result = {"domain": domain, "files": None, "databases": [], "mail": [], "ssl": None, "errors": []}
        row = source.domain_row(conn, domain)

        if plan["files"]:
            web = row["web"]
            if not web:
                result["errors"].append("files: the domain has no web directory on the source")
            else:
                log(f"files: {web['base']} -> {site['root']}/{plan['files']['target']}")
                try:
                    result["files"] = transfer.copy_site_files(conn, web["base"], site, plan["files"]["target"], plan["files"]["delete"])
                    log(f"files: done {result['files']['stats']}")
                except ActionError as exc:
                    result["errors"].append(f"files: {exc.message}")
                    log(f"files: failed: {exc.message}")

        for db in plan["databases"]:
            log(f"database {db['name']} -> {db['target']}")
            entry = {"name": db["name"], "target": db["target"], "users": [], "ok": False}
            try:
                transfer.import_database(conn, source.dump_command(db["name"]), db["target"], log)
                for login in db["users"]:
                    entry["users"].append(transfer.ensure_db_user(login, db["target"], source.mysql_user_hash(conn, login)))
                entry["ok"] = True
                log(f"database {db['name']}: done")
            except ActionError as exc:
                entry["error"] = exc.message
                result["errors"].append(f"database {db['name']}: {exc.message}")
                log(f"database {db['name']}: failed: {exc.message}")
            result["databases"].append(entry)

        if plan["mail"]:
            for local in plan["mail"]["mailboxes"]:
                src = f"{source.MAILNAMES}/{domain}/{local}/Maildir"
                log(f"mail {local}@{domain}: start")
                try:
                    r = transfer.copy_maildir(conn, src, domain, local, plan["mail"]["mode"])
                    result["mail"].append({"local": local, "ok": True, "stats": r["stats"]})
                    log(f"mail {local}@{domain}: done {r['stats']}")
                except ActionError as exc:
                    result["mail"].append({"local": local, "ok": False, "error": exc.message})
                    result["errors"].append(f"mail {local}: {exc.message}")
                    log(f"mail {local}@{domain}: failed: {exc.message}")

        if plan["ssl"]:
            if site and site.get("ssl") == "active":
                result["ssl"] = {"installed": False, "reason": "lets_encrypt_active"}
            else:
                try:
                    pem = source.certificate(conn, domain)
                    result["ssl"] = transfer.install_certificate(domain, pem) if pem else {"installed": False, "reason": "none"}
                except ActionError as exc:
                    result["ssl"] = {"installed": False, "reason": "error", "error": exc.message}
                    result["errors"].append(f"ssl: {exc.message}")
            log(f"ssl: {result['ssl']}")

        result["status"] = "ok" if not result["errors"] else "partial"
        return result

    return start_job("migrate.transfer", work, params)
