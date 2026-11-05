# shellcheck shell=bash
# PHP-FPM, every supported version side by side. Sites pick a version;
# each site gets its own pool, user and socket (see installer/bin/whp-site).

PHP_EXTENSIONS=(fpm cli common opcache mysql curl gd intl mbstring xml zip bcmath
  soap gmp imagick redis igbinary apcu readline sqlite3 exif)

module_php() {
  local v pkgs ext
  for v in "${PHP_VERSIONS[@]}"; do
    pkgs=()
    for ext in "${PHP_EXTENSIONS[@]}"; do pkgs+=("php$v-$ext"); done
    mapfile -t pkgs < <(apt_available "${pkgs[@]}")
    apt_install "${pkgs[@]}"

    PHP_VERSION=$v
    render "$TPL/php/90-whp.ini" "/etc/php/$v/fpm/conf.d/90-whp.ini"
    render "$TPL/php/90-whp-cli.ini" "/etc/php/$v/cli/conf.d/90-whp.ini"
    # The stock www pool is replaced by a tiny idle pool (FPM needs at least one).
    [[ -f /etc/php/$v/fpm/pool.d/www.conf ]] && mv -f "/etc/php/$v/fpm/pool.d/www.conf" "/etc/php/$v/fpm/pool.d/www.conf.whp-disabled"
    render "$TPL/php/pool-idle.conf" "/etc/php/$v/fpm/pool.d/00-idle.conf"
    run "php-fpm$v" -t
    svc_enable "php$v-fpm"
    svc_restart "php$v-fpm"
    ok "php $v ($(php"$v" -r 'echo PHP_VERSION;'))"
  done
  update-alternatives --set php "/usr/bin/php$PANEL_PHP" >/dev/null 2>&1 || true

  # Composer for Laravel and other modern PHP apps.
  apt_install composer
  printf '%s\n' "${PHP_VERSIONS[@]}" >"$WHP_STATE/php-versions"
}
