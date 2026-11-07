#!/usr/bin/env bash
# Safety net (whp-maintenance-guard.timer): the panel only goes into maintenance
# mode during an update. If it is still down long after that while no update is
# running (an update that was killed halfway), bring it back up, so the panel
# never stays on HTTP 503.
set -uo pipefail
PANEL="${WHP_ROOT:-/opt/webhosting-panel}/panel"
DOWN="$PANEL/storage/framework/down"
MAX_AGE_MIN="${WHP_MAINTENANCE_MAX_MIN:-30}"

[ -e "$DOWN" ] || exit 0
systemctl is-active --quiet whp-panel-update.service && exit 0
for unit in $(systemctl list-units --plain --no-legend --state=active 'whp-provision-*' 2>/dev/null | awk '{print $1}'); do
  [ -n "$unit" ] && exit 0
done
[ -n "$(find "$DOWN" -mmin +"$MAX_AGE_MIN" 2>/dev/null)" ] || exit 0

echo "Panel was left in maintenance mode for more than $MAX_AGE_MIN minutes without an update running: bringing it back up."
php "$PANEL/artisan" up || rm -f "$DOWN"
