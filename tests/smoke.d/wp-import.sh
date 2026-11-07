# shellcheck shell=bash
# WordPress import wizard (whp-agent wp_import.*). Sourced by tests/smoke.sh,
# which provides check/pass/bad and $fail.
echo "== WordPress import wizard"
WPI_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
check "add source site wpsrc.test and target import.test" bash -c "whp-site add wpsrc.test --profile wordpress --no-ssl --json >/dev/null && whp-site add import.test --no-ssl --json >/dev/null"
mariadb -e "CREATE DATABASE IF NOT EXISTS wpsrc; CREATE USER IF NOT EXISTS 'wpsrc'@'localhost' IDENTIFIED BY 'Src-Db-Pass-2026'; GRANT ALL ON wpsrc.* TO 'wpsrc'@'localhost';"
rm -f /var/www/vhosts/wpsrc.test/public/index.html
check "import a WordPress zip and database export (files, database, wp-config, addresses, paths)" python3 "$WPI_DIR/wp_import_smoke.py" wpsrc.test import.test
check "nginx config valid after the import" nginx -t
