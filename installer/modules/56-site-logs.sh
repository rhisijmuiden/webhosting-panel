# shellcheck shell=bash
# Website logs: Nginx and PHP write them into /var/www/vhosts/<domain>/logs/,
# the panel shows them (packages/site-logs, agent sitelogs.py). logrotate
# keeps them small: whp-site-logrotate writes one block per website just
# before every run, and Nginx reopens its files afterwards.

module_site_logs() {
  apt_install logrotate
  local dropin=/etc/systemd/system/logrotate.service.d/whp-sites.conf
  local before
  before=$(files_sum "$dropin")
  install -d -m 0755 /etc/systemd/system/logrotate.service.d
  cat >"$dropin.new" <<UNIT
# Managed by the Webhosting Panel installer (module 56-site-logs).
[Service]
Environment=WHP_SITE_LOG_DAYS=${WHP_SITE_LOG_DAYS:-14}
ExecStartPre=/usr/local/sbin/whp-site-logrotate
ExecStartPost=-/bin/systemctl kill --kill-whom=main --signal=USR1 nginx.service
UNIT
  mv -f "$dropin.new" "$dropin"
  chmod 0644 "$dropin"
  [[ $(files_sum "$dropin") == "$before" ]] || run systemctl daemon-reload
  # A copy, not a link: it must not depend on where the code is checked out.
  rm -f /usr/local/sbin/whp-site-logrotate
  install -m 0755 "$INSTALLER_DIR/bin/whp-site-logrotate" /usr/local/sbin/whp-site-logrotate
  run env WHP_SITE_LOG_DAYS="${WHP_SITE_LOG_DAYS:-14}" /usr/local/sbin/whp-site-logrotate
  run logrotate --debug /etc/logrotate.d/whp-sites
  svc_enable logrotate.timer
  ok "$(t sitelogs_ok "${WHP_SITE_LOG_DAYS:-14}")"
}
