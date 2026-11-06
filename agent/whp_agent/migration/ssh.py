"""SSH to a source server as root with the panel's own migration key.

The key pair and known_hosts live in /etc/webhosting-panel/migration/. The
first connection to a host records its key (accept-new) and every later
connection insists on that key, so a man in the middle after the first test
is refused.
"""

from __future__ import annotations

import ipaddress
import os
import re
import shlex
import subprocess
from pathlib import Path

from .. import paths
from ..registry import ActionError
from ..run import SAFE_ENV, run

KEY_DIR = Path(os.environ.get("WHP_MIGRATION_DIR", os.path.join(paths.CONFIG_DIR, "migration")))
KEY_FILE = KEY_DIR / "id_ed25519"
KNOWN_HOSTS = KEY_DIR / "known_hosts"
CONTROL_DIR = Path(os.environ.get("WHP_MIGRATION_CONTROL_DIR", "/run/whp-agent/ssh"))

_HOST = re.compile(r"^(?=.{1,253}$)(?!-)([A-Za-z0-9-]{1,63}(?<!-)\.)*[A-Za-z0-9-]{1,63}$")
_USER = re.compile(r"^[a-z_][a-z0-9_-]{0,31}$")


def ensure_key() -> dict:
    """Create the key pair once. Returns the public key and its fingerprint."""
    KEY_DIR.mkdir(mode=0o700, parents=True, exist_ok=True)
    if not KEY_FILE.exists():
        run(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", f"webhosting-panel-migration@{os.uname().nodename}",
             "-f", str(KEY_FILE)], timeout=30)
    os.chmod(KEY_FILE, 0o600)
    public = (KEY_DIR / "id_ed25519.pub").read_text(encoding="utf-8").strip()
    fp = run(["ssh-keygen", "-l", "-f", str(KEY_DIR / "id_ed25519.pub")], timeout=10).stdout.split()
    return {"public_key": public, "fingerprint": fp[1] if len(fp) > 1 else ""}


class Connection:
    """One source server. Validates host, port and user once."""

    def __init__(self, params: dict):
        host = params.get("host")
        if not isinstance(host, str):
            raise ActionError("invalid_params", "host is required")
        host = host.strip().lower()
        try:
            host = str(ipaddress.ip_address(host))
        except ValueError:
            if not _HOST.match(host):
                raise ActionError("invalid_params", "host must be a hostname or IP address")
        port = params.get("port", 22)
        if isinstance(port, bool) or not isinstance(port, int) or not 1 <= port <= 65535:
            raise ActionError("invalid_params", "port is out of range")
        user = params.get("user", "root")
        if not isinstance(user, str) or not _USER.match(user):
            raise ActionError("invalid_params", "user must be a Unix user name")
        self.host, self.port, self.user = host, port, user

    @property
    def target(self) -> str:
        host = f"[{self.host}]" if ":" in self.host else self.host
        return f"{self.user}@{host}"

    def ssh_options(self, first_contact: bool = False) -> list[str]:
        CONTROL_DIR.mkdir(mode=0o700, parents=True, exist_ok=True)
        return [
            "-i", str(KEY_FILE),
            "-p", str(self.port),
            "-o", "BatchMode=yes",
            "-o", "IdentitiesOnly=yes",
            "-o", f"UserKnownHostsFile={KNOWN_HOSTS}",
            "-o", "GlobalKnownHostsFile=/dev/null",
            "-o", f"StrictHostKeyChecking={'accept-new' if first_contact else 'yes'}",
            "-o", "ConnectTimeout=15",
            "-o", "ServerAliveInterval=30",
            "-o", "ServerAliveCountMax=6",
            "-o", "ControlMaster=auto",
            "-o", "ControlPersist=120",
            "-o", f"ControlPath={CONTROL_DIR}/%C",
        ]

    def rsync_shell(self) -> str:
        """The -e value for rsync (rsync splits it on spaces; none of our options contain one)."""
        return " ".join(["ssh", *self.ssh_options()])

    def run(self, command: str, timeout: int = 300, check: bool = True, first_contact: bool = False):
        """Run a shell command string on the source. Build it with q()."""
        proc = run(["ssh", *self.ssh_options(first_contact), self.target, "--", command], timeout=timeout, check=False)
        if check and proc.returncode != 0:
            raise ActionError(
                "command_failed",
                _ssh_error(proc.returncode, proc.stderr),
                {"stderr": proc.stderr[-2000:]},
            )
        return proc

    def popen(self, command: str, **kwargs) -> subprocess.Popen:
        """Stream a command's stdout (database dumps)."""
        return subprocess.Popen(
            ["ssh", *self.ssh_options(), "-C", self.target, "--", command],
            env=dict(SAFE_ENV), shell=False, **kwargs,
        )

    def host_key_fingerprint(self) -> str:
        proc = run(["ssh-keygen", "-l", "-F", self._known_name(), "-f", str(KNOWN_HOSTS)], timeout=10, check=False)
        for line in proc.stdout.splitlines():
            parts = line.split()
            if len(parts) >= 3 and not line.startswith("#"):
                return f"{parts[-1].strip('()')} {parts[-2]}"
        return ""

    def forget_host_key(self) -> None:
        if KNOWN_HOSTS.exists():
            run(["ssh-keygen", "-R", self._known_name(), "-f", str(KNOWN_HOSTS)], timeout=10, check=False)

    def _known_name(self) -> str:
        return self.host if self.port == 22 else f"[{self.host}]:{self.port}"


def q(value: str) -> str:
    return shlex.quote(value)


def _ssh_error(code: int, stderr: str) -> str:
    text = stderr or ""
    if "REMOTE HOST IDENTIFICATION HAS CHANGED" in text or "Host key verification failed" in text:
        return "The host key of the source server changed. Check the server and reset its key in the panel if this is expected."
    if "Permission denied" in text:
        return "SSH login refused. Add the panel's public key to /root/.ssh/authorized_keys on the source server."
    if "Connection refused" in text or "timed out" in text or "No route to host" in text or "Could not resolve" in text:
        return "Cannot reach the source server over SSH: " + text.strip().splitlines()[-1][:300]
    lines = [line for line in text.strip().splitlines() if line.strip()]
    return f"Command on the source server failed (exit {code})" + (f": {lines[-1][:300]}" if lines else "")
