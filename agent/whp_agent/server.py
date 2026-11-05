"""Unix socket server: one JSON request per line, one JSON response per line."""

import grp
import json
import logging
import os
import pwd
import socket
import socketserver
import struct
import sys

from . import paths
from .actions import load_all
from .registry import ActionError, dispatch

log = logging.getLogger("whp-agent")

MAX_LINE = 1024 * 1024
ALLOWED_USERS = {"root", os.environ.get("WHP_PANEL_USER", "whp")}


def peer_user(sock):
    try:
        creds = sock.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i"))
        _pid, uid, _gid = struct.unpack("3i", creds)
        return pwd.getpwuid(uid).pw_name
    except (OSError, KeyError):
        return None


def handle_line(raw: bytes) -> dict:
    req_id = None
    try:
        try:
            req = json.loads(raw)
        except ValueError:
            raise ActionError("invalid_params", "Request is not valid JSON")
        if not isinstance(req, dict):
            raise ActionError("invalid_params", "Request must be an object")
        req_id = req.get("id")
        name = req.get("action")
        if not isinstance(name, str):
            raise ActionError("invalid_params", "Missing action")
        data = dispatch(name, req.get("params") or {})
        return {"id": req_id, "ok": True, "data": data}
    except ActionError as exc:
        return {"id": req_id, "ok": False, "error": exc.to_dict()}
    except Exception as exc:  # pragma: no cover - defensive
        log.exception("Action failed")
        return {"id": req_id, "ok": False, "error": {"code": "internal", "message": str(exc)}}


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        user = peer_user(self.request)
        if user not in ALLOWED_USERS:
            log.warning("Refused connection from user %s", user)
            return
        while True:
            raw = self.rfile.readline(MAX_LINE)
            if not raw:
                return
            if not raw.strip():
                continue
            response = handle_line(raw)
            if not response["ok"]:
                log.info("user=%s error=%s", user, response["error"]["code"])
            self.wfile.write((json.dumps(response) + "\n").encode())
            self.wfile.flush()


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s", stream=sys.stdout)
    load_all()
    sock_path = paths.SOCKET_PATH
    os.makedirs(os.path.dirname(sock_path), exist_ok=True)
    if os.path.exists(sock_path):
        os.unlink(sock_path)
    server = Server(sock_path, Handler)
    try:
        gid = grp.getgrnam(os.environ.get("WHP_PANEL_GROUP", "whp")).gr_gid
        os.chown(sock_path, 0, gid)
    except (KeyError, PermissionError):
        log.warning("Group whp not found, socket stays owned by the current user")
    os.chmod(sock_path, 0o660)
    log.info("whp-agent listening on %s", sock_path)
    try:
        server.serve_forever()
    finally:
        os.unlink(sock_path)


if __name__ == "__main__":
    main()
