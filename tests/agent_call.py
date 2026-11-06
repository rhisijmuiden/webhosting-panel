#!/usr/bin/env python3
"""Call one agent action over its socket: agent_call.py <action> [json params]. Prints the data."""
import json
import socket
import sys

s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
s.connect("/run/whp-agent/agent.sock")
s.sendall((json.dumps({"id": "smoke", "action": sys.argv[1], "params": json.loads(sys.argv[2] if len(sys.argv) > 2 else "{}")}) + "\n").encode())
buf = b""
while not buf.endswith(b"\n"):
    chunk = s.recv(65536)
    if not chunk:
        break
    buf += chunk
r = json.loads(buf)
print(json.dumps(r.get("data") if r.get("ok") else r.get("error")))
sys.exit(0 if r.get("ok") else 1)
