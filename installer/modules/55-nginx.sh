# shellcheck shell=bash
# Nginx with FastCGI page cache, HTTP/2, HTTP/3 (when the build supports it),
# modern TLS and a default server that rejects unknown hosts.

module_nginx() {
  apt_install nginx
  mapfile -t extra < <(apt_available libnginx-mod-http-brotli-filter libnginx-mod-http-brotli-static libnginx-mod-http-headers-more-filter)
  ((${#extra[@]})) && apt_install "${extra[@]}"

  backup_once /etc/nginx/nginx.conf
  NGINX_WORKER_CONNECTIONS=4096
  # Page cache: 5% of free disk, between 1 and 20 GB.
  local free_gb
  free_gb=$(df -BG --output=avail /var/cache | tail -n1 | tr -dc 0-9)
  NGINX_CACHE_MAX=$(( free_gb / 20 ))
  (( NGINX_CACHE_MAX < 1 )) && NGINX_CACHE_MAX=1
  (( NGINX_CACHE_MAX > 20 )) && NGINX_CACHE_MAX=20
  render "$TPL/nginx/nginx.conf" /etc/nginx/nginx.conf
  install -d -m 0755 /etc/nginx/whp /etc/nginx/whp/sites
  install -d -m 0700 -o www-data -g www-data /var/cache/nginx/whp

  if nginx_has_http3; then
    NGINX_QUIC_DEFAULT="listen 443 quic reuseport default_server;
    listen [::]:443 quic reuseport default_server;"
    # shellcheck disable=SC2016 # nginx variable
    NGINX_ALT_SVC='add_header Alt-Svc $whp_alt_svc always;'
  else
    NGINX_QUIC_DEFAULT="# HTTP/3 not available in this nginx build"
    NGINX_ALT_SVC="# HTTP/3 not available in this nginx build"
  fi
  printf '%s\n' "$NGINX_ALT_SVC" >/etc/nginx/whp/alt-svc.conf

  if pkg_installed libnginx-mod-http-brotli-filter; then
    render "$TPL/nginx/brotli.conf" /etc/nginx/conf.d/whp-brotli.conf
  else
    rm -f /etc/nginx/conf.d/whp-brotli.conf
  fi
  local f
  render "$TPL/nginx/whp-http.conf" /etc/nginx/conf.d/whp-http.conf
  for f in tls.conf headers.conf php-cache.conf php-nocache.conf php-common.conf acme.conf static.conf deny.conf; do
    render "$TPL/nginx/snippets/$f" "/etc/nginx/whp/$f"
  done

  install -d -m 0755 /etc/nginx/whp/pages
  install -m 0644 "$TPL/nginx/pages/suspended.html" /etc/nginx/whp/pages/suspended.html

  rm -f /etc/nginx/sites-enabled/default
  render "$TPL/nginx/default-server.conf" /etc/nginx/sites-available/00-whp-default.conf
  ln -sf ../sites-available/00-whp-default.conf /etc/nginx/sites-enabled/00-whp-default.conf

  # Let's Encrypt: reload services after every renewal.
  install -d /etc/letsencrypt/renewal-hooks/deploy
  install -m 0755 "$TPL/nginx/certbot-deploy-hook.sh" /etc/letsencrypt/renewal-hooks/deploy/whp-reload.sh
  systemctl enable --now certbot.timer >/dev/null 2>&1 || true

  run nginx -t
  svc_enable nginx
  svc_reload nginx
  if [[ $WHP_LETSENCRYPT == yes ]]; then
    if "$INSTALLER_DIR/bin/whp-cert" "$WHP_HOSTNAME"; then
      ok "$(t cert_ok "$WHP_HOSTNAME")"
    else
      warn "$(t cert_fail "$WHP_HOSTNAME" "$WHP_HOSTNAME")"
    fi
  fi
  ok "nginx $(nginx -v 2>&1 | grep -oE '[0-9.]+$'), http3: $(nginx_has_http3 && echo yes || echo no)"
}
