# shellcheck shell=bash
# PHP sessions of the websites: every website keeps them in <site>/sessions
# (its PHP-FPM pool). Debian turns PHP's own session cleanup off and only
# cleans /var/lib/php/sessions, so whp-site-sessionclean removes the expired
# ones every half hour, per website as that website's own user.
# Called from module_php (50-php.sh).

module_php_sessions() {
  local before
  before=$(files_sum /etc/systemd/system/whp-site-sessionclean.service /etc/systemd/system/whp-site-sessionclean.timer)
  # A copy, not a link: it must not depend on where the code is checked out.
  rm -f /usr/local/sbin/whp-site-sessionclean
  install -m 0755 "$INSTALLER_DIR/bin/whp-site-sessionclean" /usr/local/sbin/whp-site-sessionclean
  install -m 0644 "$TPL/systemd/whp-site-sessionclean.service" /etc/systemd/system/whp-site-sessionclean.service
  install -m 0644 "$TPL/systemd/whp-site-sessionclean.timer" /etc/systemd/system/whp-site-sessionclean.timer
  [[ $(files_sum /etc/systemd/system/whp-site-sessionclean.service /etc/systemd/system/whp-site-sessionclean.timer) == "$before" ]] \
    || run systemctl daemon-reload
  svc_enable whp-site-sessionclean.timer
  # The first cleanup right away: existing servers may have years of sessions.
  run /usr/local/sbin/whp-site-sessionclean
  ok "$(t php_sessions_ok)"
}
