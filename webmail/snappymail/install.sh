#!/usr/bin/env bash
# Install or upgrade SnappyMail webmail with the RHIS theme on Debian 13.
#
# Run as root. Called by installer/ at the end of a fresh install, and by the
# agent on update. Safe to run again: config and user data are kept.
#
# Environment (all optional):
#   WEBMAIL_HOST        default webmail.<hostname -f>
#   PHP_VERSION         default 8.4
#   SNAPPYMAIL_VERSION  default below; set together with SNAPPYMAIL_SHA256 to pin
#   SNAPPYMAIL_SHA256   sha256 of the release tarball; checked when set
#   BRAND               default RHIS
set -euo pipefail

SNAPPYMAIL_VERSION="${SNAPPYMAIL_VERSION:-3.0.0}"
SNAPPYMAIL_SHA256="${SNAPPYMAIL_SHA256:-}"
PHP_VERSION="${PHP_VERSION:-8.4}"
BRAND="${BRAND:-RHIS}"
WEBMAIL_HOST="${WEBMAIL_HOST:-webmail.$(hostname -f)}"

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WEBROOT=/var/www/snappymail
DATA=/var/lib/snappymail
PRIVATE="$DATA/_data_/_default_"
POOL_USER=snappymail
SOCK=/run/php/snappymail.sock
AUTOCONFIG_SOCK=/run/php/whp-autoconfig.sock
FONT_URL="https://github.com/google/fonts/raw/main/ofl/archivo/Archivo%5Bwdth,wght%5D.ttf"

log() { printf '\033[1;36m[webmail]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[webmail]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root."
. /etc/os-release
[[ "${ID:-}" == debian && "${VERSION_ID:-}" == 13 ]] || die "Debian 13 (Trixie) is required."
[[ "$WEBMAIL_HOST" =~ ^[a-z0-9.-]+$ ]] || die "Invalid WEBMAIL_HOST: $WEBMAIL_HOST"
[[ "$SNAPPYMAIL_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Invalid SNAPPYMAIL_VERSION"

log "Packages for PHP $PHP_VERSION"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y --no-install-recommends \
  "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
  "php${PHP_VERSION}-intl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-gd" \
  "php${PHP_VERSION}-apcu" curl ca-certificates >/dev/null

id "$POOL_USER" >/dev/null 2>&1 || useradd --system --home-dir "$DATA" --shell /usr/sbin/nologin "$POOL_USER"

installed=""
[[ -f "$WEBROOT/data/VERSION" ]] && installed="$(cat "$WEBROOT/data/VERSION")"
if [[ "$installed" != "$SNAPPYMAIL_VERSION" ]]; then
  log "Downloading SnappyMail $SNAPPYMAIL_VERSION"
  tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
  url="https://github.com/the-djmaze/snappymail/releases/download/v${SNAPPYMAIL_VERSION}/snappymail-${SNAPPYMAIL_VERSION}.tar.gz"
  curl -fsSL --proto '=https' --tlsv1.2 -o "$tmp/sm.tar.gz" "$url"
  if [[ -n "$SNAPPYMAIL_SHA256" ]]; then
    echo "$SNAPPYMAIL_SHA256  $tmp/sm.tar.gz" | sha256sum -c --quiet - || die "Checksum mismatch for $url"
  else
    log "No SNAPPYMAIL_SHA256 set: trusting the HTTPS download. sha256: $(sha256sum "$tmp/sm.tar.gz" | cut -d' ' -f1)"
  fi
  mkdir -p "$tmp/x" && tar -xzf "$tmp/sm.tar.gz" -C "$tmp/x"
  [[ -f "$tmp/x/index.php" && -d "$tmp/x/snappymail" ]] || die "Unexpected archive layout"
  mkdir -p "$WEBROOT"
  # Replace code, keep our include.php and themes.
  rm -rf "$WEBROOT/snappymail"
  cp -a "$tmp/x/index.php" "$tmp/x/snappymail" "$WEBROOT/"
  mkdir -p "$WEBROOT/data"; cp -a "$tmp/x/data/VERSION" "$WEBROOT/data/VERSION" 2>/dev/null || echo "$SNAPPYMAIL_VERSION" > "$WEBROOT/data/VERSION"
fi

log "Data folder outside the web root"
cat > "$WEBROOT/include.php" <<PHP
<?php
define('APP_DATA_FOLDER_PATH', '$DATA/');
PHP
install -d -m 0750 -o "$POOL_USER" -g "$POOL_USER" "$DATA" "$DATA/_data_" "$PRIVATE" "$PRIVATE/configs" "$PRIVATE/domains"

log "RHIS theme"
rm -rf "$WEBROOT/themes/RHIS"
install -d "$WEBROOT/themes"
cp -a "$REPO_DIR/webmail/snappymail/themes/RHIS" "$WEBROOT/themes/RHIS"
install -d "$WEBROOT/themes/RHIS/fonts"
if [[ ! -s /var/cache/webhosting-panel/Archivo.ttf ]]; then
  install -d /var/cache/webhosting-panel
  curl -fsSL --proto '=https' -o /var/cache/webhosting-panel/Archivo.ttf "$FONT_URL" || log "Could not fetch Archivo; the theme falls back to the system font."
fi
[[ -s /var/cache/webhosting-panel/Archivo.ttf ]] && cp /var/cache/webhosting-panel/Archivo.ttf "$WEBROOT/themes/RHIS/fonts/Archivo.ttf"
cp "$REPO_DIR/webmail/snappymail/themes/RHIS/images/rhis-icon.svg" "$WEBROOT/themes/RHIS/images/favicon.svg" 2>/dev/null || true
chown -R root:root "$WEBROOT"; chmod -R u=rwX,go=rX "$WEBROOT"

if [[ ! -f "$PRIVATE/configs/application.ini" ]]; then
  log "Default configuration"
  sed "s/@BRAND@/${BRAND//\//\\/}/g" "$REPO_DIR/webmail/snappymail/config/application.ini" > "$PRIVATE/configs/application.ini"
  chown "$POOL_USER:$POOL_USER" "$PRIVATE/configs/application.ini"; chmod 0640 "$PRIVATE/configs/application.ini"
fi
# Webmail only logs in to this server; the agent writes one domain file per RHIS mail domain.
rm -f "$PRIVATE/domains/default.json"

log "PHP-FPM pools"
cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/snappymail.conf" <<INI
[snappymail]
user = $POOL_USER
group = $POOL_USER
listen = $SOCK
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 20
pm.process_idle_timeout = 30s
php_admin_value[open_basedir] = $WEBROOT:$DATA:/tmp
php_admin_value[upload_max_filesize] = 25M
php_admin_value[post_max_size] = 30M
php_admin_value[memory_limit] = 256M
php_admin_value[expose_php] = Off
php_admin_flag[display_errors] = Off
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
INI
# Separate tiny pool for autoconfig/autodiscover/mta-sts: read-only, no data access.
cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/whp-autoconfig.conf" <<INI
[whp-autoconfig]
user = nobody
group = nogroup
listen = $AUTOCONFIG_SOCK
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
php_admin_value[open_basedir] = /opt/webhosting-panel/webmail/autoconfig:/opt/webhosting-panel/packages/dns-mail/src:/var/lib/webhosting-panel/mail/domains.json
php_admin_value[memory_limit] = 32M
php_admin_flag[display_errors] = Off
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
INI
install -d -m 0755 /var/lib/webhosting-panel/mail /var/lib/webhosting-panel/acme
[[ -f /var/lib/webhosting-panel/mail/domains.json ]] || echo '{"mail_host":"'"$(hostname -f)"'","brand":"'"$BRAND"'","domains":{}}' > /var/lib/webhosting-panel/mail/domains.json
chmod 0644 /var/lib/webhosting-panel/mail/domains.json

log "Certificate for $WEBMAIL_HOST"
cert="/etc/ssl/certs/ssl-cert-snakeoil.pem"; key="/etc/ssl/private/ssl-cert-snakeoil.key"
if [[ -f "/etc/letsencrypt/live/$WEBMAIL_HOST/fullchain.pem" ]]; then
  cert="/etc/letsencrypt/live/$WEBMAIL_HOST/fullchain.pem"; key="/etc/letsencrypt/live/$WEBMAIL_HOST/privkey.pem"
fi

log "Nginx"
sed -e "s|@WEBMAIL_HOST@|$WEBMAIL_HOST|g" -e "s|@PHP_SOCK@|$SOCK|g" -e "s|@AUTOCONFIG_SOCK@|$AUTOCONFIG_SOCK|g" \
    -e "s|@CERT@|$cert|g" -e "s|@KEY@|$key|g" \
    "$REPO_DIR/webmail/nginx/webmail.conf.tpl" > /etc/nginx/sites-available/whp-webmail.conf
ln -sf /etc/nginx/sites-available/whp-webmail.conf /etc/nginx/sites-enabled/whp-webmail.conf
systemctl reload "php${PHP_VERSION}-fpm"
nginx -t && systemctl reload nginx

if [[ "$cert" == *snakeoil* ]] && command -v certbot >/dev/null; then
  log "Requesting a Let's Encrypt certificate (needs DNS for $WEBMAIL_HOST pointing here)"
  if certbot certonly --webroot -w /var/lib/webhosting-panel/acme -d "$WEBMAIL_HOST" --non-interactive --agree-tos --register-unsafely-without-email --keep-until-expiring >/dev/null 2>&1; then
    sed -i -e "s|$cert|/etc/letsencrypt/live/$WEBMAIL_HOST/fullchain.pem|" -e "s|$key|/etc/letsencrypt/live/$WEBMAIL_HOST/privkey.pem|" /etc/nginx/sites-available/whp-webmail.conf
    nginx -t && systemctl reload nginx
  else
    log "Certificate request failed; webmail runs on the snakeoil certificate for now. Run this script again once DNS is in place."
  fi
fi

log "SnappyMail $SNAPPYMAIL_VERSION ready on https://$WEBMAIL_HOST"
