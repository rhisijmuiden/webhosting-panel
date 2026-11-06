#!/usr/bin/env bash
# Install or refresh whp-agent. Called by the installer after the clone.
set -euo pipefail
ROOT="${WHP_ROOT:-/opt/webhosting-panel}"
# 0751 on the state dir: vmail must traverse it to reach the spam Sieve script.
install -d -m 0751 -o root -g whp /var/lib/webhosting-panel
install -d -m 0750 -o root -g whp /var/lib/webhosting-panel/jobs
# Customer backup zips uploaded through the panel wait here for the agent.
install -d -m 0750 -o whp -g whp /var/lib/webhosting-panel/uploads
install -d -m 0700 /var/lib/webhosting-panel/client-backups
install -d -m 0750 -o root -g whp /var/log/webhosting-panel
install -d -m 0750 -o root -g whp /etc/webhosting-panel
install -d -m 0755 /etc/nftables.d
# File manager: spool for file contents (panel <-> agent) and web terminal sockets.
install -d -m 0700 -o whp -g whp /var/lib/webhosting-panel/files-spool
install -m 0644 "$ROOT/agent/systemd/whp-files.tmpfiles" /etc/tmpfiles.d/whp-files.conf
systemd-tmpfiles --create /etc/tmpfiles.d/whp-files.conf || true
install -m 0644 "$ROOT/agent/systemd/whp-agent.service" /etc/systemd/system/whp-agent.service
systemctl daemon-reload
systemctl enable --now whp-agent
systemctl restart whp-agent
echo "whp-agent installed"
