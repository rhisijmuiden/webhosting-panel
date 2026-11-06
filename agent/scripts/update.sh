#!/usr/bin/env bash
# Update the panel to the latest commit of a branch (main = production, test = test).
# Run by whp-agent as root: update.sh <branch>
set -euo pipefail
# The agent runs with UMask=0027 (group whp). Code files must be readable for
# Nginx (www-data) too, or changed CSS/JS/images give 403 and the panel loses
# its styling. Write them like the installer does: 0644 files, 0755 folders.
umask 022

BRANCH="${1:?usage: update.sh <branch>}"
case "$BRANCH" in main|test) ;; *) echo "Unknown branch: $BRANCH" >&2; exit 2 ;; esac

ROOT="${WHP_ROOT:-/opt/webhosting-panel}"
CONFIG="${WHP_CONFIG_DIR:-/etc/webhosting-panel}"
PANEL="$ROOT/panel"
PANEL_USER="${WHP_PANEL_USER:-whp}"
GIT=(git -c "safe.directory=$ROOT" -C "$ROOT")
export COMPOSER_HOME="${WHP_STATE_DIR:-/var/lib/webhosting-panel}/composer"
as_panel() { runuser -u "$PANEL_USER" -- env COMPOSER_HOME="$COMPOSER_HOME" "$@"; }

echo "==> Fetching $BRANCH"
"${GIT[@]}" fetch --quiet origin "$BRANCH"
PREVIOUS="$("${GIT[@]}" rev-parse HEAD)"

echo "==> Maintenance mode on"
as_panel php "$PANEL/artisan" down --retry=15 || true

rollback() {
  echo "!! Update failed, going back to $PREVIOUS" >&2
  "${GIT[@]}" checkout --quiet -f "$PREVIOUS" || true
  (cd "$PANEL" && as_panel composer install --no-dev --no-interaction --optimize-autoloader --quiet) || true
  as_panel php "$PANEL/artisan" up || true
}
trap rollback ERR

echo "==> Checking out origin/$BRANCH"
"${GIT[@]}" checkout --quiet -B "$BRANCH" "origin/$BRANCH"
"${GIT[@]}" reset --quiet --hard "origin/$BRANCH"

# Repair files that earlier updates wrote with the agent's umask (0640 root:whp).
"${GIT[@]}" ls-files -z | (cd "$ROOT" && xargs -0r chmod a+r)
find "$ROOT" \( -path "$ROOT/.git" -o -path "$PANEL/storage" -o -path "$PANEL/vendor" -o -path "$PANEL/bootstrap/cache" \) -prune \
  -o -type d -print0 | xargs -0r chmod a+rx

ln -sf "$CONFIG/panel.env" "$PANEL/.env"
chown -R "$PANEL_USER:$PANEL_USER" "$PANEL/storage" "$PANEL/bootstrap/cache"
chown "$PANEL_USER:$PANEL_USER" "$PANEL"
[ -d "$PANEL/vendor" ] && chown -R "$PANEL_USER:$PANEL_USER" "$PANEL/vendor"

# Nginx serves public/ itself: make sure it can read the stylesheet.
if ! runuser -u www-data -- test -r "$PANEL/public/assets/app.css" 2>/dev/null; then
  echo "!! Nginx (www-data) cannot read $PANEL/public/assets/app.css" >&2
  false
fi

echo "==> Installing dependencies"
(cd "$PANEL" && as_panel composer install --no-dev --no-interaction --optimize-autoloader --quiet)

echo "==> Running migrations"
as_panel php "$PANEL/artisan" migrate --force
as_panel php "$PANEL/artisan" optimize:clear >/dev/null
as_panel php "$PANEL/artisan" optimize

trap - ERR

echo "==> Restarting services"
systemctl reload php8.4-fpm || true
systemctl restart whp-queue || true
# Restart the agent last and in the background, so this job can finish writing its result.
systemd-run --on-active=5 --unit "whp-agent-restart-$(date +%s)" systemctl restart whp-agent >/dev/null 2>&1 || true
# Then push the login protection settings again, through the restarted agent.
systemd-run --on-active=20 --unit "whp-security-apply-$(date +%s)" \
  runuser -u "$PANEL_USER" -- php "$PANEL/artisan" whp:security:apply >/dev/null 2>&1 || true

as_panel php "$PANEL/artisan" up
echo "==> Done: $(cat "$ROOT/VERSION") ($("${GIT[@]}" rev-parse --short HEAD))"
