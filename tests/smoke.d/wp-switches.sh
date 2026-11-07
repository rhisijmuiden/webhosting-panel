# shellcheck shell=bash
# WordPress quick switches (whp-agent wp.switches & co). Sourced by tests/smoke.sh,
# which provides check/pass/bad and $fail. Uses the wp.test install of the toolkit check.
echo "== wordpress quick switches"
if [[ ! -f /var/www/vhosts/wp.test/public/wp-includes/version.php ]]; then
  echo "SKIP wordpress quick switches (no WordPress on wp.test)"
else
  check "maintenance, debug + debug.log, indexing, clear cache" \
    python3 "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/wp_switches_smoke.py" wp.test
fi
