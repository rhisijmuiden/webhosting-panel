#!/usr/bin/env bash
# End-to-end checks after a full install. Runs inside the test container.
# shellcheck disable=SC2016,SC1091,SC2329
set -uo pipefail
fail=0
pass() { printf '\e[32mPASS\e[0m %s\n' "$*"; }
bad()  { printf '\e[31mFAIL\e[0m %s\n' "$*"; fail=1; }
check() { local d=$1; shift; if "$@" >/tmp/check.out 2>&1; then pass "$d"; else bad "$d"; sed 's/^/     /' /tmp/check.out | tail -n 20; fi; }

source /etc/webhosting-panel/secrets.env
HOST=$(grep ^WHP_HOSTNAME= /etc/webhosting-panel/installer.env | cut -d= -f2)

echo "== services"
for s in nginx mariadb valkey-server unbound nftables postfix dovecot rspamd crowdsec \
         crowdsec-firewall-bouncer php8.3-fpm php8.4-fpm php8.5-fpm whp-cert-retry.timer; do
  check "service $s active" systemctl is-active --quiet "$s"
done
systemctl is-active --quiet clamav-daemon && pass "clamav-daemon active" || echo "SKIP clamav-daemon (signatures may still be downloading)"

echo "== configuration tests"
check "nginx -t" nginx -t
check "postfix check" postfix check
check "doveconf -n" doveconf -n
check "unbound-checkconf" unbound-checkconf
check "rspamadm configtest" rspamadm configtest
check "nft table inet whp loaded" nft list table inet whp
check "crowdsec bouncer tables" bash -c 'nft list tables | grep -q crowdsec'
check "php-fpm8.4 -t" php-fpm8.4 -t
check "nginx has http_v3" bash -c 'nginx -V 2>&1 | grep -q http_v3_module'

echo "== security defaults"
check "valkey refuses anonymous access" bash -c '! valkey-cli ping 2>&1 | grep -q PONG'
check "valkey whp user works" bash -c "REDISCLI_AUTH='$VALKEY_WHP_PASS' valkey-cli --user whp ping | grep -q PONG"
check "valkey only on localhost" bash -c '! ss -ltnH "sport = :6379" | grep -vE "127.0.0.1|\[::1\]"'
check "mariadb only on localhost" bash -c '! ss -ltnH "sport = :3306" | grep -vE "127.0.0.1"'
check "mariadb panel user" mariadb -h127.0.0.1 -uwhp_panel -p"$PANEL_DB_PASS" -e 'SELECT 1' whp_panel
check "mariadb root has no TCP password login" bash -c '! mariadb -h127.0.0.1 -uroot -e "SELECT 1" 2>/dev/null'
check "unknown host gets no answer (444)" bash -c '[[ $(curl -s -o /dev/null -w "%{http_code}" -H "Host: nope.test" http://127.0.0.1/) == 000 ]]'
check "hostname on port 80 redirects to the panel" bash -c "curl -s -o /dev/null -w '%{redirect_url}' -H \"Host: $HOST\" http://127.0.0.1/ | grep -q ':8443/'"
check "server_tokens off" bash -c '! curl -sI -H "Host: nope.test" http://127.0.0.1/.well-known/acme-challenge/x | grep -qi "nginx/"'
check "imap 143 only on loopback" bash -c '! ss -ltnH "sport = :143" | grep -vE "127.0.0.1|\[::1\]"'
check "firewall input policy drop" bash -c 'nft list chain inet whp input | grep -q "policy drop"'

echo "== websites"
check "add site on PHP 8.3" whp-site add site.test --php 8.3 --no-ssl --json
printf '<?php echo "php=", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, " t=", microtime(true);\n' >/var/www/vhosts/site.test/public/index.php
rm -f /var/www/vhosts/site.test/public/index.html
chown s_site_test: /var/www/vhosts/site.test/public/index.php
check "site runs PHP 8.3" bash -c "curl -s -H 'Host: site.test' http://127.0.0.1/ | grep -q php=8.3"
check "switch site to PHP 8.5" whp-site php site.test 8.5
check "site runs PHP 8.5" bash -c "curl -s -H 'Host: site.test' http://127.0.0.1/ | grep -q php=8.5"
curl -s -o /dev/null -H 'Host: site.test' http://127.0.0.1/
check "page cache HIT on second request" bash -c "curl -s -D - -o /dev/null -H 'Host: site.test' http://127.0.0.1/ | grep -qi 'x-cache: HIT'"
check "logged-in cookie bypasses cache" bash -c "curl -sI -H 'Host: site.test' -H 'Cookie: wordpress_logged_in_x=1' http://127.0.0.1/ | grep -qi 'x-cache: BYPASS'"
check "cache purge empties the site's cache" bash -c "whp-site cache site.test purge --json | jq -e .purged >/dev/null && ! curl -s -D - -o /dev/null -H 'Host: site.test' http://127.0.0.1/ | grep -qi 'x-cache: HIT'"
check "cache off" whp-site cache site.test off
check "no cache header when off" bash -c "! curl -sI -H 'Host: site.test' http://127.0.0.1/ | grep -qi 'x-cache'"
echo 'SECRET=1' >/var/www/vhosts/site.test/public/.env
check ".env is not served" bash -c "[[ \$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: site.test' http://127.0.0.1/.env) == 404 ]]"
check "open_basedir blocks /etc/passwd" bash -c "printf '<?php var_dump(@file_get_contents(\"/etc/passwd\"));' >/var/www/vhosts/site.test/public/x.php && curl -s -H 'Host: site.test' http://127.0.0.1/x.php | grep -q 'bool(false)'"
check "site runs as its own user" bash -c "printf '<?php echo posix_getpwuid(posix_geteuid())[\"name\"];' >/var/www/vhosts/site.test/public/u.php && curl -s -H 'Host: site.test' http://127.0.0.1/u.php | grep -q s_site_test"
check "site has its own systemd unit in whp-sites.slice" bash -c "systemctl is-active --quiet whp-site-site_test && [[ \$(systemctl show -p Slice --value whp-site-site_test) == whp-sites.slice ]]"
check "per-site memory accounting" bash -c "whp-site show site.test | jq -e '.resources.memory_bytes > 0'"
check "per-site limits" bash -c "whp-site limits site.test --cpu 150% --memory 512M && [[ \$(systemctl show -p CPUQuotaPerSecUSec --value whp-site-site_test) == 1.500000s ]]"
check "site still answers after limits" bash -c "curl -s -H 'Host: site.test' http://127.0.0.1/ | grep -q php="
check "no HSTS or Alt-Svc on plain HTTP" bash -c "! curl -s -D - -o /dev/null -H 'Host: site.test' http://127.0.0.1/ | grep -qiE 'strict-transport|alt-svc'"
check "security headers" bash -c "curl -sI -H 'Host: site.test' http://127.0.0.1/ | grep -qi x-content-type-options"
check "add laravel site" whp-site add app.test --profile laravel --no-ssl --json
printf '<?php echo "laravel-docroot";\n' >/var/www/vhosts/app.test/app/public/index.php
check "laravel docroot app/public and pretty URLs" bash -c "curl -s -H 'Host: app.test' http://127.0.0.1/some/route | grep -q laravel-docroot"
check "list --json" bash -c "whp-site list --json | jq -e 'length == 2'"
check "show reports disk usage" bash -c "whp-site show app.test | jq -e '.disk.used_mb >= 0'"
check "quota stored" whp-site quota app.test 500 --json
check "remove site" whp-site remove app.test --json
check "site user removed" bash -c '! getent passwd s_app_test'

echo "== mail"
printf 'example.test OK\n' >>/etc/postfix/whp/virtual_domains
printf 'info@example.test example.test/info/\n' >>/etc/postfix/whp/virtual_mailboxes
printf 'info@example.test info@example.test\n' >>/etc/postfix/whp/sender_login
printf 'info@example.test:%s::::::\n' "$(doveadm pw -s ARGON2ID -p 'Test-1234-pass')" >/etc/dovecot/users
postfix reload >/dev/null 2>&1
check "smtp banner on 25" bash -c 'exec 3<>/dev/tcp/127.0.0.1/25; read -t 5 l <&3; [[ $l == 220* ]]'
check "submission offers AUTH after STARTTLS" bash -c "(printf 'EHLO t\r\n'; sleep 2; printf 'QUIT\r\n') | timeout 8 openssl s_client -quiet -starttls smtp -connect 127.0.0.1:587 2>/dev/null | grep -q AUTH"
check "smtps 465 TLS" bash -c "echo QUIT | timeout 5 openssl s_client -quiet -connect 127.0.0.1:465 2>/dev/null | grep -q 220"
check "imaps 993 TLS" bash -c "echo 'a logout' | timeout 5 openssl s_client -quiet -connect 127.0.0.1:993 2>/dev/null | grep -q '^\* OK'"
check "imap login over TLS" bash -c "printf 'a login info@example.test Test-1234-pass\r\nb logout\r\n' | timeout 5 openssl s_client -quiet -connect 127.0.0.1:993 2>/dev/null | grep -q '^a OK'"
check "managesieve 4190" bash -c 'exec 3<>/dev/tcp/127.0.0.1/4190; read -t 5 l <&3; [[ $l == *IMPLEMENTATION* ]]'
printf 'Subject: smoke\nFrom: root@%s\nTo: info@example.test\n\nhello\n' "$HOST" | sendmail -t -i
for i in $(seq 1 30); do
  compgen -G '/var/vmail/example.test/info/Maildir/new/*' >/dev/null && break
  sleep 1
done
check "local delivery through lmtp into Maildir" compgen -G '/var/vmail/example.test/info/Maildir/new/*'
check "rspamd scanned the message" bash -c "rspamc -h 127.0.0.1:11334 -P '$RSPAMD_CONTROLLER_PASS' stat | grep -qE 'Messages scanned: [1-9]'"
check "spam (GTUBE) from outside gets action reject" bash -c "printf 'From: a@example.org\nTo: info@example.test\nSubject: x\n\nXJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X\n' | rspamc -h 127.0.0.1:11333 --ip 203.0.113.5 --from a@example.org | grep -qi 'Action: reject'"

echo "== tools"
check "whp-status --json" bash -c 'whp-status --json | jq -e "length > 5"'
check "malware scan of a site" bash -c 'whp-malware-scan site.test | jq -e ".domain == \"site.test\""'
check "panel.env exists and is not world readable" bash -c '[[ $(stat -c %a /etc/webhosting-panel/panel.env) == 640 ]]'
check "secrets.env is root only" bash -c '[[ $(stat -c %a /etc/webhosting-panel/secrets.env) == 600 ]]'
check "install.log holds no passwords" bash -c 'p=$(grep ^PANEL_DB_PASS= /etc/webhosting-panel/secrets.env | cut -d= -f2); [[ -n $p ]] && ! grep -qF -- "$p" /var/log/webhosting-panel/install.log'
check "panel vhost answers on 8443 with TLS" bash -c "curl -sk -o /dev/null -w '%{http_code}' --resolve $HOST:8443:127.0.0.1 https://$HOST:8443/ | grep -qE '^[1-5][0-9][0-9]$'"

exit $fail
