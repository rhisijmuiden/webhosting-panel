# shellcheck shell=bash
# WordPress database cleanup (whp-agent wp.dbclean_*). Sourced by tests/smoke.sh,
# which provides check and $fail. Runs on wp.test, made by the WordPress toolkit test.
echo "== wordpress database cleanup"
if [[ ! -f /var/www/vhosts/wp.test/public/wp-includes/version.php ]]; then
  echo "SKIP wordpress database cleanup (no WordPress on wp.test)"
else
  check "preview, snapshot, clean up revisions/spam/trash/transients/orphans, optimize" \
    python3 "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/wp_dbclean_smoke.py" wp.test
fi
