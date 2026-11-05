"""Action registry. Every callable action is registered here by name."""

from typing import Callable, Dict

ERROR_CODES = {
    "invalid_params",
    "not_found",
    "unknown_action",
    "forbidden",
    "conflict",
    "command_failed",
    "timeout",
    "internal",
}

ACTIONS: Dict[str, Callable[[dict], dict]] = {}


class ActionError(Exception):
    def __init__(self, code: str, message: str, details: dict | None = None):
        if code not in ERROR_CODES:
            code = "internal"
        super().__init__(message)
        self.code = code
        self.message = message
        self.details = details or {}

    def to_dict(self) -> dict:
        data = {"code": self.code, "message": self.message}
        if self.details:
            data["details"] = self.details
        return data


def action(name: str):
    """Register a function as an agent action: @action("service.status")."""

    def decorator(fn: Callable[[dict], dict]):
        if name in ACTIONS:
            raise RuntimeError(f"Action {name} registered twice")
        ACTIONS[name] = fn
        return fn

    return decorator


def dispatch(name: str, params: dict) -> dict:
    fn = ACTIONS.get(name)
    if fn is None:
        raise ActionError("unknown_action", f"Unknown action: {name}")
    if not isinstance(params, dict):
        raise ActionError("invalid_params", "params must be an object")
    result = fn(params)
    return result if result is not None else {}
