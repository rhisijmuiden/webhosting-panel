#!/usr/bin/env bash
# First-time setup of the panel. Called by the installer after the clone, as root.
# Expects: user whp, /etc/webhosting-panel/panel.env, database whp_panel.
set -euo pipefail

ROOT="${WHP_ROOT:-/opt/webhosting-panel}"
PANEL="$ROOT/panel"
CONFIG="${WHP_CONFIG_DIR:-/etc/webhosting-panel}"
STATE="${WHP_STATE_DIR:-/var/lib/webhosting-panel}"
PANEL_USER="${WHP_PANEL_USER:-whp}"
export COMPOSER_HOME="$STATE/composer"
as_panel() { runuser -u "$PANEL_USER" -- env COMPOSER_HOME="$COMPOSER_HOME" "$@"; }

[ -f "$CONFIG/panel.env" ] || { echo "Missing $CONFIG/panel.env" >&2; exit 1; }

install -d -o "$PANEL_USER" -g "$PANEL_USER" -m 0750 "$COMPOSER_HOME" /var/log/webhosting-panel
chown root:"$PANEL_USER" "$CONFIG/panel.env"
chmod 0640 "$CONFIG/panel.env"
ln -sf "$CONFIG/panel.env" "$PANEL/.env"
chown -R "$PANEL_USER:$PANEL_USER" "$PANEL/storage" "$PANEL/bootstrap/cache"
chown "$PANEL_USER:$PANEL_USER" "$PANEL"
[ -d "$PANEL/vendor" ] && chown -R "$PANEL_USER:$PANEL_USER" "$PANEL/vendor"

(cd "$PANEL" && as_panel composer install --no-dev --no-interaction --optimize-autoloader)
as_panel php "$PANEL/artisan" migrate --force
as_panel php "$PANEL/artisan" optimize
# Resource jail and PHP settings of every customer (after an update of the limits code).
as_panel php "$PANEL/artisan" whp:limits:apply >/dev/null 2>&1 || echo "whp:limits:apply reported errors: see the activity log" >&2

install -m 0644 "$PANEL/deploy/systemd/whp-queue.service" /etc/systemd/system/
install -m 0644 "$PANEL/deploy/systemd/whp-scheduler.service" /etc/systemd/system/
install -m 0644 "$PANEL/deploy/systemd/whp-scheduler.timer" /etc/systemd/system/
install -m 0644 "$PANEL/deploy/systemd/whp-maintenance-guard.service" /etc/systemd/system/
install -m 0644 "$PANEL/deploy/systemd/whp-maintenance-guard.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now whp-queue.service whp-scheduler.timer whp-maintenance-guard.timer

# Login protection: write the CrowdSec scenarios and profiles (needs the agent).
as_panel php "$PANEL/artisan" whp:security:apply || echo "Login protection not applied yet (is the agent running?)" >&2

if [ -n "${WHP_ADMIN_EMAIL:-}" ]; then
  as_panel php "$PANEL/artisan" whp:admin "$WHP_ADMIN_EMAIL" ${WHP_ADMIN_PASSWORD:+--password="$WHP_ADMIN_PASSWORD"}
fi
echo "Panel installed"
