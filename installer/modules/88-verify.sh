# shellcheck shell=bash
# Readiness check: after all steps, confirm that every tool and service the
# panel features rely on is really there, so a server is ready without manual
# work. A missing item is reported (screen, install log, summary) but does not
# stop the run: the modules above already failed hard on what is essential,
# and the next panel update (install.sh --upgrade) installs what is missing.
#
# Add a line here when a new feature needs a new program or service.

VERIFY_PASSED=0 VERIFY_FAILED=()

_verify() {
  local label=$1; shift
  if "$@" >/dev/null 2>&1; then
    VERIFY_PASSED=$((VERIFY_PASSED + 1))
  else
    VERIFY_FAILED+=("$label")
    printf '%s\n' "$(_ind)${C_YELLOW}!${C_RESET} $(t verify_missing "$label")" >&2
    _log_file "CHECK missing: $label"
  fi
  return 0
}
_have()    { command -v "$1" || [[ -x $1 ]]; }
_active()  { systemctl is-active --quiet "$1"; }
_enabled() { systemctl is-enabled --quiet "$1"; }

module_verify() {
  VERIFY_PASSED=0 VERIFY_FAILED=()
  local v

  # Web, PHP and databases
  _verify "Nginx (service)"            _active nginx
  _verify "MariaDB (service)"          _active mariadb
  _verify "Valkey (service)"           _active valkey-server
  _verify "Unbound DNS resolver"       _active unbound
  for v in "${PHP_VERSIONS[@]}"; do
    _verify "PHP $v FPM"               _active "php$v-fpm"
  done
  _verify "Composer"                   _have composer
  _verify "WP-CLI (WordPress toolkit)" _have /usr/local/bin/wp
  _verify "mysqldump/mysql (WP-CLI db)" _have mysqldump
  _verify "phpMyAdmin"                 test -f /var/www/phpmyadmin/index.php

  # Certificates
  _verify "certbot (Let's Encrypt)"    _have certbot
  _verify "certbot Cloudflare plugin (wildcard SSL)" python3 -c 'import certbot_dns_cloudflare'
  _verify "Certificate retry timer"    _enabled whp-cert-retry.timer

  # Apps, deploys, file manager
  _verify "Git (Git deployments)"      _have git
  _verify "ssh-keygen (deploy keys)"   _have ssh-keygen
  _verify "Node.js and npm (build steps)" bash -c 'command -v node && command -v npm'
  _verify "uv (Python apps)"           _have /usr/local/bin/uv
  _verify "Build tools (runtimes, PECL)" _have cc
  _verify "bubblewrap (web terminal sandbox)" _have bwrap
  _verify "ttyd (web terminal)"        bash -c 'command -v ttyd || test -x /usr/local/bin/ttyd'
  _verify "Chromium (smart WordPress updates)" bash -c 'command -v chromium-headless-shell || command -v chromium'
  _verify "Pillow (screenshot compare)" python3 -c 'import PIL'

  # Backups (full, incremental, remote SFTP/SCP/FTP(S)/OneDrive, restore tests)
  _verify "whp-backup"                 _have /usr/local/sbin/whp-backup
  _verify "restic (backup engine)"     _have restic
  _verify "rclone (remote backup locations)" _have rclone
  _verify "jq"                         _have jq
  _verify "Incremental backup timer"   _enabled whp-backup-incremental.timer
  _verify "Full backup timer"          _enabled whp-backup-full.timer

  # Hosting extras
  _verify "ProFTPD (FTP accounts)"     _active proftpd
  _verify "Disk quota tools"           bash -c 'command -v setquota && command -v xfs_quota'
  _verify "Log rotation of the websites" _enabled logrotate.timer
  _verify "PHP session cleanup timer"  _enabled whp-site-sessionclean.timer
  _verify "GeoIP update timer"         _enabled whp-geoip-update.timer
  _verify "Malware scanner (YARA)"     _have yara
  _verify "Malware scan timer"         _enabled whp-malware-scan.timer
  [[ $WHP_CLAMAV == yes ]] && _verify "ClamAV (enabled)" _enabled clamav-daemon
  [[ $WHP_FIREWALL == yes ]] && _verify "nftables firewall" _have nft

  if [[ $WHP_MAIL == yes ]]; then
    _verify "Postfix (service)"        _active postfix
    _verify "Dovecot (service)"        _active dovecot
    _verify "Rspamd (service)"         _active rspamd
    _verify "Sieve compiler"           _have sievec
    _verify "imapsync (mail import)"   _have /usr/local/bin/imapsync
    _verify "SnappyMail webmail"       test -d /var/www/snappymail
  fi
  if [[ $WHP_CROWDSEC == yes ]]; then
    _verify "CrowdSec (service)"       _active crowdsec
    _verify "cscli"                    _have cscli
  fi

  # The panel itself
  _verify "whp-agent (service)"        _active whp-agent
  _verify "Panel queue worker"         _active whp-queue
  _verify "Panel scheduler timer"      _enabled whp-scheduler.timer
  _verify "Maintenance guard timer"    _enabled whp-maintenance-guard.timer
  _verify "Panel login page (https://$WHP_HOSTNAME:8443)" bash -c \
    "[[ \$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 --resolve '$WHP_HOSTNAME:8443:127.0.0.1' 'https://$WHP_HOSTNAME:8443/login') == 200 ]]"

  local total=$((VERIFY_PASSED + ${#VERIFY_FAILED[@]}))
  if ((${#VERIFY_FAILED[@]} == 0)); then
    ok "$(t verify_ok "$VERIFY_PASSED" "$total")"
  else
    printf '%s\n' "$(_ind)${C_YELLOW}!${C_RESET} $(t verify_some "${#VERIFY_FAILED[@]}" "$total")" >&2
    _log_file "CHECK $VERIFY_PASSED/$total passed"
  fi
  return 0
}
