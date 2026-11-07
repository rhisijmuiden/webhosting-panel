# shellcheck shell=bash
# WordPress search & replace and change domain (agent wp.sr_*). Sourced by tests/smoke.sh,
# which provides check and $fail. Uses the wp.test install made by tests/wp_smoke.py.
echo "== wordpress search & replace"
if [[ ! -f /var/www/vhosts/wp.test/public/wp-config.php ]]; then
  echo "SKIP wordpress search & replace (no wp.test install)"
else
  check "search & replace and change domain end to end" python3 "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/wp_search_replace_smoke.py" wp.test wpsr.test
fi
