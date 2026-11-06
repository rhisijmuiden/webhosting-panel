"""Run external commands without a shell."""

import subprocess

from .registry import ActionError

SAFE_ENV = {"PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"}


def run(argv, timeout=60, check=True, input_text=None, cwd=None, env=None, umask=-1):
    if not isinstance(argv, (list, tuple)) or not argv or not all(isinstance(a, str) for a in argv):
        raise ActionError("internal", "run() needs a list of strings")
    full_env = dict(SAFE_ENV)
    if env:
        full_env.update(env)
    try:
        proc = subprocess.run(
            list(argv),
            input=input_text,
            capture_output=True,
            text=True,
            timeout=timeout,
            cwd=cwd,
            env=full_env,
            shell=False,
            umask=umask,
        )
    except FileNotFoundError:
        raise ActionError("command_failed", f"Command not found: {argv[0]}")
    except subprocess.TimeoutExpired:
        raise ActionError("timeout", f"{argv[0]} did not finish within {timeout} seconds")
    if check and proc.returncode != 0:
        raise ActionError(
            "command_failed",
            f"{argv[0]} exited with code {proc.returncode}",
            {"stderr": proc.stderr[-2000:], "stdout": proc.stdout[-2000:]},
        )
    return proc
