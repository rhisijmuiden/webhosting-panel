"""MariaDB databases and database users that belong to a website.

Every database gets its own user with rights on that database only, from
localhost (the "main user"). A website can have extra users with read and
write, or read-only, rights on one or more of its databases. The agent talks
to MariaDB as root over the unix socket, which the installer leaves as the
only way in for root. SQL goes in on stdin, never on the command line; names
are checked against strict patterns and every string literal is escaped.

Which database and user belongs to which site is kept in
/var/lib/webhosting-panel/databases.json and db-users.json, so a site can
only ever change or drop its own databases and users.

phpMyAdmin single sign-on (db.sso): the agent creates a short-lived MariaDB
user with rights on one database, writes its login to a one-time token file
in /run/whp-pma/ (only the phpMyAdmin PHP pool can read it) and returns the
token. The panel sends the browser to phpMyAdmin with that token; the
password never reaches the panel or the browser. Expired sign-on users are
dropped by db.sso_cleanup (the panel runs it every few minutes).
"""

import json
import os
import pwd
import re
import secrets
import string
import threading
import time

from .. import paths
from .. import validate as v
from ..registry import ActionError, action
from ..run import run

MARIADB = ["mariadb", "--protocol=socket", "-uroot", "--batch", "--skip-column-names"]
# Names the agent makes itself (and new names a customer picks).
DB_NAME = re.compile(r"^[a-z0-9_]{1,64}$")
# Names of databases and users that already exist (migrated from Plesk, ...).
EXISTING_DB = re.compile(r"^[A-Za-z0-9_]{1,64}$")
EXISTING_USER = re.compile(r"^[A-Za-z0-9_.-]{1,80}$")
SUFFIX = re.compile(r"^[a-z0-9][a-z0-9_]{0,31}$")
RESERVED_DATABASES = {"mysql", "information_schema", "performance_schema", "sys", "whp_panel", "snappymail", "phpmyadmin", "test"}
RESERVED_USERS = {"root", "mysql", "mariadb.sys", "whp_panel", "debian-sys-maint", "phpmyadmin"}
SSO_PREFIX = "whp_pma_"
SSO_USER = re.compile(r"^whp_pma_[a-f0-9]{12}$")
SSO_TTL = 8 * 3600          # how long a phpMyAdmin sign-on user lives
SSO_TOKEN_TTL = 120         # how long the browser has to use the token
SSO_DIR = os.environ.get("WHP_PMA_TOKEN_DIR", "/run/whp-pma")
SSO_OWNER = "whp-pma"
PASSWORD = re.compile(r"^[\x20-\x7e]{12,128}$")
RW = "ALL PRIVILEGES"
RO = "SELECT, SHOW VIEW"
_lock = threading.RLock()


# ---------------------------------------------------------------- state

def _path(name):
    return os.path.join(paths.STATE_DIR, name)


def _read(name):
    try:
        with open(_path(name)) as fh:
            data = json.load(fh)
            return data if isinstance(data, dict) else {}
    except (FileNotFoundError, ValueError):
        return {}


def _write(name, data):
    os.makedirs(paths.STATE_DIR, exist_ok=True)
    tmp = _path(name) + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(data, fh, indent=1, sort_keys=True)
    os.chmod(tmp, 0o600)
    os.replace(tmp, _path(name))


def _state_file():
    return _path("databases.json")


def _load():
    return _read("databases.json")


def _save(data):
    _write("databases.json", data)


def _load_users():
    """Extra users: {user: {domain, databases: [...], readonly, created}}."""
    return _read("db-users.json")


def _save_users(data):
    _write("db-users.json", data)


def _load_sso():
    return _read("db-sso.json")


def _save_sso(data):
    _write("db-sso.json", data)


# ---------------------------------------------------------------- SQL helpers

def sql(statements, timeout=60):
    """Run SQL as the MariaDB root user. Returns stdout."""
    return run(MARIADB, input_text=statements, timeout=timeout).stdout


def _ident(name):
    return "`" + name.replace("`", "``") + "`"


def _lit(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def _account(user):
    return f"{_lit(user)}@'localhost'"


def password(length=32):
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


def _exists(kind, name):
    if kind == "database":
        q = f"SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME={_lit(name)};\n"
    else:
        q = f"SELECT COUNT(*) FROM mysql.global_priv WHERE User={_lit(name)} AND Host='localhost';\n"
    return sql(q).strip() not in ("", "0")


def _connections_sql(user, limit):
    # 0 = no own limit (the server-wide max_connections still applies).
    return f"ALTER USER {_account(user)} WITH MAX_USER_CONNECTIONS {int(limit or 0)};\n"


def _grant_sql(user, databases, readonly):
    privileges = RO if readonly else RW
    return "".join(f"GRANT {privileges} ON {_ident(db)}.* TO {_account(user)};\n" for db in databases)


# ---------------------------------------------------------------- names

def prefix_for(domain):
    """The start of every name a customer picks for this site: bakkerij_nl_."""
    return re.sub(r"[^a-z0-9]+", "_", domain.lower()).strip("_")[:20].strip("_") + "_"


def name_for(domain, label):
    """A database (and user) name like klant_nl_wp_3f9a: readable and unique."""
    base = re.sub(r"[^a-z0-9]+", "_", domain.lower()).strip("_")[:28]
    label = re.sub(r"[^a-z0-9]+", "", label.lower())[:8] or "db"
    return f"{base}_{label}_{secrets.token_hex(2)}"


def _custom_name(domain, suffix, key="name"):
    if not isinstance(suffix, str) or not SUFFIX.match(suffix):
        raise ActionError("invalid_params", f"{key} may only contain a-z, 0-9 and _ (at most 32 characters)")
    name = prefix_for(domain) + suffix
    if len(name) > 64 or name in RESERVED_DATABASES or name.startswith(SSO_PREFIX):
        raise ActionError("invalid_params", f"{key} is too long or not allowed")
    return name


def _password_param(params, key="password"):
    value = params.get(key)
    if value is None or value == "":
        return password()
    if not isinstance(value, str) or not PASSWORD.match(value):
        raise ActionError("invalid_params", "The password must be 12 to 128 normal characters")
    return value


def _site_limit(domain):
    return _limits().get(domain)


# ---------------------------------------------------------------- databases

def create_for_site(domain, label, name=None, pw=None):
    """Create a database and its user for a site. Returns name, user, password, host."""
    with _lock:
        data = _load()
        users = _load_users()
        if name is None:
            for _ in range(20):
                name = name_for(domain, label)
                if name not in data:
                    break
            else:  # pragma: no cover - 65536 tries per label
                raise ActionError("conflict", "Could not find a free database name")
        if not DB_NAME.match(name):  # pragma: no cover - name_for only makes valid names
            raise ActionError("internal", "Generated an invalid database name")
        if name in data or name in users or _exists("database", name) or _exists("user", name):
            raise ActionError("conflict", f"The name {name} is already in use")
        pw = pw or password()
        sql(
            f"CREATE DATABASE {_ident(name)} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            f"CREATE USER {_account(name)} IDENTIFIED BY {_lit(pw)};\n"
            + _grant_sql(name, [name], False)
            + _connections_sql(name, _site_limit(domain))
            + "FLUSH PRIVILEGES;\n"
        )
        data[name] = {"domain": domain, "user": name, "label": label, "created": int(time.time())}
        _save(data)
    return {"name": name, "user": name, "password": pw, "host": "localhost"}


def drop(name, domain):
    """Drop a database and its main user, only when it belongs to domain.
    Extra users lose their rights on it; extra users left without a database are dropped too."""
    if not EXISTING_DB.match(name or ""):
        raise ActionError("invalid_params", "Invalid database name")
    with _lock:
        data = _load()
        entry = data.get(name)
        if entry is None or entry.get("domain") != domain:
            raise ActionError("not_found", f"Database {name} does not belong to {domain}")
        users = _load_users()
        dropped_users = []
        statements = [f"DROP DATABASE IF EXISTS {_ident(name)};\n",
                      f"DROP USER IF EXISTS {_account(entry.get('user', name))};\n"]
        for user, u in list(users.items()):
            if u.get("domain") != domain or name not in u.get("databases", []):
                continue
            u["databases"] = [d for d in u["databases"] if d != name]
            if u["databases"]:
                statements.append(f"REVOKE ALL PRIVILEGES ON {_ident(name)}.* FROM {_account(user)};\n")
            else:
                statements.append(f"DROP USER IF EXISTS {_account(user)};\n")
                dropped_users.append(user)
                del users[user]
        sso = _load_sso()
        for user, s in list(sso.items()):
            if s.get("database") == name:
                statements.append(f"DROP USER IF EXISTS {_account(user)};\n")
                del sso[user]
        sql("".join(statements) + "FLUSH PRIVILEGES;\n")
        del data[name]
        _save(data)
        _save_users(users)
        _save_sso(sso)
    return {"name": name, "dropped": True, "dropped_users": dropped_users}


def owned_by(domain):
    return sorted(n for n, e in _load().items() if e.get("domain") == domain)


def _own_database(domain, name):
    if not isinstance(name, str) or not EXISTING_DB.match(name):
        raise ActionError("invalid_params", "Invalid database name")
    entry = _load().get(name)
    if entry is None or entry.get("domain") != domain:
        raise ActionError("not_found", f"Database {name} does not belong to {domain}")
    return entry


def _sizes():
    out = sql(
        "SELECT table_schema, COALESCE(SUM(data_length + index_length), 0), COUNT(*) "
        "FROM information_schema.tables GROUP BY table_schema;\n"
    )
    sizes = {}
    for line in out.splitlines():
        parts = line.split("\t")
        if len(parts) >= 2 and parts[1].isdigit():
            sizes[parts[0]] = (int(parts[1]), int(parts[2]) if len(parts) > 2 and parts[2].isdigit() else None)
    return sizes


@action("db.list")
def db_list(params):
    """Databases (with their size, table count and users) and the extra users of one site, or of all sites."""
    domain = v.domain(params, "domain", required=False)
    data = _load()
    extra = _load_users()
    try:
        sizes = _sizes()
    except ActionError:
        sizes = {}
    rows = []
    for name, entry in sorted(data.items()):
        if domain and entry.get("domain") != domain:
            continue
        size, tables = sizes.get(name, (0, 0))
        main = entry.get("user", name)
        access = [{"user": main, "readonly": False, "main": True}] + [
            {"user": u, "readonly": bool(e.get("readonly")), "main": False}
            for u, e in sorted(extra.items()) if e.get("domain") == entry.get("domain") and name in e.get("databases", [])]
        rows.append({"name": name, "user": main, "domain": entry.get("domain"),
                     "label": entry.get("label"), "created": entry.get("created"),
                     "size_bytes": size, "tables": tables, "users": access})
    users = [{"user": u, "domain": e.get("domain"), "databases": e.get("databases", []),
              "readonly": bool(e.get("readonly")), "created": e.get("created")}
             for u, e in sorted(extra.items()) if not domain or e.get("domain") == domain]
    return {"databases": rows, "users": users, "prefix": prefix_for(domain) if domain else None}


@action("db.create")
def db_create(params):
    """Create a database for a site. The password is returned once and not stored.
    With name, the database is called <site prefix><name>; otherwise a name is made up."""
    domain = v.domain(params, "domain")
    label = v.string(params, "label", required=False, default="db", max_len=8, pattern=r"[a-z0-9]+")
    suffix = params.get("name")
    name = _custom_name(domain, suffix) if suffix not in (None, "") else None
    return create_for_site(domain, label, name=name, pw=_password_param(params))


@action("db.drop")
def db_drop(params):
    return drop(v.string(params, "name", max_len=64, pattern=EXISTING_DB.pattern[1:-1]), v.domain(params, "domain"))


@action("db.adopt")
def db_adopt(params):
    """Register a database that already exists in MariaDB (a migrated one) as the site's own,
    with user as its main user. Refused when it belongs to another site or is a system database."""
    domain = v.domain(params, "domain")
    name = v.string(params, "name", max_len=64, pattern=EXISTING_DB.pattern[1:-1])
    user = v.string(params, "user", required=False, max_len=80, pattern=EXISTING_USER.pattern[1:-1]) or name
    if name.lower() in RESERVED_DATABASES or user.lower() in RESERVED_USERS or user.startswith(SSO_PREFIX):
        raise ActionError("invalid_params", "This database or user cannot be adopted")
    with _lock:
        data = _load()
        entry = data.get(name)
        if entry is not None:
            if entry.get("domain") != domain:
                raise ActionError("conflict", f"Database {name} belongs to another website")
            return {"name": name, "user": entry.get("user", name), "adopted": False}
        if any(e.get("user") == user for e in data.values()) or user in _load_users():
            raise ActionError("conflict", f"User {user} already belongs to another database")
        if not _exists("database", name):
            raise ActionError("not_found", f"Database {name} does not exist")
        if not _exists("user", user):
            pw = password()
            sql(f"CREATE USER {_account(user)} IDENTIFIED BY {_lit(pw)};\n" + _grant_sql(user, [name], False)
                + _connections_sql(user, _site_limit(domain)) + "FLUSH PRIVILEGES;\n")
        data[name] = {"domain": domain, "user": user, "label": "adopted", "created": int(time.time())}
        _save(data)
    return {"name": name, "user": user, "adopted": True}


# ---------------------------------------------------------------- users

def _own_user(domain, user):
    """('main', database) or ('extra', entry) for a user of this site."""
    if not isinstance(user, str) or not EXISTING_USER.match(user):
        raise ActionError("invalid_params", "Invalid user name")
    for name, entry in _load().items():
        if entry.get("user", name) == user and entry.get("domain") == domain:
            return "main", name
    entry = _load_users().get(user)
    if entry is not None and entry.get("domain") == domain:
        return "extra", entry
    raise ActionError("not_found", f"User {user} does not belong to {domain}")


def _databases_param(domain, params):
    dbs = params.get("databases")
    if not isinstance(dbs, list) or not dbs or len(dbs) > 100:
        raise ActionError("invalid_params", "Choose at least one database")
    out = []
    for name in dbs:
        _own_database(domain, name)
        if name not in out:
            out.append(name)
    return out


@action("db.user_create")
def db_user_create(params):
    """An extra user <site prefix><name> with rights on one or more databases of the site.
    The password (given, or made up) is returned once and not stored."""
    domain = v.domain(params, "domain")
    user = _custom_name(domain, params.get("name"))
    readonly = bool(v.boolean(params, "readonly", required=False, default=False))
    pw = _password_param(params)
    with _lock:
        dbs = _databases_param(domain, params)
        users = _load_users()
        if user in users or user in _load() or any(e.get("user") == user for e in _load().values()) or _exists("user", user):
            raise ActionError("conflict", f"The name {user} is already in use")
        sql(f"CREATE USER {_account(user)} IDENTIFIED BY {_lit(pw)};\n" + _grant_sql(user, dbs, readonly)
            + _connections_sql(user, _site_limit(domain)) + "FLUSH PRIVILEGES;\n")
        users[user] = {"domain": domain, "databases": dbs, "readonly": readonly, "created": int(time.time())}
        _save_users(users)
    return {"user": user, "password": pw, "databases": dbs, "readonly": readonly, "host": "localhost"}


@action("db.user_update")
def db_user_update(params):
    """Change which databases an extra user may use, and whether read-only."""
    domain = v.domain(params, "domain")
    user = v.string(params, "user", max_len=80, pattern=EXISTING_USER.pattern[1:-1])
    readonly = bool(v.boolean(params, "readonly", required=False, default=False))
    with _lock:
        kind, _ = _own_user(domain, user)
        if kind != "extra":
            raise ActionError("invalid_params", "The main user of a database always has full rights on it")
        dbs = _databases_param(domain, params)
        users = _load_users()
        old = users[user].get("databases", [])
        revoke = "".join(f"REVOKE ALL PRIVILEGES ON {_ident(db)}.* FROM {_account(user)};\n"
                         for db in old if EXISTING_DB.match(db))
        # REVOKE fails on a grant that does not exist: run it on its own and ignore that.
        if revoke:
            run(MARIADB + ["--force"], input_text=revoke, timeout=60, check=False)
        sql(_grant_sql(user, dbs, readonly) + "FLUSH PRIVILEGES;\n")
        users[user].update({"databases": dbs, "readonly": readonly})
        _save_users(users)
    return {"user": user, "databases": dbs, "readonly": readonly}


@action("db.user_drop")
def db_user_drop(params):
    domain = v.domain(params, "domain")
    user = v.string(params, "user", max_len=80, pattern=EXISTING_USER.pattern[1:-1])
    with _lock:
        kind, _ = _own_user(domain, user)
        if kind != "extra":
            raise ActionError("invalid_params", "The main user is removed together with its database")
        sql(f"DROP USER IF EXISTS {_account(user)};\nFLUSH PRIVILEGES;\n")
        users = _load_users()
        del users[user]
        _save_users(users)
    return {"user": user, "dropped": True}


@action("db.password")
def db_password(params):
    """New password for a main or extra user of the site (given, or made up). Returned once."""
    domain = v.domain(params, "domain")
    user = v.string(params, "user", max_len=80, pattern=EXISTING_USER.pattern[1:-1])
    pw = _password_param(params)
    with _lock:
        _own_user(domain, user)
        sql(f"ALTER USER {_account(user)} IDENTIFIED BY {_lit(pw)};\nFLUSH PRIVILEGES;\n")
    return {"user": user, "password": pw}


# ---------------------------------------------------------------- phpMyAdmin sign-on

def _sso_owner():
    try:
        p = pwd.getpwnam(SSO_OWNER)
        return p.pw_uid, p.pw_gid
    except KeyError:
        raise ActionError("unavailable", "phpMyAdmin is not installed on this server")


def _cleanup_sso(now=None):
    """Drop sign-on users that expired, and token files nobody used. Caller holds _lock."""
    now = now or int(time.time())
    sso = _load_sso()
    expired = [u for u, s in sso.items() if int(s.get("expires", 0)) <= now and SSO_USER.match(u)]
    if expired:
        sql("".join(f"DROP USER IF EXISTS {_account(u)};\n" for u in expired) + "FLUSH PRIVILEGES;\n")
        for u in expired:
            del sso[u]
        _save_sso(sso)
    removed_tokens = 0
    if os.path.isdir(SSO_DIR):
        for f in os.listdir(SSO_DIR):
            full = os.path.join(SSO_DIR, f)
            try:
                if f.endswith(".json") and os.path.getmtime(full) < now - SSO_TOKEN_TTL:
                    os.unlink(full)
                    removed_tokens += 1
            except FileNotFoundError:
                pass
    return {"dropped_users": expired, "removed_tokens": removed_tokens}


@action("db.sso")
def db_sso(params):
    """A one-time phpMyAdmin login for one database of the site. Returns the token for
    /phpmyadmin/whp-sso.php; the login itself only goes to phpMyAdmin."""
    domain = v.domain(params, "domain")
    name = v.string(params, "name", max_len=64, pattern=EXISTING_DB.pattern[1:-1])
    lang = v.string(params, "lang", required=False, default="en", max_len=5, pattern=r"[a-z]{2}")
    uid, gid = _sso_owner()
    with _lock:
        _own_database(domain, name)
        _cleanup_sso()
        user = SSO_PREFIX + secrets.token_hex(6)
        pw = password(40)
        now = int(time.time())
        sql(f"CREATE USER {_account(user)} IDENTIFIED BY {_lit(pw)};\n" + _grant_sql(user, [name], False)
            + _connections_sql(user, _site_limit(domain)) + "FLUSH PRIVILEGES;\n")
        sso = _load_sso()
        sso[user] = {"domain": domain, "database": name, "created": now, "expires": now + SSO_TTL}
        _save_sso(sso)
        token = secrets.token_hex(32)
        os.makedirs(SSO_DIR, mode=0o750, exist_ok=True)
        path = os.path.join(SSO_DIR, token + ".json")
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w") as fh:
            json.dump({"user": user, "password": pw, "database": name, "domain": domain, "lang": lang,
                       "expires": now + SSO_TOKEN_TTL}, fh)
        os.chown(path, uid, gid)
    return {"token": token, "database": name, "expires": now + SSO_TOKEN_TTL, "path": "/phpmyadmin/whp-sso.php"}


@action("db.sso_cleanup")
def db_sso_cleanup(params):
    with _lock:
        return _cleanup_sso()


# ---------------------------------------------------------------- limits

def _limits_file():
    return _path("database-limits.json")


def _limits():
    return _read("database-limits.json")


@action("db.limits")
def db_limits(params):
    """Cap the simultaneous MariaDB connections of every database user of a site
    (package limit), so one site cannot take all connections of the server.
    Databases and users created later for the site get the same cap."""
    domain = v.domain(params, "domain")
    limit = v.integer(params, "max_connections", required=False, minimum=1, maximum=10_000)
    with _lock:
        limits = _limits()
        if limit:
            limits[domain] = limit
        else:
            limits.pop(domain, None)
        _write("database-limits.json", limits)
        users = {e.get("user", n) for n, e in _load().items() if e.get("domain") == domain}
        users |= {u for u, e in _load_users().items() if e.get("domain") == domain}
        users = sorted(u for u in users if EXISTING_USER.match(u or ""))
        if users:
            sql("".join(_connections_sql(u, limit) for u in users))
    return {"domain": domain, "max_connections": limit, "users": users}
