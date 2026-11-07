# shellcheck shell=bash
# Web application firewall: ModSecurity v3 in Nginx with the OWASP Core Rule
# Set (CRS), both from Debian. The rules are loaded once for all websites and
# stay off until the panel switches them on per site, in block or detect-only
# mode (whp-agent waf.apply writes /etc/nginx/whp/sites/<domain>/20-waf.conf
# and /etc/nginx/whp/waf/sites/<domain>.conf). Matches go to a JSON audit log
# that the panel collects (waf.events) for the Security center.

WAF_MODULE_SO=/usr/lib/nginx/modules/ngx_http_modsecurity_module.so

# The folder with the CRS REQUEST-*.conf files of the installed package.
waf_crs_rules_dir() {
  local d
  for d in /usr/share/modsecurity-crs/rules /usr/share/modsecurity-crs /etc/modsecurity/crs/rules; do
    [[ -f $d/REQUEST-901-INITIALIZATION.conf ]] && { printf '%s\n' "$d"; return 0; }
  done
  return 1
}

# Switch every site's firewall include off (nginx must keep working without the module).
waf_disable_sites() {
  rm -f /etc/nginx/conf.d/whp-waf.conf /etc/nginx/whp/sites/*/20-waf.conf /etc/nginx/modules-enabled/50-whp-modsecurity.conf
}

module_waf() {
  local pkgs
  mapfile -t pkgs < <(apt_available libnginx-mod-http-modsecurity modsecurity-crs)
  if ((${#pkgs[@]} < 2)); then
    warn "web application firewall: libnginx-mod-http-modsecurity or modsecurity-crs is not available, skipped"
    waf_disable_sites
    return 0
  fi
  apt_install libnginx-mod-http-modsecurity modsecurity-crs

  # Debian enables the module with a symlink in modules-enabled (grep -R follows it).
  rm -f /etc/nginx/modules-enabled/50-whp-modsecurity.conf
  if ! grep -qRs ngx_http_modsecurity_module /etc/nginx/modules-enabled/; then
    if [[ -f $WAF_MODULE_SO ]]; then
      printf 'load_module %s;\n' "$WAF_MODULE_SO" >/etc/nginx/modules-enabled/50-whp-modsecurity.conf
    else
      warn "web application firewall: the nginx module is missing, skipped"
      waf_disable_sites
      return 0
    fi
  fi

  WAF_CRS_RULES=$(waf_crs_rules_dir) || {
    warn "web application firewall: OWASP CRS rules not found, skipped"
    waf_disable_sites
    return 0
  }

  install -d -m 0755 /etc/nginx/whp/waf /etc/nginx/whp/waf/sites
  install -d -m 0750 -o www-data -g www-data /var/cache/modsecurity
  install -d -m 0750 -o www-data -g adm /var/log/modsecurity
  [[ -f /var/log/modsecurity/audit.log ]] || install -m 0640 -o www-data -g adm /dev/null /var/log/modsecurity/audit.log
  local f
  for f in modsecurity.conf crs-setup.conf before-crs.conf after-crs.conf; do
    render "$TPL/waf/$f" "/etc/nginx/whp/waf/$f"
  done
  render "$TPL/waf/main.conf" /etc/nginx/whp/waf/main.conf
  # Paranoia level, threshold and server-wide exceptions come from the panel.
  [[ -f /etc/nginx/whp/waf/server.conf ]] || render "$TPL/waf/server.conf" /etc/nginx/whp/waf/server.conf
  [[ -f /etc/nginx/whp/waf/server-exceptions.conf ]] || printf "# Exceptions for every website (whp-agent waf.apply).\n" >/etc/nginx/whp/waf/server-exceptions.conf
  render "$TPL/waf/logrotate" /etc/logrotate.d/whp-modsecurity

  render "$TPL/waf/nginx-http.conf" /etc/nginx/conf.d/whp-waf.conf
  local test_out
  if ! test_out=$(nginx -t 2>&1); then
    printf '%s\n' "$test_out" >>"$WHP_INSTALL_LOG"
    warn "web application firewall: nginx does not accept the ModSecurity configuration, switched off: $(grep -m1 emerg <<<"$test_out")"
    waf_disable_sites
    run nginx -t
  fi
  svc_reload nginx
  ok "web application firewall: ModSecurity $(dpkg-query -W -f='${Version}' libmodsecurity3t64 2>/dev/null || dpkg-query -W -f='${Version}' libmodsecurity3 2>/dev/null || echo '?'), OWASP CRS $(dpkg-query -W -f='${Version}' modsecurity-crs 2>/dev/null || echo '?')"
}
