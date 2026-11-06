"""MariaDB databases that belong to a website.

Every database gets its own user with rights on that database only, from
localhost. The agent talks to MariaDB as root over the unix socket, which
the installer leaves as the only way in for root. SQL goes in on stdin,
never on the command line; names are generated here and checked against a
strict pattern, passwords are generated here and contain only [A-Za-z0-9].

Which database belongs to which site is kept in
/var/lib/webhosting-panel/databases.json, so a site can only ever drop its
own databases (apps.remove, and later the database screens in the panel).
"""

import json
import os
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
DB_NAME = re.compile(r"^[a-z0-9_]{1,64}$")
_lock = threading.Lock()


def _state_file():
    return os.path.join(paths.STATE_DIR, "databases.json")


def _load():
    try:
        with open(_state_file()) as fh:
            data = json.load(fh)
            return data if isinstance(data, dict) else {}
    except FileNotFoundError:
        return {}


def _save(data):
    os.makedirs(paths.STATE_DIR, exist_ok=True)
    tmp = _state_file() + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(data, fh, indent=1, sort_keys=True)
    os.chmod(tmp, 0o600)
    os.replace(tmp, _state_file())


def sql(statements, timeout=60):
    """Run SQL as the MariaDB root user. Returns stdout."""
    return run(MARIADB, input_text=statements, timeout=timeout).stdout


def password(length=32):
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


def name_for(domain, label):
    """A database (and user) name like klant_nl_wp_3f9a: readable and unique."""
    base = re.sub(r"[^a-z0-9]+", "_", domain.lower()).strip("_")[:28]
    label = re.sub(r"[^a-z0-9]+", "", label.lower())[:8] or "db"
    return f"{base}_{label}_{secrets.token_hex(2)}"


def create_for_site(domain, label):
    """Create a database and its user for a site. Returns name, user, password, host."""
    with _lock:
        data = _load()
        for _ in range(20):
            name = name_for(domain, label)
            if name not in data:
                break
        else:  # pragma: no cover - 65536 tries per label
            raise ActionError("conflict", "Could not find a free database name")
        if not DB_NAME.match(name):  # pragma: no cover - name_for only makes valid names
            raise ActionError("internal", "Generated an invalid database name")
        pw = password()
        sql(
            f"CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            f"CREATE USER '{name}'@'localhost' IDENTIFIED BY '{pw}';\n"
            f"GRANT ALL PRIVILEGES ON `{name}`.* TO '{name}'@'localhost';\n"
            "FLUSH PRIVILEGES;\n"
        )
        data[name] = {"domain": domain, "user": name, "label": label, "created": int(time.time())}
        _save(data)
    return {"name": name, "user": name, "password": pw, "host": "localhost"}


def drop(name, domain):
    """Drop a database and its user, only when it belongs to domain."""
    if not DB_NAME.match(name or ""):
        raise ActionError("invalid_params", "Invalid database name")
    with _lock:
        data = _load()
        entry = data.get(name)
        if entry is None or entry.get("domain") != domain:
            raise ActionError("not_found", f"Database {name} does not belong to {domain}")
        sql(
            f"DROP DATABASE IF EXISTS `{name}`;\n"
            f"DROP USER IF EXISTS '{entry.get('user', name)}'@'localhost';\n"
            "FLUSH PRIVILEGES;\n"
        )
        del data[name]
        _save(data)
    return {"name": name, "dropped": True}


def owned_by(domain):
    return sorted(n for n, e in _load().items() if e.get("domain") == domain)


def _sizes():
    out = sql(
        "SELECT table_schema, COALESCE(SUM(data_length + index_length), 0) "
        "FROM information_schema.tables GROUP BY table_schema;\n"
    )
    sizes = {}
    for line in out.splitlines():
        parts = line.split("\t")
        if len(parts) == 2 and parts[1].isdigit():
            sizes[parts[0]] = int(parts[1])
    return sizes


@action("db.list")
def db_list(params):
    domain = v.domain(params, "domain", required=False)
    data = _load()
    try:
        sizes = _sizes()
    except ActionError:
        sizes = {}
    rows = []
    for name, entry in sorted(data.items()):
        if domain and entry.get("domain") != domain:
            continue
        rows.append({"name": name, "user": entry.get("user", name), "domain": entry.get("domain"),
                     "label": entry.get("label"), "created": entry.get("created"),
                     "size_bytes": sizes.get(name, 0)})
    return {"databases": rows}


@action("db.create")
def db_create(params):
    """Create a database for a site. The password is returned once and not stored."""
    domain = v.domain(params, "domain")
    label = v.string(params, "label", required=False, default="db", max_len=8, pattern=r"[a-z0-9]+")
    return create_for_site(domain, label)


@action("db.drop")
def db_drop(params):
    return drop(v.string(params, "name", max_len=64, pattern=DB_NAME.pattern[1:-1]), v.domain(params, "domain"))
