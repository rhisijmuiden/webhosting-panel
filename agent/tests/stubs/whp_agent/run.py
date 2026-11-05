import subprocess

CALLS = []


def run(argv, timeout=60, check=True, input_text=None, cwd=None, env=None):
    CALLS.append(list(argv))
    return subprocess.CompletedProcess(argv, 0, "", "")
