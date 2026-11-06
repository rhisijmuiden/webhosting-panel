"""Plesk (Obsidian 18.x, also 17.x) as a migration source.

Everything is read from the psa database with the Plesk admin password in
/etc/psa/.psa.shadow, so it works on every Plesk for Linux without the API.
The columns that differ between Plesk versions are looked up in
information_schema first and left out when missing. Every query is run on its
own: if one still fails, it is retried without the optional columns, and only
then becomes a warning, so the rest of the inventory always works.

The inventory format is source independent (see docs/migration.md), so the
panel can show and migrate a DirectAdmin or cPanel inventory the same way.
"""

from __future__ import annotations

import re

from ..registry import ActionError
from .ssh import Connection, q

VHOSTS = "/var/www/vhosts"
MAILNAMES = "/var/qmail/mailnames"
CERT_DIR = "/usr/local/psa/var/certificates"
MAIL_AUTH_VIEW = "/usr/local/psa/admin/bin/mail_auth_view"

# The mysql client on the source, with the Plesk admin login, psa database.
_MYSQL = 'MYSQL_PWD="$(cat /etc/psa/.psa.shadow)" "$(command -v mysql || command -v mariadb)" -uadmin -N -B --default-character-set=utf8mb4'
_DUMP = 'MYSQL_PWD="$(cat /etc/psa/.psa.shadow)" "$(command -v mysqldump || command -v mariadb-dump)" -uadmin'

# Columns that differ between Plesk versions are written as {alias.column:default}.
# render_query() turns them into IFNULL(alias.column, default) when the column
# exists on the source, and into the default when it does not, so one schema
# change on a newer or older Plesk does not break a whole part of the inventory.
QUERIES = {
    "domains": (
        "SELECT d.id, d.name, {d.parentDomainId:0}, {d.webspace_id:0}, d.htype, {d.status:0}, {d.real_size:0}, d.cl_id,"
        " IFNULL(h.www_root,''), {h.php_handler_id:''}, IFNULL(su.login,''), {d.dns_zone_id:0}"
        " FROM domains d LEFT JOIN hosting h ON h.dom_id=d.id LEFT JOIN sys_users su ON su.id=h.sys_user_id ORDER BY d.name"
    ),
    "clients": (
        "SELECT clients.id, clients.login, {clients.type:'client'}, {clients.pname:''}, {clients.cname:''},"
        " {clients.email:''}, {clients.phone:''} FROM clients"
    ),
    "aliases": "SELECT domain_aliases.dom_id, domain_aliases.name, {domain_aliases.web:'true'}, {domain_aliases.mail:'false'} FROM domain_aliases",
    "databases": (
        "SELECT db.id, db.name, {db.type:'mysql'}, db.dom_id, IFNULL(s.host,'localhost'), IFNULL(s.port,'')"
        " FROM data_bases db LEFT JOIN DatabaseServers s ON s.id=db.db_server_id"
    ),
    "db_users": "SELECT db_id, login FROM db_users",
    # Newer Plesk dropped mail.redirect (forwards live only in mail_redir), older ones have redir_addr.
    "mail": (
        "SELECT m.id, m.dom_id, m.mail_name, {m.postbox:'true'}, {m.redirect:'auto'}, {m.mbox_quota:-1}, IFNULL(a.type,''), {m.redir_addr:''}"
        " FROM mail m LEFT JOIN accounts a ON a.id=m.account_id"
    ),
    "mail_redir": "SELECT mn_id, address FROM mail_redir",
    "mail_aliases": "SELECT mn_id, alias FROM mail_aliases",
    "dns": "SELECT dns_recs.dns_zone_id, dns_recs.type, dns_recs.host, dns_recs.val, {dns_recs.opt:''} FROM dns_recs",
    "certificates": "SELECT h.dom_id, {c.name:''}, IFNULL(c.cert_file,''), {c.ca_file:''} FROM hosting h JOIN certificates c ON c.id=h.certificate_id",
    "ips": "SELECT ip_address FROM IP_Addresses",
    # Subscriptions (webspaces): service plan, setup date and expiry date (unix time, -1 = never).
    "subscriptions": (
        "SELECT sb.object_id, IFNULL(t.name,''), {d.cr_date:''}, IFNULL(l.value,'')"
        " FROM Subscriptions sb JOIN domains d ON d.id=sb.object_id"
        " LEFT JOIN PlansSubscriptions ps ON ps.subscription_id=sb.id"
        " LEFT JOIN Templates t ON t.id=ps.plan_id AND t.type='domain'"
        " LEFT JOIN Limits l ON l.id={d.limits_id:0} AND l.limit_name='expiration'"
        " WHERE sb.object_type='domain'"
    ),
}

# Table behind each alias used in QUERIES.
ALIASES = {
    "d": "domains", "h": "hosting", "su": "sys_users", "db": "data_bases", "s": "DatabaseServers",
    "m": "mail", "a": "accounts", "c": "certificates", "sb": "Subscriptions", "t": "Templates", "l": "Limits",
}

_OPTIONAL = re.compile(r"\{(\w+)\.(\w+):([^}]*)\}")


def render_query(query: str, columns: dict[str, set[str]] | None) -> str:
    """Fill in the optional columns. Without schema information every column is assumed to exist."""
    def sub(m: re.Match) -> str:
        alias, column, default = m.group(1), m.group(2), m.group(3)
        table = ALIASES.get(alias, alias).lower()
        if columns is None or column.lower() in columns.get(table, set()):
            return f"IFNULL({alias}.{column},{default})"
        return default
    return _OPTIONAL.sub(sub, query)


def schema(conn: Connection) -> dict[str, set[str]] | None:
    """Lower-cased columns per psa table, or None when information_schema cannot be read."""
    try:
        rows = sql(conn, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='psa'")
    except ActionError:
        return None
    cols: dict[str, set[str]] = {}
    for r in rows:
        if len(r) >= 2:
            cols.setdefault(r[0].lower(), set()).add(r[1].lower())
    return cols or None

DNS_TYPES = {"A", "AAAA", "CNAME", "MX", "TXT", "SRV", "CAA"}


def detect(conn: Connection, first_contact: bool = False) -> dict:
    """Check the login and that the source runs Plesk."""
    proc = conn.run(
        "cat /usr/local/psa/version 2>/dev/null; echo '|'; hostname -f 2>/dev/null || hostname; echo '|';"
        " command -v rsync >/dev/null && echo rsync",
        timeout=60, first_contact=first_contact,
    )
    parts = [p.strip() for p in proc.stdout.split("|")]
    version = parts[0] if parts else ""
    if not version:
        raise ActionError("not_found", "This server does not run Plesk (/usr/local/psa/version is missing)")
    return {
        "type": "plesk",
        "version": version.split()[0] if version else "",
        "version_full": version,
        "hostname": parts[1] if len(parts) > 1 else "",
        "rsync": len(parts) > 2 and parts[2] == "rsync",
    }


def sql(conn: Connection, query: str, database: str = "psa", timeout: int = 120) -> list[list[str]]:
    proc = conn.run(f"{_MYSQL} -D {q(database)} -e {q(query)}", timeout=timeout)
    return parse_tsv(proc.stdout)


def parse_tsv(text: str) -> list[list[str]]:
    rows = []
    for line in text.splitlines():
        if line == "":
            continue
        rows.append([_unescape(c) for c in line.split("\t")])
    return rows


def _unescape(value: str) -> str:
    if value == "NULL":
        return ""
    return value.replace("\\t", "\t").replace("\\n", "\n").replace("\\\\", "\\")


def php_version(handler: str) -> str:
    """plesk-php82-fpm -> 8.2, plesk-php74-fastcgi -> 7.4, fpm / module -> ''."""
    m = re.search(r"php(\d)(\d+)", handler or "")
    return f"{m.group(1)}.{m.group(2)}" if m else ""


def _int(value: str, default: int = 0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _web_paths(domain: str, www_root: str) -> dict | None:
    """Split a Plesk document root into the copied base dir and the docroot inside it.

    /var/www/vhosts/example.com/httpdocs         -> base httpdocs, sub ''
    /var/www/vhosts/example.com/httpdocs/public  -> base httpdocs, sub 'public' (Laravel)
    /var/www/vhosts/example.com/shop.example.com -> base shop.example.com (subdomain)
    """
    if not www_root or not www_root.startswith(VHOSTS + "/"):
        return None
    rest = www_root[len(VHOSTS) + 1:].strip("/").split("/")
    if len(rest) < 2 or any(p in ("", ".", "..") for p in rest):
        return None
    return {"root": www_root, "vhost": rest[0], "base": f"{VHOSTS}/{rest[0]}/{rest[1]}", "sub": "/".join(rest[2:])}


def _dns_record(zone_domain: str, rtype: str, host: str, val: str, opt: str) -> dict | None:
    rtype = rtype.upper()
    if rtype not in DNS_TYPES:
        return None
    name = host.rstrip(".").lower()
    content = val.strip()
    rec = {"type": rtype, "name": name, "content": content}
    if rtype == "MX":
        rec["content"] = content.rstrip(".").lower()
        rec["priority"] = _int(opt, 10)
    elif rtype == "CNAME":
        rec["content"] = content.rstrip(".").lower()
    elif rtype == "SRV":
        # Plesk: host _sip._tcp.example.com., val target., opt "priority weight port"
        nums = opt.split()
        if len(nums) != 3:
            return None
        rec["srv"] = {"priority": _int(nums[0]), "weight": _int(nums[1]), "port": _int(nums[2]), "target": content.rstrip(".")}
        rec["content"] = f"{nums[1]} {nums[2]} {content.rstrip('.')}"
        rec["priority"] = _int(nums[0])
    elif rtype == "CAA":
        # val: 0 issue "letsencrypt.org" (Plesk stores flag, tag and value in val/opt over versions)
        rec["content"] = " ".join(p for p in (opt, content) if p) if not content[:1].isdigit() else content
    elif rtype == "TXT":
        rec["content"] = content.strip('"')
    return rec


def _date(value: str) -> str | None:
    """Plesk cr_date (YYYY-MM-DD or a datetime) as YYYY-MM-DD."""
    m = re.match(r"^(\d{4}-\d{2}-\d{2})", (value or "").strip())
    return m.group(1) if m and not m.group(1).startswith("0000") else None


def _expiry(value: str) -> str | None:
    """Limits.value of 'expiration': a unix timestamp, -1 (or empty) for never."""
    import datetime
    try:
        ts = int(str(value).strip())
    except ValueError:
        return None
    if ts <= 0:
        return None
    return datetime.datetime.fromtimestamp(ts, datetime.timezone.utc).strftime("%Y-%m-%d")


def build_inventory(rows: dict[str, list[list[str]]], meta: dict, sizes: dict[str, int], apps: dict[str, str]) -> dict:
    """Turn the raw query results into the source independent inventory (pure, unit tested)."""
    owners = {}
    for r in rows.get("clients", []):
        if len(r) < 7:
            continue
        owners[r[0]] = {"id": r[0], "login": r[1], "kind": r[2], "name": r[3] or r[1], "company": r[4], "email": r[5], "phone": r[6]}

    by_id: dict[str, dict] = {}
    zone_of: dict[str, str] = {}
    for r in rows.get("domains", []):
        if len(r) < 12:
            continue
        dom_id, name, parent, webspace, htype, status, size, cl_id, www_root, handler, sys_user, zone = r[:12]
        name = name.lower()
        kind = {"vrt_hst": "site", "std_fwd": "forward", "frm_fwd": "forward"}.get(htype, "none")
        web = _web_paths(name, www_root) if kind == "site" else None
        if web:
            web["system_user"] = sys_user
            web["app"] = apps.get(www_root, "php")
        by_id[dom_id] = {
            "name": name,
            "kind": kind,
            "is_subdomain": parent not in ("", "0"),
            "parent": None,
            "subscription": None,
            "owner_id": cl_id,
            "active": status in ("0", ""),
            "size_mb": _int(size) // (1024 * 1024),
            "php": php_version(handler),
            "web": web,
            "aliases": [],
            "databases": [],
            "mailboxes": [],
            "forwarders": [],
            "dns": [],
            "ssl": None,
            "notes": [],
        }
        by_id[dom_id]["_parent"] = parent
        by_id[dom_id]["_webspace"] = webspace
        if zone not in ("", "0"):
            zone_of[zone] = dom_id
    # Plesk subscription per webspace: service plan, setup date, expiry date.
    subs: dict[str, dict] = {}
    for r in rows.get("subscriptions", []):
        if len(r) < 4 or r[0] not in by_id:
            continue
        sub = subs.setdefault(r[0], {"plan": None, "created": None, "expires": None})
        sub["plan"] = sub["plan"] or (r[1].strip() or None)  # add-on plans have no 'domain' template row
        sub["created"] = sub["created"] or _date(r[2])
        sub["expires"] = sub["expires"] or _expiry(r[3])
    for dom_id, d in by_id.items():
        p = by_id.get(d.pop("_parent"))
        d["parent"] = p["name"] if p else None
        webspace = d.pop("_webspace")
        w = by_id.get(webspace)
        d["subscription"] = w["name"] if w else d["parent"] or d["name"]
        info = subs.get(webspace if w else dom_id, {})
        d["plan"] = info.get("plan")
        d["created"] = info.get("created")
        d["expires"] = info.get("expires")

    for r in rows.get("aliases", []):
        if len(r) >= 4 and r[0] in by_id:
            by_id[r[0]]["aliases"].append({"name": r[1].lower(), "web": r[2] == "true", "mail": r[3] == "true"})

    db_by_id = {}
    for r in rows.get("databases", []):
        if len(r) >= 6 and r[3] in by_id:
            db = {"name": r[1], "type": r[2], "server": r[4] if r[4] not in ("", "localhost", "127.0.0.1") else "local", "users": []}
            if db["type"] != "mysql":
                by_id[r[3]]["notes"].append(f"Database {r[1]} is {r[2]}; only MySQL/MariaDB databases are migrated.")
            elif db["server"] != "local":
                by_id[r[3]]["notes"].append(f"Database {r[1]} lives on database server {r[4]}; it is migrated through that server's admin login.")
            db_by_id[r[0]] = db
            by_id[r[3]]["databases"].append(db)
    for r in rows.get("db_users", []):
        if len(r) >= 2 and r[0] in db_by_id:
            db_by_id[r[0]]["users"].append(r[1])

    mail_by_id = {}
    redirects: dict[str, list[str]] = {}
    for r in rows.get("mail_redir", []):
        if len(r) >= 2:
            redirects.setdefault(r[0], []).append(r[1].strip().lower())
    aliases: dict[str, list[str]] = {}
    for r in rows.get("mail_aliases", []):
        if len(r) >= 2:
            aliases.setdefault(r[0], []).append(r[1].strip().lower())
    for r in rows.get("mail", []):
        if len(r) < 7 or r[1] not in by_id:
            continue
        mid, dom_id, local, postbox, redirect, quota, pwtype = r[:7]
        redir_addr = r[7].strip().lower() if len(r) > 7 else ""
        d = by_id[dom_id]
        local = local.lower()
        # redirect is 'auto' when this Plesk has no mail.redirect column: then mail_redir alone decides.
        fwd = list(redirects.get(mid, [])) if redirect in ("true", "auto") else []
        if redirect in ("true", "auto") and redir_addr and redir_addr not in fwd:
            fwd.append(redir_addr)
        if postbox == "true":
            quota_b = _int(quota, -1)
            box = {
                "local": local,
                "quota_mb": 0 if quota_b <= 0 else max(1, quota_b // (1024 * 1024)),
                "size_mb": sizes.get(f"{d['name']}/{local}", 0) // 1024,
                "password": {"plain": "plain", "sym": "plain", "crypt": "hash"}.get(pwtype, "none"),
                "aliases": aliases.get(mid, []),
                "forward": fwd,
            }
            if fwd:
                d["notes"].append(f"{local}@{d['name']} keeps a copy and forwards to {', '.join(fwd)}; set up the forward again after the migration.")
            d["mailboxes"].append(box)
            mail_by_id[mid] = box
        elif fwd:
            d["forwarders"].append({"local": local, "targets": fwd})
        for a in aliases.get(mid, []) if postbox != "true" else []:
            if fwd:
                d["forwarders"].append({"local": a, "targets": fwd})

    for r in rows.get("dns", []):
        if len(r) < 5 or r[0] not in zone_of:
            continue
        d = by_id[zone_of[r[0]]]
        rec = _dns_record(d["name"], r[1], r[2], r[3], r[4])
        if rec:
            d["dns"].append(rec)

    for r in rows.get("certificates", []):
        if len(r) >= 4 and r[0] in by_id and r[2]:
            by_id[r[0]]["ssl"] = {"name": r[1], "lets_encrypt": r[1].lower().startswith("lets encrypt")}

    domains = sorted(by_id.values(), key=lambda d: (d["subscription"] or "", d["is_subdomain"], d["name"]))
    used_owners = {d["owner_id"] for d in domains}
    return {
        "source": meta,
        "server_ips": sorted({r[0] for r in rows.get("ips", []) if r}),
        "owners": [o for k, o in owners.items() if k in used_owners],
        "domains": domains,
        "totals": {
            "domains": len(domains),
            "sites": sum(1 for d in domains if d["web"]),
            "databases": sum(len(d["databases"]) for d in domains),
            "mailboxes": sum(len(d["mailboxes"]) for d in domains),
            "size_mb": sum(d["size_mb"] for d in domains),
        },
    }


def inventory(conn: Connection, log=lambda line: None) -> dict:
    meta = detect(conn)
    log(f"Plesk {meta['version']} on {meta['hostname']}")
    rows: dict[str, list[list[str]]] = {}
    warnings = []
    columns = schema(conn)
    for key, query in QUERIES.items():
        try:
            rows[key] = _query_with_fallback(conn, query, columns)
            log(f"{key}: {len(rows[key])} rows")
        except ActionError as exc:
            rows[key] = []
            warnings.append(f"Could not read {key}: {exc.message}")
            log(f"{key}: failed ({exc.message})")

    sizes = mail_sizes(conn)
    roots = sorted({r[8] for r in rows.get("domains", []) if len(r) > 8 and r[8].startswith(VHOSTS + "/")})
    apps = detect_apps(conn, roots)
    inv = build_inventory(rows, meta, sizes, apps)
    inv["warnings"] = warnings
    return inv


def _query_with_fallback(conn: Connection, query: str, columns: dict[str, set[str]] | None) -> list[list[str]]:
    try:
        return sql(conn, render_query(query, columns))
    except ActionError:
        minimal = render_query(query, {})
        if minimal == render_query(query, columns):
            raise
        return sql(conn, minimal)


def mail_sizes(conn: Connection) -> dict[str, int]:
    """KB per mailbox, keyed domain/local."""
    proc = conn.run(f"cd {MAILNAMES} 2>/dev/null && du -sk */*/Maildir 2>/dev/null || true", timeout=900, check=False)
    sizes = {}
    for line in proc.stdout.splitlines():
        parts = line.split("\t")
        if len(parts) == 2 and parts[1].endswith("/Maildir"):
            sizes[parts[1][: -len("/Maildir")].lower()] = _int(parts[0])
    return sizes


def detect_apps(conn: Connection, roots: list[str]) -> dict[str, str]:
    """wordpress when wp-config.php is there, laravel when the docroot is <app>/public with artisan."""
    if not roots:
        return {}
    script = (
        "for p in " + " ".join(q(r) for r in roots) + "; do"
        ' if [ -f "$p/wp-config.php" ] || [ -f "$p/../wp-config.php" ]; then echo "$p	wordpress";'
        ' elif [ -f "$p/../artisan" ]; then echo "$p	laravel"; fi; done'
    )
    proc = conn.run(script, timeout=300, check=False)
    apps = {}
    for line in proc.stdout.splitlines():
        parts = line.split("\t")
        if len(parts) == 2:
            apps[parts[0]] = parts[1]
    return apps


# ---- per domain details used while transferring ---------------------------------

def domain_row(conn: Connection, domain: str) -> dict:
    rows = sql(conn, "SELECT d.id, IFNULL(h.www_root,'') FROM domains d LEFT JOIN hosting h ON h.dom_id=d.id WHERE d.name=" + _lit(domain))
    if not rows:
        raise ActionError("not_found", f"{domain} does not exist on the source server")
    return {"id": rows[0][0], "www_root": rows[0][1], "web": _web_paths(domain, rows[0][1])}


def mail_credentials(conn: Connection, domain: str) -> list[dict]:
    """Mailbox passwords. Plain text from mail_auth_view, or the stored crypt hash.

    Only returned to the panel over the socket, never written to a job file.
    """
    rows = sql(
        conn,
        "SELECT m.mail_name, IFNULL(a.type,''), IFNULL(a.password,'') FROM mail m JOIN domains d ON d.id=m.dom_id"
        " LEFT JOIN accounts a ON a.id=m.account_id WHERE m.postbox='true' AND d.name=" + _lit(domain),
    )
    plain = {}
    proc = conn.run(f"{MAIL_AUTH_VIEW} 2>/dev/null || true", timeout=300, check=False)
    plain = parse_mail_auth_view(proc.stdout)
    out = []
    for local, ptype, stored in rows:
        address = f"{local.lower()}@{domain}"
        if address in plain and plain[address]:
            out.append({"local": local.lower(), "scheme": "plain", "secret": plain[address]})
        elif ptype == "crypt" and re.match(r"^\$(1|5|6)\$", stored):
            out.append({"local": local.lower(), "scheme": "crypt", "secret": stored})
        elif ptype == "plain" and stored:
            out.append({"local": local.lower(), "scheme": "plain", "secret": stored})
        else:
            out.append({"local": local.lower(), "scheme": "none", "secret": ""})
    return out


def parse_mail_auth_view(text: str) -> dict[str, str]:
    """| address | flags | password | table -> {address: password}."""
    out = {}
    for line in text.splitlines():
        line = line.rstrip()
        if not line.startswith("|") or not line.endswith("|"):
            continue
        cells = line[1:-1].split("|")
        if len(cells) < 3:
            continue
        address = cells[0].strip().lower()
        if "@" not in address or address == "address":
            continue
        password = "|".join(cells[2:])
        # The table pads with one space on both sides.
        if password.startswith(" "):
            password = password[1:]
        if password.endswith(" "):
            password = password.rstrip(" ")
        out[address] = password
    return out


def mysql_user_hash(conn: Connection, login: str) -> str | None:
    """The mysql_native_password hash of a database user, so the site's config keeps working."""
    rows = sql(conn, "SELECT Host FROM mysql.user WHERE User=" + _lit(login), database="mysql")
    for (host,) in rows:
        proc = conn.run(f"{_MYSQL} -e {q('SHOW CREATE USER ' + _lit(login) + '@' + _lit(host))}", timeout=60, check=False)
        m = re.search(r"'(\*[0-9A-F]{40})'", proc.stdout)
        if m:
            return m.group(1)
    return None


def dump_command(database: str) -> str:
    return (
        f"{_DUMP} --single-transaction --quick --routines --triggers --events --hex-blob"
        f" --default-character-set=utf8mb4 --no-tablespaces --add-drop-table {q(database)}"
    )


def certificate(conn: Connection, domain: str) -> dict | None:
    """The certificate the domain uses in Plesk: {cert, key, chain} as PEM text."""
    rows = sql(
        conn,
        "SELECT c.cert_file, IFNULL(c.ca_file,'') FROM hosting h JOIN domains d ON d.id=h.dom_id"
        " JOIN certificates c ON c.id=h.certificate_id WHERE d.name=" + _lit(domain),
    )
    if not rows or not rows[0][0]:
        return None
    cert_file, ca_file = rows[0]
    for f in (cert_file, ca_file):
        if f and not re.fullmatch(r"[A-Za-z0-9_.-]+", f):
            raise ActionError("command_failed", "Unexpected certificate file name on the source")
    text = conn.run(f"cat {q(CERT_DIR + '/' + cert_file)}", timeout=60).stdout
    chain = conn.run(f"cat {q(CERT_DIR + '/' + ca_file)}", timeout=60, check=False).stdout if ca_file else ""
    return split_pem(text, chain)


def split_pem(text: str, chain: str = "") -> dict | None:
    key = re.search(r"-----BEGIN (?:RSA |EC )?PRIVATE KEY-----.+?-----END (?:RSA |EC )?PRIVATE KEY-----", text, re.S)
    certs = re.findall(r"-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----", text + "\n" + chain, re.S)
    if not key or not certs:
        return None
    seen, ordered = set(), []
    for c in certs:
        if c not in seen:
            seen.add(c)
            ordered.append(c)
    return {"key": key.group(0) + "\n", "fullchain": "\n".join(ordered) + "\n"}


def _lit(value: str) -> str:
    """A SQL string literal (the value is also validated by the caller)."""
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"
