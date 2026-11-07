# shellcheck shell=bash disable=SC2016
# WordPress security hardening (whp-agent wphard.*). Sourced by tests/smoke.sh,
# which provides check/pass/bad and $fail. A minimal WordPress file tree is
# enough: the agent edits files and Nginx rules, it runs no WordPress PHP.
echo "== wordpress hardening"
if [[ ! -S /run/whp-agent/agent.sock ]]; then
  echo "SKIP wordpress hardening (agent not running)"
else
  WH_AC="python3 $(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/agent_call.py"
  export WH_AC
  wh_get() { curl -s -o /dev/null -w '%{http_code}' -H 'Host: harden.test' "http://127.0.0.1$1"; }
  export -f wh_get
  check "add site harden.test" whp-site add harden.test --no-ssl --json
  WH_PUB=/var/www/vhosts/harden.test/public
  install -d "$WH_PUB/wp-includes" "$WH_PUB/wp-content/uploads" "$WH_PUB/blog/wp-includes" "$WH_PUB/blog/wp-content/uploads"
  for d in "$WH_PUB" "$WH_PUB/blog"; do
    printf "<?php\n\$wp_version = '6.8.3';\n" >"$d/wp-includes/version.php"
    printf '<?php echo "inc-php";\n' >"$d/wp-includes/plain.php"
    printf '<?php echo "upload-php";\n' >"$d/wp-content/uploads/shell.php"
    printf '<?php echo "front";\n' >"$d/index.php"
    printf 'WordPress readme\n' >"$d/readme.html"
    cat >"$d/wp-config.php" <<'PHP'
<?php
define( 'DB_NAME', 'none' );
define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );
define( 'LOGGED_IN_KEY',    'put your unique phrase here' );
define( 'NONCE_KEY',        'put your unique phrase here' );
define( 'AUTH_SALT',        'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT',   'put your unique phrase here' );
define( 'NONCE_SALT',       'put your unique phrase here' );
$table_prefix = 'wp_';

/* That's all, stop editing! Happy publishing. */
require_once ABSPATH . 'wp-settings.php';
PHP
  done
  chown -R "$(stat -c %U /var/www/vhosts/harden.test)": "$WH_PUB"
  chmod 0644 "$WH_PUB/wp-config.php"
  check "status: nothing on" bash -c "$WH_AC wphard.status '{\"domain\":\"harden.test\"}' | jq -e '.on == 0 and .salts.default == true'"
  check "before: wp-includes PHP runs" bash -c '[[ $(wh_get /wp-includes/plain.php) == 200 ]]'
  check "before: PHP in uploads of a folder install runs" bash -c '[[ $(wh_get /blog/wp-content/uploads/shell.php) == 200 ]]'
  check "before: author scan answered" bash -c '[[ $(wh_get "/?author=1") == 200 ]]'
  check "switch everything on" bash -c "$WH_AC wphard.apply '{\"domain\":\"harden.test\",\"measures\":{\"file_edit\":true,\"php_folders\":true,\"wp_config\":true,\"hide_version\":true,\"user_enum\":true}}' | jq -e '.on == 5'"
  check "nginx config valid" nginx -t
  check "DISALLOW_FILE_EDIT in wp-config.php" grep -q "define( 'DISALLOW_FILE_EDIT', true );" "$WH_PUB/wp-config.php"
  check "wp-config.php is 600 and still owned by the site" bash -c "[[ \$(stat -c '%a %U' $WH_PUB/wp-config.php) == \"600 \$(stat -c %U /var/www/vhosts/harden.test)\" ]]"
  check "mu-plugin written by the site user" bash -c "[[ \$(stat -c %U $WH_PUB/wp-content/mu-plugins/whp-hardening.php) == \$(stat -c %U /var/www/vhosts/harden.test) ]] && php -l $WH_PUB/wp-content/mu-plugins/whp-hardening.php"
  check "wp-includes PHP blocked" bash -c '[[ $(wh_get /wp-includes/plain.php) == 404 ]]'
  check "readme.html blocked" bash -c '[[ $(wh_get /readme.html) == 404 ]]'
  check "wp-config.php not served" bash -c '[[ $(wh_get /wp-config.php) == 404 ]]'
  check "author scan refused" bash -c '[[ $(wh_get "/?author=1") == 403 ]]'
  check "wp-admin may still filter by author" bash -c '[[ $(wh_get "/wp-admin/edit.php?author=1") != 403 ]]'
  check "front page still works" bash -c "curl -s -H 'Host: harden.test' http://127.0.0.1/ | grep -q front"
  check "folder install not touched by root install measures" bash -c '[[ $(wh_get /blog/wp-includes/plain.php) == 200 ]]'
  check "folder install: block PHP in its uploads" bash -c "$WH_AC wphard.apply '{\"domain\":\"harden.test\",\"path\":\"blog\",\"measures\":{\"php_folders\":true}}' >/dev/null && [[ \$(wh_get /blog/wp-content/uploads/shell.php) == 404 && \$(wh_get /wp-includes/plain.php) == 404 ]]"
  check "renew salts" bash -c "$WH_AC wphard.salts '{\"domain\":\"harden.test\"}' | jq -e '.salts.default == false and .salts.undo == true' && ! grep -q 'put your unique phrase' $WH_PUB/wp-config.php && php -l $WH_PUB/wp-config.php"
  check "undo salts" bash -c "$WH_AC wphard.salts '{\"domain\":\"harden.test\",\"undo\":true}' | jq -e '.salts.default == true'"
  check "switch everything off again" bash -c "$WH_AC wphard.apply '{\"domain\":\"harden.test\",\"measures\":{\"file_edit\":false,\"php_folders\":false,\"wp_config\":false,\"hide_version\":false,\"user_enum\":false}}' | jq -e '.on == 0'"
  check "off: wp-config.php back to 644 without DISALLOW_FILE_EDIT" bash -c "[[ \$(stat -c %a $WH_PUB/wp-config.php) == 644 ]] && ! grep -q DISALLOW_FILE_EDIT $WH_PUB/wp-config.php"
  check "off: mu-plugin removed, root rules gone" bash -c "[[ ! -e $WH_PUB/wp-content/mu-plugins/whp-hardening.php && \$(wh_get /wp-includes/plain.php) == 200 && \$(wh_get '/?author=1') == 200 ]]"
  check "off: folder install keeps its own rules" bash -c '[[ $(wh_get /blog/wp-content/uploads/shell.php) == 404 ]]'
  check "symlinked wp-config.php refused" bash -c "mv $WH_PUB/wp-config.php $WH_PUB/real-config.php && ln -s /etc/shadow $WH_PUB/wp-config.php && ! $WH_AC wphard.apply '{\"domain\":\"harden.test\",\"measures\":{\"file_edit\":true}}' && [[ \$(stat -c %a /etc/shadow) == 640 ]]"
  check "remove site cleans up rules" bash -c "whp-site remove harden.test --json >/dev/null && [[ ! -e /etc/nginx/whp/sites/harden.test ]] && nginx -t"
fi
