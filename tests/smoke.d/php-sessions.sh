# shellcheck shell=bash disable=SC2016
# Expired PHP sessions per website (whp-site-sessionclean, module 51-php-sessions).
# Sourced by tests/smoke.sh, which provides check/pass/bad and $fail.
echo "== php sessions cleanup"
check "add site sess.test" whp-site add sess.test --no-ssl --json
PS_ROOT=/var/www/vhosts/sess.test
PS_USER=$(stat -c %U "$PS_ROOT/sessions")
printf '<?php session_start(); $_SESSION["n"] = ($_SESSION["n"] ?? 0) + 1; echo "n=", $_SESSION["n"];\n' >"$PS_ROOT/public/session.php"
chown "$PS_USER": "$PS_ROOT/public/session.php"
check "PHP writes its session into the website folder" bash -c 'curl -s -H "Host: sess.test" http://127.0.0.1/session.php?nocache=1 | grep -q n=1 && compgen -G "/var/www/vhosts/sess.test/sessions/sess_*" >/dev/null'
# An old session, a fresh one, a non-session file and a link to a root file.
install -m 0600 -o "$PS_USER" -g "$PS_USER" /dev/null "$PS_ROOT/sessions/sess_smokeold"
touch -d '3 hours ago' "$PS_ROOT/sessions/sess_smokeold"
install -m 0600 -o "$PS_USER" -g "$PS_USER" /dev/null "$PS_ROOT/sessions/sess_smokenew"
install -m 0600 -o "$PS_USER" -g "$PS_USER" /dev/null "$PS_ROOT/sessions/keep-me"
touch -d '3 hours ago' "$PS_ROOT/sessions/keep-me"
printf 'root-owned\n' >/root/sess_smoke_target
touch -d '3 hours ago' /root/sess_smoke_target
ln -sfn /root/sess_smoke_target "$PS_ROOT/sessions/sess_smokelink"
touch -h -d '3 hours ago' "$PS_ROOT/sessions/sess_smokelink"
check "timer is active" systemctl is-active --quiet whp-site-sessionclean.timer
check "dry run lists the old session and deletes nothing" bash -c 'whp-site-sessionclean --dry-run | grep -q "^sess.test: 1 expired" && [[ -f /var/www/vhosts/sess.test/sessions/sess_smokeold ]]'
check "cleanup run" whp-site-sessionclean
check "expired session removed" bash -c '[[ ! -e /var/www/vhosts/sess.test/sessions/sess_smokeold ]]'
check "fresh sessions and other files kept" bash -c 'cd /var/www/vhosts/sess.test/sessions && [[ -f sess_smokenew && -f keep-me ]] && compgen -G "sess_*" | grep -vq "^sess_smoke"'
check "a link is not followed or touched" bash -c '[[ -L /var/www/vhosts/sess.test/sessions/sess_smokelink && $(cat /root/sess_smoke_target) == root-owned ]]'
# A query string keeps the page cache out of it (it ignores the session cookie on the first request).
check "the site still continues its session" bash -c 'c=$(mktemp); curl -s -c "$c" -H "Host: sess.test" http://127.0.0.1/session.php?nocache=1 >/dev/null; whp-site-sessionclean >/dev/null; curl -s -b "$c" -H "Host: sess.test" http://127.0.0.1/session.php?nocache=1 | grep -q n=2; r=$?; rm -f "$c"; exit $r'
rm -f /root/sess_smoke_target
check "remove site sess.test" whp-site remove sess.test --json
