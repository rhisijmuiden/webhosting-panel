# shellcheck shell=bash
# Smart WordPress updates (agent wp.smart_*): real Chromium screenshots, keep or
# roll back. Sourced by tests/smoke.sh after the WordPress toolkit made wp.test.
echo "== smart WordPress updates"
check "headless Chromium and Pillow installed" bash -c "command -v chromium-headless-shell || command -v chromium; python3 -c 'import PIL'"
check "whp-shot user has no login" bash -c "[[ \$(getent passwd whp-shot | cut -d: -f7) == /usr/sbin/nologin ]]"
if [[ -f /var/lib/webhosting-panel/sites/wp.test.env ]]; then
  check "screenshot check keeps a good update and rolls back a visual change" \
    python3 "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/wp_smart_smoke.py" wp.test
else
  echo "SKIP smart WordPress updates (wp.test is not there)"
fi
