"""CrowdSec decisions (bans) through cscli."""

import json

from .. import validate as v
from ..registry import action
from ..run import run

CSCLI = "/usr/bin/cscli"


def _json(argv):
    out = run([CSCLI, *argv, "-o", "json"], timeout=30).stdout.strip()
    return json.loads(out) if out and out != "null" else []


@action("crowdsec.decisions")
def decisions(params):
    alerts = _json(["decisions", "list", "--limit", "500"])
    rows = []
    for alert in alerts or []:
        for d in alert.get("decisions") or []:
            rows.append({
                "id": d.get("id"),
                "value": d.get("value"),
                "scope": d.get("scope"),
                "type": d.get("type"),
                "origin": d.get("origin"),
                "scenario": d.get("scenario") or alert.get("scenario"),
                "duration": d.get("duration"),
                "country": (alert.get("source") or {}).get("cn"),
            })
    return {"decisions": rows}


@action("crowdsec.metrics")
def metrics(params):
    alerts = _json(["alerts", "list", "--since", "24h", "--limit", "0"])
    return {"alerts_24h": len(alerts or [])}


@action("crowdsec.ban")
def ban(params):
    address = v.ip_or_network(params, "address")
    duration = v.string(params, "duration", required=False, default="4h", max_len=8, pattern=r"\d{1,4}[mhd]")
    reason = v.string(params, "reason", required=False, default="Blocked from panel", max_len=120)
    scope = "range" if "/" in address and not address.endswith(("/32", "/128")) else "ip"
    value = address.split("/")[0] if scope == "ip" else address
    run([CSCLI, "decisions", "add", f"--{scope}", value, "--duration", duration, "--reason", reason], timeout=30)
    return {"address": value, "duration": duration}


@action("crowdsec.unban")
def unban(params):
    address = v.ip_or_network(params, "address")
    value = address.split("/")[0] if address.endswith(("/32", "/128")) else address
    flag = "--range" if "/" in value else "--ip"
    run([CSCLI, "decisions", "delete", flag, value], timeout=30)
    return {"address": value}
