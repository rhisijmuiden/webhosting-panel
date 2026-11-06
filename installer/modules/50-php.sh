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

  # Git deployments: ssh-keygen for deploy keys, Node.js and npm for build steps (npm run build).
  apt_install openssh-client nodejs npm

  # WP-CLI for the WordPress toolkit and the app installer (pinned release, checksum checked).
  install_wp_cli
  printf '%s\n' "${PHP_VERSIONS[@]}" >"$WHP_STATE/php-versions"
}

WP_CLI_VERSION=2.12.0

install_wp_cli() {
  local url=https://github.com/wp-cli/wp-cli/releases/download/v$WP_CLI_VERSION/wp-cli-$WP_CLI_VERSION.phar
  local tmp have compat=()
  have=$(php /usr/local/bin/wp --allow-root --version 2>/dev/null | awk '{print $2}' || true)
  if [[ $have == "$WP_CLI_VERSION" ]]; then ok "wp-cli $have"; return 0; fi
  tmp=$(mktemp -d)
  if curl -fsSL --retry 3 -o "$tmp/wp.phar" "$url" && curl -fsSL --retry 3 -o "$tmp/wp.phar.sha512" "$url.sha512" \
     && [[ $(sha512sum "$tmp/wp.phar" | cut -d' ' -f1) == "$(tr -d ' \n' <"$tmp/wp.phar.sha512")" ]]; then
    install -m 0755 "$tmp/wp.phar" /usr/local/bin/wp
    ok "wp-cli $WP_CLI_VERSION"
  else
    warn "wp-cli $WP_CLI_VERSION could not be downloaded or verified; the WordPress toolkit needs /usr/local/bin/wp"
  fi
  rm -rf "$tmp"
  # wp db export/import call mysqldump/mysql; MariaDB 11 ships those names in the compat package.
  mapfile -t compat < <(apt_available mariadb-client-compat)
  ((${#compat[@]})) && apt_install "${compat[@]}"
  return 0
}
