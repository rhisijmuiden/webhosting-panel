import re

from whp_agent.registry import ActionError

_DOMAIN = re.compile(r"^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")


def domain(params, key):
    val = params.get(key)
    if not isinstance(val, str) or not _DOMAIN.match(val.lower()):
        raise ActionError("invalid_params", f"{key} must be a domain")
    return val.lower()


def string(params, key, max_len=255):
    val = params.get(key)
    if not isinstance(val, str) or len(val) > max_len:
        raise ActionError("invalid_params", key)
    return val


def boolean(params, key):
    val = params.get(key)
    if not isinstance(val, bool):
        raise ActionError("invalid_params", key)
    return val


def choice(params, key, options):
    val = params.get(key)
    if val not in options:
        raise ActionError("invalid_params", key)
    return val
