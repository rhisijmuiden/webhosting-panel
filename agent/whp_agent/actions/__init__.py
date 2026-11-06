"""Import every module in this package so its actions register themselves."""

import importlib
import pkgutil


def load_all():
    for module in pkgutil.iter_modules(__path__):
        importlib.import_module(f"{__name__}.{module.name}")
