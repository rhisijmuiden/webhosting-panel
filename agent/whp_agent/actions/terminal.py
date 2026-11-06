"""Web terminal for customers whose hosting package allows SSH.

The panel never gets a real shell on the server. terminal.start runs ttyd as
the site user in a transient systemd unit (sites slice, memory and task
limits, one client, maximum lifetime) and ttyd starts bash inside a
bubblewrap sandbox: the system (/usr, a few files from /etc) is read-only,
only the site root is writable, and the customer sees no other websites,
no /home, no /root and no panel configuration. ttyd listens on a Unix socket
in /run/whp-terminal/<token>/; Nginx on :8443 proxies /terminal/<token>/ to it
after the panel confirmed (auth_request) that the logged-in user owns the token.
"""

import os
import re
import secrets
import shutil
import time

from .. import validate as v
from ..registry import ActionError, action
from ..run import run
from .files import _site

TTYD = os.environ.get("WHP_TTYD") or next(
    (p for p in ("/usr/bin/ttyd", "/usr/local/bin/ttyd") if os.path.exists(p)), "/usr/bin/ttyd")
BWRAP = os.environ.get("WHP_BWRAP", "/usr/bin/bwrap")
RUN_DIR = os.environ.get("WHP_TERMINAL_DIR", "/run/whp-terminal")
SYSTEMD_RUN = "/usr/bin/systemd-run"
SYSTEMCTL = "/usr/bin/systemctl"
WEB_GROUP = "www-data"
_TOKEN = re.compile(r"^[a-f0-9]{32}$")
# Read-only bits of /etc the tools in the sandbox need (DNS, TLS, PHP, git, locale).
ETC_FILES = ["passwd", "group", "nsswitch.conf", "hosts", "resolv.conf", "localtime", "timezone",
             "bash.bashrc", "profile", "inputrc", "ssl", "ca-certificates", "alternatives", "php",
             "gitconfig", "mime.types", "default/locale", "locale.alias", "manpath.config", "vim", "nanorc",
             "terminfo", "environment"]
# Huisstijl colours for xterm.js (nacht background, koraal cursor).
XTERM_THEME = ('{"background":"#14213D","foreground":"#EEF1F7","cursor":"#FF6B57",'
               '"selectionBackground":"#9CC3FF55","black":"#14213D","blue":"#9CC3FF","red":"#FF7A7A",'
               '"green":"#4ACB8C","yellow":"#F2B640","magenta":"#FF8A78","cyan":"#9CC3FF","white":"#EEF1F7"}')


def available():
    return os.access(TTYD, os.X_OK) and os.access(BWRAP, os.X_OK)


def sandbox_argv(site):
    """bwrap command line: only the site root is writable, the rest of the system read-only."""
    root = site["root"]
    argv = [
        BWRAP, "--die-with-parent", "--unshare-all", "--share-net", "--new-session",
        "--hostname", site["domain"][:63],
        "--ro-bind", "/usr", "/usr",
        "--symlink", "usr/bin", "/bin", "--symlink", "usr/sbin", "/sbin",
        "--symlink", "usr/lib", "/lib", "--symlink", "usr/lib64", "/lib64",
        "--proc", "/proc", "--dev", "/dev", "--tmpfs", "/tmp", "--tmpfs", "/run",
        "--dir", "/var", "--dir", "/var/www", "--dir", "/var/www/vhosts",
    ]
    for name in ETC_FILES:
        argv += ["--ro-bind-try", f"/etc/{name}", f"/etc/{name}"]
    argv += [
        # MariaDB over the socket, so mysql and wp-cli work from the shell.
        "--ro-bind-try", "/run/mysqld", "/run/mysqld",
        "--bind", root, root,
        "--chdir", root,
        "--clearenv",
        "--setenv", "HOME", root, "--setenv", "USER", site["user"], "--setenv", "LOGNAME", site["user"],
        "--setenv", "PATH", "/usr/local/bin:/usr/bin:/bin", "--setenv", "TERM", "xterm-256color",
        "--setenv", "LANG", "C.UTF-8", "--setenv", "TMPDIR", "/tmp",
        "--setenv", "PS1", f"\\[\\e[38;5;209m\\]{site['user']}@{site['domain']}\\[\\e[0m\\]:\\w\\$ ",
        "/bin/bash", "--noprofile", "--norc", "-i",
    ]
    return argv


def unit_argv(site, token, minutes):
    sock = os.path.join(RUN_DIR, token, "ttyd.sock")
    return [
        SYSTEMD_RUN, "--quiet", "--collect", f"--unit=whp-term-{token}",
        f"--uid={site['user']}", f"--gid={site['user']}", "--slice=whp-sites.slice",
        "-p", f"RuntimeMaxSec={minutes * 60}",
        "-p", "UMask=0007",  # socket 0770, group www-data (setgid folder) so Nginx can connect
        "-p", "NoNewPrivileges=yes",
        "-p", "MemoryMax=1G", "-p", "TasksMax=256", "-p", "Nice=5",
        "-p", f"ExecStopPost=+/usr/bin/rm -rf {os.path.join(RUN_DIR, token)}",
        TTYD, "--interface", sock, "--once", "--max-clients", "1", "--writable", "--check-origin",
        "--base-path", f"/terminal/{token}", "--ping-interval", "30",
        "-t", f"titleFixed={site['domain']}", "-t", "fontSize=14", "-t", "disableLeaveAlert=true",
        "-t", "fontFamily=ui-monospace,JetBrains Mono,Menlo,Consolas,monospace",
        "-t", f"theme={XTERM_THEME}",
        *sandbox_argv(site),
    ]


def _cleanup_stale():
    """Remove socket folders whose unit is gone (after a reboot or crash)."""
    try:
        names = os.listdir(RUN_DIR)
    except FileNotFoundError:
        return
    for name in names:
        if not _TOKEN.fullmatch(name):
            continue
        proc = run([SYSTEMCTL, "is-active", "--quiet", f"whp-term-{name}.service"], check=False)
        if proc.returncode != 0:
            shutil.rmtree(os.path.join(RUN_DIR, name), ignore_errors=True)


@action("terminal.status")
def terminal_status(params):
    return {"available": available()}


@action("terminal.start")
def terminal_start(params):
    site = _site(params)
    minutes = v.integer(params, "minutes", required=False, default=60, minimum=5, maximum=480)
    if not available():
        raise ActionError("not_found", "The web terminal is not installed (packages ttyd and bubblewrap)")
    if site["suspended"]:
        raise ActionError("forbidden", "This website is suspended")
    import grp

    os.makedirs(RUN_DIR, mode=0o711, exist_ok=True)
    os.chmod(RUN_DIR, 0o711)
    _cleanup_stale()
    token = secrets.token_hex(16)
    d = os.path.join(RUN_DIR, token)
    os.mkdir(d, 0o750)
    os.chown(d, site["uid"], grp.getgrnam(WEB_GROUP).gr_gid)
    os.chmod(d, 0o2750)
    try:
        run(unit_argv(site, token, minutes), timeout=30)
    except ActionError:
        shutil.rmtree(d, ignore_errors=True)
        raise
    # Wait until ttyd listens, so the browser does not get a 502 first.
    sock = os.path.join(d, "ttyd.sock")
    for _ in range(50):
        if os.path.exists(sock):
            break
        time.sleep(0.1)
    return {"token": token, "path": f"/terminal/{token}/", "expires_in": minutes * 60}


@action("terminal.stop")
def terminal_stop(params):
    token = v.string(params, "token", pattern=_TOKEN.pattern)
    run([SYSTEMCTL, "stop", f"whp-term-{token}.service"], check=False, timeout=30)
    shutil.rmtree(os.path.join(RUN_DIR, token), ignore_errors=True)
    return {"stopped": token}
