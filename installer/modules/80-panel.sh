# shellcheck shell=bash
# Everything the panel (phase 2) needs from the server: system user, code
# checkout, database, Valkey user, PHP-FPM pool, Nginx vhost on 8443 and
# /etc/webhosting-panel/panel.env. Then the component installers run.
# See docs/architecture/interfaces.md.

panel_checkout() {
  local branch=main
  [[ $WHP_CHANNEL == test ]] && branch='test'
  if [[ $REPO_DIR == "$WHP_CODE_DIR" ]]; then
    return 0
  fi
  if [[ -d $WHP_CODE_DIR/.git ]]; then
    info "$WHP_CODE_DIR already exists, left as is"
    return 0
  fi
  if [[ -d $REPO_DIR/.git ]]; then
    # Installed from a local checkout (development, CI): copy it.
    run git clone -q --no-hardlinks "$REPO_DIR" "$WHP_CODE_DIR"
    run git -C "$WHP_CODE_DIR" remote set-url origin "$WHP_REPO_URL"
  else
    run git clone -q --branch "$branch" "$WHP_REPO_URL" "$WHP_CODE_DIR"
  fi
}

panel_env() {
  local f=$WHP_ETC/panel.env
  PANEL_DB_PASS=$(secret PANEL_DB_PASS 40)
  VALKEY_WHP_PASS=$(secret VALKEY_WHP_PASS 40)
  if [[ ! -f $f ]]; then
    install -m 0640 -o root -g whp /dev/null "$f"
    cat >"$f" <<ENV
APP_NAME="Webhosting Panel"
APP_ENV=production
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_DEBUG=false
APP_URL=https://$WHP_HOSTNAME:8443
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_TIMEZONE=$WHP_TZ
LOG_CHANNEL=daily
LOG_STACK=daily
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=whp_panel
DB_USERNAME=whp_panel
DB_PASSWORD=$PANEL_DB_PASS
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=1
REDIS_CACHE_DB=1
REDIS_USERNAME=whp
REDIS_PASSWORD=$VALKEY_WHP_PASS
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_FROM_ADDRESS=$WHP_EMAIL
WHP_CHANNEL=$WHP_CHANNEL
WHP_HOSTNAME=$WHP_HOSTNAME
WHP_AGENT_SOCKET=/run/whp-agent/agent.sock
ENV
  fi
  env_set "$f" WHP_CHANNEL "$WHP_CHANNEL"
}

module_panel() {
  getent group whp >/dev/null || run groupadd --system whp
  getent passwd whp >/dev/null || run useradd --system --gid whp --home-dir "$WHP_STATE" \
    --shell /usr/sbin/nologin whp
  chgrp whp "$WHP_ETC"
  install -d -m 0750 -o whp -g whp "$WHP_STATE" "$WHP_STATE/panel"
  install -d -m 0770 -o root -g whp "$WHP_LOG_DIR"

  panel_checkout

  # Database
  PANEL_DB_PASS=$(secret PANEL_DB_PASS 40)
  run mysql_root -e "CREATE DATABASE IF NOT EXISTS whp_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'whp_panel'@'localhost' IDENTIFIED BY '$PANEL_DB_PASS';
    CREATE USER IF NOT EXISTS 'whp_panel'@'127.0.0.1' IDENTIFIED BY '$PANEL_DB_PASS';
    ALTER USER 'whp_panel'@'localhost' IDENTIFIED BY '$PANEL_DB_PASS';
    ALTER USER 'whp_panel'@'127.0.0.1' IDENTIFIED BY '$PANEL_DB_PASS';
    GRANT ALL PRIVILEGES ON whp_panel.* TO 'whp_panel'@'localhost';
    GRANT ALL PRIVILEGES ON whp_panel.* TO 'whp_panel'@'127.0.0.1';
    FLUSH PRIVILEGES;"

  panel_env

  # PHP-FPM pool and vhost
  render "$TPL/php/pool-panel.conf" "/etc/php/$PANEL_PHP/fpm/pool.d/whp-panel.conf"
  run "php-fpm$PANEL_PHP" -t
  svc_restart "php$PANEL_PHP-fpm"
  cert_paths "$WHP_HOSTNAME"
  if nginx_has_http3; then PANEL_QUIC="listen 443 quic;
    listen [::]:443 quic;"; else PANEL_QUIC="# HTTP/3 not available"; fi
  render "$TPL/nginx/panel.conf" /etc/nginx/sites-available/05-whp-panel.conf
  ln -sf ../sites-available/05-whp-panel.conf /etc/nginx/sites-enabled/05-whp-panel.conf
  run nginx -t
  svc_reload nginx

  # Helper CLIs
  local b
  chmod 0755 "$WHP_CODE_DIR"/installer/bin/*
  for b in whp-site whp-cert whp-status whp-malware-scan; do
    ln -sf "$WHP_CODE_DIR/installer/bin/$b" "/usr/local/sbin/$b"
  done
  # Per-site PHP-FPM units (created by whp-site) live in this slice.
  render "$TPL/systemd/whp-sites.slice" /etc/systemd/system/whp-sites.slice
  render "$TPL/systemd/whp-sites.target" /etc/systemd/system/whp-sites.target
  install -d -m 0755 "$WHP_ETC/php-fpm"
  install -d -m 0750 "$WHP_LOG_DIR/php-fpm"
  render "$TPL/systemd/whp-cert-retry.service" /etc/systemd/system/whp-cert-retry.service
  render "$TPL/systemd/whp-cert-retry.timer" /etc/systemd/system/whp-cert-retry.timer
  run systemctl daemon-reload
  svc_enable whp-cert-retry.timer whp-sites.target

  # Components from later phases, in this order, when present.
  local c
  for c in agent/install.sh panel/deploy/install.sh webmail/snappymail/install.sh backup/install.sh; do
    if [[ -f $WHP_CODE_DIR/$c ]]; then
      info "running $c"
      run env WHP_HOSTNAME="$WHP_HOSTNAME" WHP_CHANNEL="$WHP_CHANNEL" WHP_ETC="$WHP_ETC" \
        bash "$WHP_CODE_DIR/$c"
    else
      info "$(t component_missing "$c")"
    fi
  done
  ok "panel base: https://$WHP_HOSTNAME:8443"
}
