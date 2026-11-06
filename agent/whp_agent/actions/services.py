"""Status and restart of the services the panel manages."""

import glob
import os

from .. import validate as v
from ..registry import ActionError, action
from ..run import run

STATIC_SERVICES = {
    "nginx": "Nginx",
    "mariadb": "MariaDB",
    "valkey-server": "Valkey",
    "postfix": "Postfix",
    "dovecot": "Dovecot",
    "rspamd": "Rspamd",
    "unbound": "Unbound",
    "crowdsec": "CrowdSec",
    "crowdsec-firewall-bouncer": "CrowdSec bouncer",
    "nftables": "nftables",
    "whp-queue": "Panel queue worker",
}

RESTARTABLE = set(STATIC_SERVICES) - {"nftables"}


def known_services():
    services = dict(STATIC_SERVICES)
    for path in sorted(glob.glob("/etc/php/*/fpm")):
        ver = os.path.basename(os.path.dirname(path))
        services[f"php{ver}-fpm"] = f"PHP-FPM {ver}"
    return services


def _status(unit):
    proc = run(
        ["systemctl", "show", unit, "--property=ActiveState,SubState,LoadState,ActiveEnterTimestamp"],
        check=False,
        timeout=10,
    )
    props = dict(line.split("=", 1) for line in proc.stdout.splitlines() if "=" in line)
    return {
        "service": unit,
        "installed": props.get("LoadState") == "loaded",
        "active": props.get("ActiveState") == "active",
        "state": props.get("SubState", "unknown"),
        "since": props.get("ActiveEnterTimestamp") or None,
    }


@action("service.list")
def service_list(params):
    out = []
    for unit, label in known_services().items():
        status = _status(unit)
        if status["installed"]:
            status["label"] = label
            out.append(status)
    return {"services": out}


@action("service.status")
def service_status(params):
    unit = v.choice(params, "service", list(known_services()))
    return _status(unit)


@action("service.restart")
def service_restart(params):
    services = known_services()
    unit = v.choice(params, "service", list(services))
    if unit not in RESTARTABLE and not unit.startswith("php"):
        raise ActionError("forbidden", f"{unit} cannot be restarted from the panel")
    verb = "reload" if unit in ("nginx",) or unit.startswith("php") else "restart"
    if unit == "nginx":
        run(["nginx", "-t"], timeout=20)
    run(["systemctl", verb, unit], timeout=60)
    return _status(unit)
