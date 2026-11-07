# shellcheck shell=bash
# phpMyAdmin for customers and admins, on the panel address (/phpmyadmin/).
# There is no login form: people only get in through single sign-on from the
# panel (whp-agent db.sso writes a one-time token to /run/whp-pma/, the
# whp-sso.php script below trades it for a phpMyAdmin session). It runs in
# its own PHP-FPM pool as user whp-pma, which can read nothing else.
#
# Runs on every install and upgrade: a newer phpMyAdmin release is installed
# when there is one (checked against the sha256 phpmyadmin.net publishes).

PMA_ROOT=/var/www/phpmyadmin
PMA_DATA=/var/lib/phpmyadmin
PMA_TOKENS=/run/whp-pma
PMA_USER=whp-pma
PMA_SOCK=/run/php/whp-pma.sock
# Used when phpmyadmin.net cannot tell us the latest version.
PMA_FALLBACK_VERSION=5.2.3

pma_latest_version() {
  local v
  v=$(curl -fsSL --proto '=https' --max-time 20 https://www.phpmyadmin.net/home_page/version.json 2>/dev/null \
      | jq -r '.version // empty' 2>/dev/null) || v=""
  [[ $v =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || v=$PMA_FALLBACK_VERSION
  printf '%s' "$v"
}

pma_download() {
  local version=$1 tmp url sum
  tmp=$(mktemp -d)
  url="https://files.phpmyadmin.net/phpMyAdmin/$version/phpMyAdmin-$version-all-languages.tar.xz"
  if ! curl -fsSL --proto '=https' --max-time 300 -o "$tmp/pma.tar.xz" "$url" \
      || ! sum=$(curl -fsSL --proto '=https' --max-time 30 "$url.sha256" | awk '{print $1}'); then
    rm -rf "$tmp"; warn "phpMyAdmin $version: download failed ($url)"; return 1
  fi
  if [[ ! $sum =~ ^[0-9a-f]{64}$ ]] || ! echo "$sum  $tmp/pma.tar.xz" | sha256sum -c --quiet - >/dev/null 2>&1; then
    rm -rf "$tmp"; warn "phpMyAdmin $version: checksum mismatch, not installed"; return 1
  fi
  mkdir -p "$tmp/x"
  tar -xJf "$tmp/pma.tar.xz" -C "$tmp/x" --strip-components=1 --no-same-owner
  [[ -f $tmp/x/index.php && -d $tmp/x/libraries ]] || { rm -rf "$tmp"; warn "phpMyAdmin $version: unexpected archive layout"; return 1; }
  # No setup wizard and no test data on a production server.
  rm -rf "$tmp/x/setup" "$tmp/x/examples" "$tmp/x/test" "$tmp/x/config.sample.inc.php"
  printf '%s\n' "$version" >"$tmp/x/.whp-version"
  rm -rf "$PMA_ROOT.new" "$PMA_ROOT.old"
  mv "$tmp/x" "$PMA_ROOT.new"
  [[ -d $PMA_ROOT ]] && mv "$PMA_ROOT" "$PMA_ROOT.old"
  mv "$PMA_ROOT.new" "$PMA_ROOT"
  rm -rf "$PMA_ROOT.old" "$tmp"
}

module_phpmyadmin() {
  apt_install xz-utils jq curl "php$PANEL_PHP-mysql" "php$PANEL_PHP-mbstring" "php$PANEL_PHP-xml" \
    "php$PANEL_PHP-zip" "php$PANEL_PHP-gd" "php$PANEL_PHP-curl" "php$PANEL_PHP-intl"

  id "$PMA_USER" >/dev/null 2>&1 || run useradd --system --home-dir "$PMA_DATA" --shell /usr/sbin/nologin "$PMA_USER"
  install -d -m 0750 -o "$PMA_USER" -g "$PMA_USER" "$PMA_DATA" "$PMA_DATA/tmp" "$PMA_DATA/sessions" /var/log/phpmyadmin
  # One-time sign-on tokens: written by the agent (root), read once by the pool.
  render "$TPL/phpmyadmin/tmpfiles.conf" /etc/tmpfiles.d/whp-phpmyadmin.conf
  run systemd-tmpfiles --create /etc/tmpfiles.d/whp-phpmyadmin.conf

  local want have=""
  want=${WHP_PMA_VERSION:-$(pma_latest_version)}
  [[ -f $PMA_ROOT/.whp-version ]] && have=$(<"$PMA_ROOT/.whp-version")
  if [[ $have != "$want" ]]; then
    info "phpMyAdmin $want"
    pma_download "$want" || [[ -n $have ]] || { warn "phpMyAdmin is not available; database screens work without it"; return 0; }
  fi
  have=$(<"$PMA_ROOT/.whp-version")

  PMA_BLOWFISH=$(secret PMA_BLOWFISH 32)
  render "$TPL/phpmyadmin/config.inc.php" "$PMA_ROOT/config.inc.php" 0640
  render "$TPL/phpmyadmin/whp-sso.php" "$PMA_ROOT/whp-sso.php" 0644
  chown -R root:root "$PMA_ROOT"
  chmod -R u=rwX,go=rX "$PMA_ROOT"
  chgrp "$PMA_USER" "$PMA_ROOT/config.inc.php"; chmod 0640 "$PMA_ROOT/config.inc.php"

  render "$TPL/phpmyadmin/pool.conf" "/etc/php/$PANEL_PHP/fpm/pool.d/whp-pma.conf"
  run "php-fpm$PANEL_PHP" -t
  svc_restart "php$PANEL_PHP-fpm"
  render "$TPL/phpmyadmin/nginx.conf" /etc/nginx/whp/panel.d/phpmyadmin.conf
  run nginx -t
  svc_reload nginx
  ok "phpMyAdmin $have: https://$WHP_HOSTNAME:8443/phpmyadmin/ (sign-on from the panel only)"
}
