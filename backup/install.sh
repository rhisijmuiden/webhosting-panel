#!/usr/bin/env bash
# Install whp-backup on Debian 13. Run as root from a checkout of the repository.
# Called by the main installer; safe to run again (keeps existing config).
set -Eeuo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
[[ $EUID -eq 0 ]] || { echo "run as root" >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive
apt-get install -y --no-install-recommends restic rclone jq rsync openssh-client util-linux mariadb-client >/dev/null

install -d -m 700 /etc/webhosting-panel/backup /etc/webhosting-panel/backup/targets
install -d -m 700 /var/lib/webhosting-panel/backup
install -d -m 750 /var/log/webhosting-panel
[[ -f /etc/webhosting-panel/backup/backup.conf ]] || install -m 600 "$HERE/etc/backup.conf" /etc/webhosting-panel/backup/backup.conf
[[ -f /etc/webhosting-panel/backup/excludes.txt ]] || install -m 600 "$HERE/etc/excludes.txt" /etc/webhosting-panel/backup/excludes.txt

install -m 755 "$HERE/bin/whp-backup" /usr/local/sbin/whp-backup
install -m 755 "$HERE/bin/whp-backup-check-all" /usr/local/sbin/whp-backup-check-all
install -m 644 "$HERE"/systemd/*.service "$HERE"/systemd/*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now whp-backup-incremental.timer whp-backup-full.timer whp-backup-check.timer >/dev/null

cat >/etc/logrotate.d/whp-backup <<'ROT'
/var/log/webhosting-panel/backup.log {
	weekly
	rotate 12
	compress
	missingok
	notifempty
}
ROT
echo "whp-backup $(/usr/local/sbin/whp-backup version) installed"
