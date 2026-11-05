"""Parameter validation helpers. Every action validates its own params."""

import ipaddress
import re

from .registry import ActionError

_DOMAIN = re.compile(r"^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$")
_NAME = re.compile(r"^[a-z0-9][a-z0-9_.@-]{0,63}$")


def _get(params, key, required=True, default=None):
    if key not in params or params[key] is None:
        if required:
            raise ActionError("invalid_params", f"Missing parameter: {key}")
        return default
    return params[key]


def string(params, key, required=True, default=None, max_len=255, pattern=None):
    value = _get(params, key, required, default)
    if value is None:
        return None
    if not isinstance(value, str) or len(value) > max_len:
        raise ActionError("invalid_params", f"{key} must be a string of at most {max_len} characters")
    if "\x00" in value or "\n" in value:
        raise ActionError("invalid_params", f"{key} contains invalid characters")
    if pattern and not re.fullmatch(pattern, value):
        raise ActionError("invalid_params", f"{key} has an invalid format")
    return value


def name(params, key, required=True, default=None):
    value = string(params, key, required, default, max_len=64)
    if value is not None and not _NAME.match(value):
        raise ActionError("invalid_params", f"{key} must be a simple name")
    return value


def domain(params, key, required=True, default=None):
    value = _get(params, key, required, default)
    if value is None:
        return None
    if not isinstance(value, str):
        raise ActionError("invalid_params", f"{key} must be a domain name")
    value = value.strip().lower().rstrip(".")
    try:
        value = value.encode("idna").decode("ascii")
    except UnicodeError:
        raise ActionError("invalid_params", f"{key} is not a valid domain name")
    if not _DOMAIN.match(value):
        raise ActionError("invalid_params", f"{key} is not a valid domain name")
    return value


def boolean(params, key, required=True, default=None):
    value = _get(params, key, required, default)
    if value is None:
        return None
    if not isinstance(value, bool):
        raise ActionError("invalid_params", f"{key} must be true or false")
    return value


def integer(params, key, required=True, default=None, minimum=None, maximum=None):
    value = _get(params, key, required, default)
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, int):
        raise ActionError("invalid_params", f"{key} must be a whole number")
    if (minimum is not None and value < minimum) or (maximum is not None and value > maximum):
        raise ActionError("invalid_params", f"{key} is out of range")
    return value


def choice(params, key, options, required=True, default=None):
    value = _get(params, key, required, default)
    if value is None:
        return None
    if value not in options:
        raise ActionError("invalid_params", f"{key} must be one of: {', '.join(map(str, options))}")
    return value


def ip_or_network(params, key, required=True, default=None):
    value = string(params, key, required, default, max_len=64)
    if value is None:
        return None
    try:
        return str(ipaddress.ip_network(value, strict=False))
    except ValueError:
        raise ActionError("invalid_params", f"{key} must be an IP address or network")


def port_spec(params, key, required=True, default=None):
    """A single port (443) or a range (8000-8100)."""
    value = _get(params, key, required, default)
    if value is None:
        return None
    text = str(value)
    m = re.fullmatch(r"(\d{1,5})(?:-(\d{1,5}))?", text)
    if not m:
        raise ActionError("invalid_params", f"{key} must be a port or a port range")
    low = int(m.group(1))
    high = int(m.group(2) or low)
    if not (1 <= low <= high <= 65535):
        raise ActionError("invalid_params", f"{key} is out of range")
    return f"{low}-{high}" if high != low else str(low)


def list_of(params, key, item_validator, required=True, max_items=500):
    value = _get(params, key, required, [])
    if not isinstance(value, list) or len(value) > max_items:
        raise ActionError("invalid_params", f"{key} must be a list of at most {max_items} items")
    return [item_validator(item) for item in value]
