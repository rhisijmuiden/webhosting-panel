ACTIONS = {}


class ActionError(Exception):
    def __init__(self, code, message=""):
        super().__init__(message)
        self.code = code


def action(name):
    def deco(fn):
        ACTIONS[name] = fn
        return fn
    return deco
