# shellcheck shell=bash
# Per-site web rules (panel: Redirects & access): directories for the HSTS map
# entries and the password files of protected folders, and the GeoIP country
# database for country blocking. The rules themselves are written by whp-agent
# (webrules.apply).

module_web_rules() {
  install -d -m 0755 /etc/nginx/whp/hsts
  install -d -m 0755 /etc/nginx/whp/auth

  mapfile -t geo < <(apt_available libnginx-mod-http-geoip2)
  if ((${#geo[@]})); then
    apt_install "${geo[@]}"
  fi
  install -m 0755 "$INSTALLER_DIR/bin/whp-geoip-update" /usr/local/sbin/whp-geoip-update
  install -m 0644 "$TPL/systemd/whp-geoip-update.service" /etc/systemd/system/whp-geoip-update.service
  install -m 0644 "$TPL/systemd/whp-geoip-update.timer" /etc/systemd/system/whp-geoip-update.timer
  run systemctl daemon-reload
  svc_enable whp-geoip-update.timer

  # A fresh database at install time; on --upgrade only when there is none yet
  # (the timer keeps it current). Never fatal: only country blocking needs it.
  if [[ ! -s $WHP_STATE/geoip/country.mmdb || ! -f /etc/nginx/conf.d/whp-geoip.conf ]]; then
    if run /usr/local/sbin/whp-geoip-update --quiet; then
      ok "$(t geoip_ok)"
    else
      warn "$(t geoip_fail)"
    fi
  else
    ok "$(t geoip_ok)"
  fi
  run nginx -t
}
