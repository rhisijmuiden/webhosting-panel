#!/usr/bin/env bash
# End-to-end checks after a full install. Runs inside the test container.
# shellcheck disable=SC2016,SC1091,SC2329,SC2317
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
check "subscription slice with caps" bash -c "whp-site slice s7 --cpu 200% --memory 1024 --tasks 256 --json | jq -e '.memory_mb == 1024' >/dev/null"
check "site moves into the subscription slice" bash -c "whp-site owner site.test s7 --json | jq -e '.slice == \"whp-sites-s7.slice\"' >/dev/null && [[ \$(systemctl show -p Slice --value whp-site-site_test) == whp-sites-s7.slice ]] && curl -s -H 'Host: site.test' http://127.0.0.1/ | grep -q php="
check "subscription slice memory cap active" bash -c "[[ \$(systemctl show -p MemoryMax --value whp-sites-s7.slice) == \$((1024*1024*1024)) ]]"
check "per-site PHP settings as php_admin_value" bash -c "whp-site phpini site.test --set memory_limit=200M --set upload_max_filesize=300M --set max_execution_time=90 --workers 4 --json | jq -e '.php_ini.memory_limit == \"200M\" and .php_workers == 4' >/dev/null && printf '<?php ini_set(\"memory_limit\",\"1G\"); echo ini_get(\"memory_limit\"),\"/\",ini_get(\"upload_max_filesize\");' >/var/www/vhosts/site.test/public/ini.php && curl -s -H 'Host: site.test' http://127.0.0.1/ini.php | grep -q '200M/300M'"
check "nginx upload size follows PHP" bash -c "grep -q 'client_max_body_size 300m' /etc/nginx/whp/sites/site.test/10-php-limits.conf"
check "invalid PHP setting refused" bash -c "! whp-site phpini site.test --set disable_functions=x"
check "traffic log format defined once" bash -c "[[ \$(nginx -T 2>/dev/null | grep -c 'log_format whp_traffic') == 1 ]]"
check "traffic log format has visitor fields" bash -c "nginx -T 2>/dev/null | grep 'log_format whp_traffic' | grep -q 'http_user_agent'"
check "whp-site restores a missing traffic log format" bash -c "rm -f /etc/nginx/conf.d/whp-traffic.conf && whp-site refresh site.test && nginx -t && [[ -f /etc/nginx/conf.d/whp-traffic.conf ]]"
check "traffic is logged per site" bash -c "sleep 1; nginx -s reopen; sleep 1; grep -q '^site.test ' /var/log/nginx/whp-traffic.log || (sleep 65; grep -q '^site.test ' /var/log/nginx/whp-traffic.log)"
check "site back to the shared slice" bash -c "whp-site phpini site.test --reset --json >/dev/null && whp-site owner site.test - --json | jq -e '.slice == \"whp-sites.slice\"' >/dev/null"
check "suspend shows the 503 page" bash -c "whp-site suspend site.test --json | jq -e .suspended >/dev/null && [[ \$(curl -s -o /tmp/s.html -w %{http_code} -H 'Host: site.test' http://127.0.0.1/x.php) == 503 ]] && grep -q 'temporarily unavailable' /tmp/s.html"
check "suspend stops PHP and locks login" bash -c "! systemctl is-active --quiet whp-site-site_test && [[ \$(chage -l s_site_test | grep -i 'account expires') != *never* ]]"
check "suspend is idempotent" bash -c "whp-site suspend site.test --json | jq -e .suspended >/dev/null"
check "unsuspend restores the site" bash -c "whp-site unsuspend site.test --json | jq -e '.suspended == false' >/dev/null && curl -s -H 'Host: site.test' http://127.0.0.1/ | grep -q php= && [[ \$(chage -l s_site_test | grep -i 'account expires') == *never* ]]"
check "no HSTS or Alt-Svc on plain HTTP" bash -c "! curl -s -D - -o /dev/null -H 'Host: site.test' http://127.0.0.1/ | grep -qiE 'strict-transport|alt-svc'"
check "security headers" bash -c "curl -sI -H 'Host: site.test' http://127.0.0.1/ | grep -qi x-content-type-options"
echo "== domain aliases"
check "alias add" bash -c "whp-site alias add site.test alias.test --no-ssl --json | jq -e '.alias.mode == \"serve\" and .alias.www'"
check "alias serves the site" bash -c "curl -s -H 'Host: alias.test' http://127.0.0.1/ | grep -q php= && curl -s -H 'Host: www.alias.test' http://127.0.0.1/ | grep -q php="
check "alias listed in show" bash -c "whp-site show site.test | jq -e '.domain_aliases[0].domain == \"alias.test\"'"
check "alias used twice is refused" bash -c "! whp-site alias add site.test alias.test --no-ssl"
check "alias of an existing site is refused" bash -c "! whp-site alias add site.test site.test --no-ssl"
check "alias redirect mode" bash -c "whp-site alias mode site.test alias.test redirect --json >/dev/null && curl -s -o /dev/null -D - -H 'Host: alias.test' http://127.0.0.1/a?b=1 | grep -qi '^location: http://site.test/a?b=1'"
check "site cannot be added on an alias name" bash -c "! whp-site add alias.test --no-ssl"
check "alias remove" bash -c "whp-site alias remove site.test alias.test --json | jq -e '.domain_aliases == []' >/dev/null && ! curl -s -H 'Host: alias.test' http://127.0.0.1/ | grep -q php="
check "nginx config still valid" nginx -t
echo "== own and wildcard certificates"
whp-site add cert.test --no-ssl --json >/dev/null
d=$(mktemp -d)
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 30 -subj /CN=cert.test \
  -addext "subjectAltName=DNS:cert.test,DNS:www.cert.test" -keyout "$d/key.pem" -out "$d/cert.pem" 2>/dev/null
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 30 -subj /CN=other -keyout "$d/other.pem" -out "$d/other.crt" 2>/dev/null
check "own certificate: a key of another certificate is refused" bash -c "! whp-site ssl cert.test --custom-cert $d/cert.pem --custom-key $d/other.pem"
check "own certificate installed" bash -c "whp-site ssl cert.test --custom-cert $d/cert.pem --custom-key $d/key.pem --custom-chain $d/other.crt --json | jq -e '.ssl == \"active\"' >/dev/null && whp-site cert cert.test | jq -e '.ssl == \"custom\"' >/dev/null"
check "own certificate is served over HTTPS" bash -c "echo | openssl s_client -connect 127.0.0.1:443 -servername cert.test 2>/dev/null | openssl x509 -noout -subject | grep -q 'CN *= *cert.test'"
check "own certificate: HTTP redirects to HTTPS" bash -c "curl -s -o /dev/null -D - -H 'Host: cert.test' http://127.0.0.1/ | grep -qi '^location: https://cert.test/'"
check "own certificate: chain appended, key private" bash -c "[[ \$(grep -c 'BEGIN CERTIFICATE' /etc/webhosting-panel/ssl/cert.test/fullchain.pem) == 2 && \$(stat -c %a /etc/webhosting-panel/ssl/cert.test/privkey.pem) == 600 ]]"
check "cert shows the served files" bash -c "whp-site cert cert.test | jq -e '.ssl == \"custom\" and .cert_file == \"/etc/webhosting-panel/ssl/cert.test/fullchain.pem\"' >/dev/null && whp-site cert --all | jq -e '.certificates | length > 1' >/dev/null"
check "own certificate is not replaced by the hourly retry" bash -c "whp-site ssl --pending >/dev/null 2>&1; whp-site cert cert.test | jq -e '.ssl == \"custom\"' >/dev/null"
check "back to Let's Encrypt keeps the own certificate when it cannot issue" bash -c "! whp-site ssl cert.test --letsencrypt >/dev/null 2>&1 && whp-site cert cert.test | jq -e '.ssl == \"custom\"' >/dev/null"
check "wildcard without a Cloudflare token fails cleanly" bash -c "! whp-site ssl cert.test --wildcard 2>/tmp/wc.err && grep -q 'Cloudflare' /tmp/wc.err && whp-site cert cert.test | jq -e '.ssl == \"custom\"' >/dev/null"
check "certbot Cloudflare plugin installed" python3 -c 'import certbot_dns_cloudflare'
check "agent reads certificate details" bash -c "python3 $(dirname "$0")/agent_call.py ssl.info '{\"domain\":\"cert.test\"}' | jq -e '.certificate.names == [\"cert.test\",\"www.cert.test\"] and .certificate.days_left >= 29' >/dev/null"
check "remove site with own certificate" bash -c "whp-site remove cert.test --json >/dev/null && [[ ! -e /etc/webhosting-panel/ssl/cert.test ]]"
rm -rf "$d"
check "nginx config valid after certificates" nginx -t
check "add laravel site" whp-site add app.test --profile laravel --no-ssl --json
printf '<?php echo "laravel-docroot";\n' >/var/www/vhosts/app.test/app/public/index.php
check "laravel docroot app/public and pretty URLs" bash -c "curl -s -H 'Host: app.test' http://127.0.0.1/some/route | grep -q laravel-docroot"
check "list --json" bash -c "whp-site list --json | jq -e 'length == 2'"
check "show reports disk usage" bash -c "whp-site show app.test | jq -e '.disk.used_mb >= 0'"
check "quota stored" whp-site quota app.test 500 --json
check "remove site" whp-site remove app.test --json
check "site user removed" bash -c '! getent passwd s_app_test'

echo "== wordpress toolkit"
check "wp-cli installed" bash -c "php /usr/local/bin/wp --allow-root --version | grep -q 'WP-CLI 2'"
check "add wordpress site" whp-site add wp.test --profile wordpress --no-ssl --json
mariadb -e "CREATE DATABASE IF NOT EXISTS wp_smoke; CREATE USER IF NOT EXISTS 'wp_smoke'@'localhost' IDENTIFIED BY 'Smoke-Db-Pass-2026'; GRANT ALL ON wp_smoke.* TO 'wp_smoke'@'localhost';"
rm -f /var/www/vhosts/wp.test/public/index.html
check "toolkit end to end (detect, status, cron, password, login link, update)" python3 "$(dirname "$0")/wp_smoke.py" wp.test

echo "== subdomains and cloning"
check "add subdomain test.wp.test of wp.test" whp-site add test.wp.test --parent wp.test --no-ssl --json
check "subdomain knows its parent, parent lists it" bash -c "whp-site show test.wp.test --json | jq -e '.parent == \"wp.test\"' && whp-site show wp.test --json | jq -e '.subdomains == [\"test.wp.test\"]'"
check "a subdomain of another domain is refused" bash -c "! whp-site add test.site.test --parent wp.test --no-ssl"
check "a subdomain has no quota of its own" bash -c "! whp-site quota test.wp.test 100"
check "the main site cannot be removed while it has subdomains" bash -c "! whp-site remove wp.test"
check "clone WordPress into the subdomain (files, database, addresses)" python3 "$(dirname "$0")/clone_smoke.py" wp.test test.wp.test
check "WordPress staging: push to live with backup, restore" python3 "$(dirname "$0")/staging_smoke.py" test.wp.test wp.test

echo "== git deployments"
check "git, ssh-keygen and npm installed" bash -c "command -v git && command -v ssh-keygen && command -v npm"
check "deploy, zero downtime switch, failed build, rollback, remove" python3 "$(dirname "$0")/git_smoke.py" site.test

echo "== databases and phpMyAdmin"
check "phpMyAdmin installed for sign-on only" bash -c "test -f /var/www/phpmyadmin/whp-sso.php && ! test -e /var/www/phpmyadmin/setup"
check "databases, users, passwords and phpMyAdmin sign-on" python3 "$(dirname "$0")/db_smoke.py"

echo "== mail"
printf 'example.test OK\n' >>/etc/postfix/whp/virtual_domains
printf 'info@example.test example.test/info/\n' >>/etc/postfix/whp/virtual_mailboxes
printf 'info@example.test info@example.test\n' >>/etc/postfix/whp/sender_login
printf 'info@example.test:%s::::::\n' "$(doveadm pw -s ARGON2ID -p 'Test-1234-pass')" >/etc/dovecot/users
# Second mailbox for the autoresponder and IMAP import checks.
printf 'bob@example.test example.test/bob/\n' >>/etc/postfix/whp/virtual_mailboxes
printf 'bob@example.test bob@example.test\n' >>/etc/postfix/whp/sender_login
printf 'bob@example.test:%s::::::\n' "$(doveadm pw -s ARGON2ID -p 'Test-1234-bob')" >>/etc/dovecot/users
install -d -m 0700 -o vmail -g vmail /var/vmail/example.test /var/vmail/example.test/info /var/vmail/example.test/bob
postfix reload >/dev/null 2>&1
check "smtp banner on 25" bash -c 'exec 3<>/dev/tcp/127.0.0.1/25; read -t 5 l <&3; [[ $l == 220* ]]'
check "submission offers AUTH after STARTTLS" bash -c "(printf 'EHLO t\r\n'; sleep 2; printf 'QUIT\r\n') | timeout 8 openssl s_client -quiet -starttls smtp -connect 127.0.0.1:587 2>/dev/null | grep -q AUTH"
check "smtps 465 TLS" bash -c "echo QUIT | timeout 5 openssl s_client -quiet -connect 127.0.0.1:465 2>/dev/null | grep -q 220"
check "imaps 993 TLS" bash -c "echo 'a logout' | timeout 5 openssl s_client -quiet -connect 127.0.0.1:993 2>/dev/null | grep -q '^\* OK'"
check "imap login over TLS" bash -c "printf 'a login info@example.test Test-1234-pass\r\nb logout\r\n' | timeout 5 openssl s_client -quiet -connect 127.0.0.1:993 2>/dev/null | grep -q '^a OK'"
check "managesieve 4190" bash -c 'exec 3<>/dev/tcp/127.0.0.1/4190; read -t 5 l <&3; [[ $l == *IMPLEMENTATION* ]]'
check "doveconf accepts the spam sieve_script" bash -c "doveconf -n | grep -q whp_spam && [[ -f /var/lib/webhosting-panel/mail/sieve/before.svbin ]]"
check "hostname certificate keeps its key (DANE)" grep -q -- "--reuse-key" /usr/local/sbin/whp-cert
check "rspamd adds X-Spam-Level for the quarantine rules" grep -q x-spam-level /etc/rspamd/local.d/milter_headers.conf
printf 'Subject: smoke\nFrom: root@%s\nTo: info@example.test\n\nhello\n' "$HOST" | sendmail -t -i
for i in $(seq 1 30); do
  compgen -G '/var/vmail/example.test/info/Maildir/new/*' >/dev/null && break
  sleep 1
done
check "local delivery through lmtp into Maildir" compgen -G '/var/vmail/example.test/info/Maildir/new/*'
check "rspamd scanned the message" bash -c "rspamc -h 127.0.0.1:11334 -P '$RSPAMD_CONTROLLER_PASS' stat | grep -qE 'Messages scanned: [1-9]'"
check "website mail SMTP listens on loopback only" bash -c "ss -ltnH | grep -q '127.0.0.1:10587 ' && ! ss -ltnH | grep -qE '(0\.0\.0\.0|\[::\]|\*):10587 '"
check "agent answers wp.mail_status" bash -c "python3 $(dirname "$0")/agent_call.py wp.mail_status '{\"domain\":\"wp.test\"}' | jq -e '.smtp == true' >/dev/null"
check "WordPress mail through this server (DKIM, sender, Reply-To, staging held back)" python3 "$(dirname "$0")/wp_mail_smoke.py" wp.test test.wp.test info@example.test
check "spam (GTUBE) from outside gets action reject" bash -c "printf 'From: a@example.org\nTo: info@example.test\nSubject: x\n\nXJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X\n' | rspamc -h 127.0.0.1:11333 --ip 203.0.113.5 --from a@example.org | grep -qi 'Action: reject'"

echo "== tools"
check "whp-status --json" bash -c 'whp-status --json | jq -e "length > 5"'
check "malware scan of a site" bash -c 'whp-malware-scan site.test | jq -e ".domain == \"site.test\""'
check "panel.env exists and is not world readable" bash -c '[[ $(stat -c %a /etc/webhosting-panel/panel.env) == 640 ]]'
check "secrets.env is root only" bash -c '[[ $(stat -c %a /etc/webhosting-panel/secrets.env) == 600 ]]'
check "install.log holds no passwords" bash -c 'p=$(grep ^PANEL_DB_PASS= /etc/webhosting-panel/secrets.env | cut -d= -f2); [[ -n $p ]] && ! grep -qF -- "$p" /var/log/webhosting-panel/install.log'
check "panel vhost answers on 8443 with TLS" bash -c "curl -sk -o /dev/null -w '%{http_code}' --resolve $HOST:8443:127.0.0.1 https://$HOST:8443/ | grep -qE '^[1-5][0-9][0-9]$'"
if [[ -f /opt/webhosting-panel/panel/artisan ]]; then
  check "panel login page renders" bash -c "[[ \$(curl -sk -o /dev/null -w %{http_code} --resolve \"$HOST:8443:127.0.0.1\" https://$HOST:8443/login) == 200 ]]"
  check "panel asset links keep port 8443" bash -c "curl -sk --resolve \"$HOST:8443:127.0.0.1\" https://$HOST:8443/login | grep -q \"https://$HOST:8443/assets/app.css\""
  check "panel stylesheet is served as CSS" bash -c "curl -skI --resolve \"$HOST:8443:127.0.0.1\" https://$HOST:8443/assets/app.css | grep -qi 'content-type: text/css'"
  check "panel queue worker active" systemctl is-active --quiet whp-queue.service
  check "agent active" systemctl is-active --quiet whp-agent.service

  echo "== security scan"
  AC="python3 $(dirname "$0")/agent_call.py"
  check "server posture (apt, sshd, services)" bash -c "$AC posture.get | jq -e '(.services | has(\"nftables\")) and .updates.total != null and .ssh.root_login != null'"
  check "malware report of a site" bash -c "$AC malware.report '{\"domain\":\"site.test\"}' | jq -e '.scanned_at != null'"
  check "vulnerability match refuses a bad slug" bash -c "! $AC vuln.match '{\"source\":\"wpvulnerability\",\"items\":[{\"type\":\"plugin\",\"slug\":\"../x\",\"version\":\"1\"}]}'"

  echo "== app installer"
  # One request to the agent over its socket; prints the data, fails on an error.
  agent() {
    python3 - "$1" "${2:-"{}"}" <<'PY'
import json, socket, sys
s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
s.connect("/run/whp-agent/agent.sock")
s.sendall((json.dumps({"id": "smoke", "action": sys.argv[1], "params": json.loads(sys.argv[2])}) + "\n").encode())
buf = b""
while not buf.endswith(b"\n"):
    chunk = s.recv(65536)
    if not chunk:
        break
    buf += chunk
r = json.loads(buf)
print(json.dumps(r.get("data") if r.get("ok") else r.get("error")))
sys.exit(0 if r.get("ok") else 1)
PY
  }
  # Start a job and wait for it; prints the finished job.
  agent_job() {
    local id i st
    id=$(agent "$1" "$2" | jq -r .job) || return 1
    for i in $(seq 1 300); do
      st=$(agent job.status "{\"job\":\"$id\"}")
      [[ $(jq -r .status <<<"$st") != running ]] && break
      sleep 2
    done
    printf '%s\n' "$st" >/tmp/app-job.json
    jq -e '.status == "succeeded"' <<<"$st" >/dev/null || { jq . <<<"$st"; return 1; }
  }
  app_params() { printf '{"domain":"%s","app":"%s","path":"%s","title":"Smoke","admin_user":"smoke","admin_email":"smoke@example.test","admin_password":"Smoke-test-pass-123","locale":"%s"}' "$@"; }

  export -f agent app_params agent_job
  check "server: status with kernel, never a shutdown" bash -c 'agent server.status | jq -e "(.kernel | length > 0) and .can_shutdown == false and (.reboot_reasons | type == \"array\")"'
  check "server: list of pending updates" bash -c 'agent server.updates | jq -e ".packages | type == \"array\""'
  check "server: health check answers for services and sites" bash -c 'agent server.health "{\"sites\":[{\"domain\":\"example.test\",\"ssl\":false}]}" | jq -e "(.services | length > 0) and (.sites | length == 1)"'
  check "server: cleanup (dry run)" agent_job server.cleanup '{"dry_run":true}'
  check "server: database optimize" agent_job server.db_optimize '{"min_free_mb":1}'
  check "server: PHP versions" bash -c 'agent server.php_versions | jq -e ".panel == \"8.4\" and (.installed | index(\"8.4\") != null)"'
  check "mail log: smoke message is in the journal, delivered locally" bash -c 'agent mail.log_read "{\"since_hours\":1}" | jq -e "[.events[] | select(.kind == \"delivery\" and .to == \"info@example.test\" and .status == \"sent\" and .local)] | length > 0"'
  check "mail log: long queue ids and a cursor" bash -c 'agent mail.log_read "{\"since_hours\":1}" | jq -e "(.cursor | length > 0) and ([.events[] | select(.kind == \"pickup\") | .qid | length > 12] | length > 0)"'
  check "mail log: rspamd history has the scan" bash -c 'agent mail.spam_history | jq -e ".rows | length > 0"'

  echo "== mail extras (autoresponder, IMAP import)"
  check "dovecot has the import master user and the autoreply script slot" bash -c "doveconf -n | grep -q whp_autoreply && doveconf -n | grep -q master-users && [[ -s /etc/dovecot/master-users ]]"
  check "imapsync installed and its Perl modules load" bash -c "/usr/local/bin/imapsync --version && perl -c /usr/local/bin/imapsync"
  check "autoreply: script written and compiled" bash -c 'agent mail.autoreply_sync "{\"autoreplies\":[{\"email\":\"info@example.test\",\"subject\":\"Smoke away\",\"body\":\"I am away until Monday.\",\"days\":1}]}" | jq -e ".written == [\"info@example.test\"]" && [[ -f /var/vmail/example.test/info/whp-autoreply.svbin ]]'
  printf 'Subject: question\nFrom: bob@example.test\nTo: info@example.test\n\nare you there?\n' | sendmail -f bob@example.test -t -i
  autoreply_arrived() { grep -lqs '^Subject: Smoke away' /var/vmail/example.test/bob/Maildir/new/* /var/vmail/example.test/bob/Maildir/cur/*; }
  for i in $(seq 1 30); do autoreply_arrived && break; sleep 1; done
  check "autoreply: the sender gets the answer" autoreply_arrived
  check "autoreply: removed again" bash -c 'agent mail.autoreply_sync "{\"autoreplies\":[]}" | jq -e ".removed == [\"info@example.test\"]" && [[ ! -e /var/vmail/example.test/info/whp-autoreply.sieve ]]'
  check "imap import: public-only by default (loopback refused)" bash -c 'agent mail.imap_import_test "{\"host\":\"127.0.0.1\",\"port\":143,\"security\":\"none\",\"user\":\"info@example.test\",\"password\":\"Test-1234-pass\"}" | jq -e ".code == \"forbidden\""'
  printf 'IMAP_IMPORT_ALLOW_PRIVATE=yes\n' >/etc/webhosting-panel/mail-import.conf
  check "imap import: test login counts the source messages" bash -c 'agent mail.imap_import_test "{\"host\":\"127.0.0.1\",\"port\":143,\"security\":\"none\",\"user\":\"info@example.test\",\"password\":\"Test-1234-pass\"}" | jq -e ".ok and .messages >= 2"'
  check "imap import: wrong password is refused" bash -c 'agent mail.imap_import_test "{\"host\":\"127.0.0.1\",\"port\":143,\"security\":\"none\",\"user\":\"info@example.test\",\"password\":\"wrong\"}" | jq -e ".code == \"forbidden\""'
  check "imap import: started as a unit" bash -c 'agent mail.imap_import_start "{\"id\":1,\"email\":\"bob@example.test\",\"host\":\"127.0.0.1\",\"port\":143,\"security\":\"none\",\"user\":\"info@example.test\",\"password\":\"Test-1234-pass\"}" | jq -e ".state == \"running\""'
  for i in $(seq 1 90); do
    [[ $(agent mail.imap_import_status '{"id":1}' | jq -r .state) != running ]] && break
    sleep 2
  done
  agent mail.imap_import_status '{"id":1}' | jq -c '{state, exit_code, progress, error_lines}' || true
  check "imap import: finished with all messages copied" bash -c 'agent mail.imap_import_status "{\"id\":1}" | jq -e ".state == \"done\" and .progress.percent == 100 and .progress.transferred >= 2 and .progress.errors == 0"'
  check "imap import: the source mail is in the target mailbox" bash -c "grep -lqs '^Subject: smoke' /var/vmail/example.test/bob/Maildir/cur/* /var/vmail/example.test/bob/Maildir/new/*"
  check "imap import: password files are gone" bash -c '[[ ! -e /var/lib/webhosting-panel/mail/imports/1/pass1 && ! -e /var/lib/webhosting-panel/mail/imports/1/pass2 ]]'
  check "imap import: master user refused from outside loopback" grep -q 'allow_nets=127.0.0.1/32,::1/128' /etc/dovecot/master-users
  agent mail.imap_import_forget '{"id":1}' >/dev/null || true
  rm -f /etc/webhosting-panel/mail-import.conf
  echo "== FTP accounts (ProFTPD)"
  check "proftpd configuration valid" proftpd -t
  check "proftpd active" bash -c 'systemctl is-active proftpd || { journalctl -u proftpd -n 30 --no-pager; false; }'
  check "proftpd listens on 21 and 2222" bash -c 'ss -ltnH "sport = :21" | grep -q . && ss -ltnH "sport = :2222" | grep -q .'
  check "firewall opens FTPS, SFTP and the passive ports" bash -c 'nft list set inet whp tcp_open | grep -q 2222 && nft list set inet whp tcp_open | grep -q 49152-49407'
  FTP_HASH=$(openssl passwd -6 'Smoke-ftp-pass-123')
  export FTP_HASH
  check "ftp.sync writes the accounts" bash -c 'agent ftp.sync "{\"accounts\":[{\"login\":\"up@site.test\",\"domain\":\"site.test\",\"password_hash\":\"$FTP_HASH\",\"folder\":\"public/ftp-up\"},{\"login\":\"ro@site.test\",\"domain\":\"site.test\",\"password_hash\":\"$FTP_HASH\",\"folder\":\"public\",\"read_only\":true}]}" | jq -e ".accounts == 2 and .read_only == 1"'
  echo smoke >/tmp/ftp-smoke.txt
  check "FTPS upload lands in the account folder, owned by the site user" bash -c 'curl -sS --ssl-reqd -k -u "up@site.test:Smoke-ftp-pass-123" -T /tmp/ftp-smoke.txt ftp://127.0.0.1/ && [[ $(stat -c %U /var/www/vhosts/site.test/public/ftp-up/ftp-smoke.txt) == s_site_test ]]'
  check "plain FTP without TLS is refused" bash -c '! curl -s -u "up@site.test:Smoke-ftp-pass-123" ftp://127.0.0.1/'
  check "SFTP login lists the account folder" bash -c 'curl -sS -k -u "ro@site.test:Smoke-ftp-pass-123" sftp://127.0.0.1:2222/ | grep -q ftp-up'
  check "read-only account cannot upload" bash -c '! curl -s -k -u "ro@site.test:Smoke-ftp-pass-123" -T /tmp/ftp-smoke.txt sftp://127.0.0.1:2222/x.txt && [[ ! -e /var/www/vhosts/site.test/public/x.txt ]]'
  check "Linux users cannot log in to ProFTPD" bash -c '! curl -s --ssl-reqd -k -u "root:x" ftp://127.0.0.1/'
  check "ftp.status reports the ports" bash -c 'agent ftp.status | jq -e ".active and .ftp_port == 21 and .sftp_port == 2222 and .accounts == 2"'
  check "ftp.sync removes the accounts again" bash -c 'agent ftp.sync "{\"accounts\":[]}" | jq -e ".accounts == 0" && ! curl -s --ssl-reqd -k -u "up@site.test:Smoke-ftp-pass-123" ftp://127.0.0.1/'
  rm -rf /tmp/ftp-smoke.txt /var/www/vhosts/site.test/public/ftp-up
  check "app catalog" bash -c 'agent apps.catalog | jq -e "[.apps[].id] == [\"wordpress\",\"woocommerce\",\"joomla\",\"nextcloud\",\"piwigo\",\"mediawiki\",\"dokuwiki\",\"phpbb\",\"freshrss\"]"'
  agent apps.catalog | jq -r '.apps[] | "   \(.id): \(.latest_version) released \(.released_at // "-") maintained=\(.maintained)"' || true
  check "app catalog: every app has a latest release and is maintained" bash -c 'agent apps.catalog | jq -e "all(.apps[]; .latest_version != null and .maintained == true)"'
  check "app catalog: every app has a release date" bash -c 'agent apps.catalog "{\"refresh\":true}" | jq -e "all(.apps[]; .released_at != null)"'
  check "add a site for the app installer" whp-site add appwp.test --php 8.4 --no-ssl --json
  check "install WordPress (nl) through the agent" agent_job apps.install "$(app_params appwp.test wordpress '' nl)"
  WP_ID=$(jq -r .result.id /tmp/app-job.json); WP_DB=$(jq -r .result.database /tmp/app-job.json)
  check "WordPress home page answers" bash -c "curl -s -H 'Host: appwp.test' http://127.0.0.1/ | grep -q 'wp-content'"
  check "WordPress is in Dutch" bash -c "curl -s -H 'Host: appwp.test' http://127.0.0.1/ | grep -q 'lang=\"nl-NL\"'"
  check "WordPress login page" bash -c "[[ \$(curl -s -o /dev/null -w %{http_code} -H 'Host: appwp.test' http://127.0.0.1/wp-login.php) == 200 ]]"
  check "WordPress files belong to the site user and nginx can read them" bash -c "[[ \$(stat -c %U /var/www/vhosts/appwp.test/public/wp-includes/version.php) == s_appwp_test && \$(stat -c %a /var/www/vhosts/appwp.test/public/wp-includes/css) == 755 ]]"
  check "wp-config.php is private" bash -c "[[ \$(stat -c %a /var/www/vhosts/appwp.test/public/wp-config.php) == 600 ]]"
  check "WordPress database and user exist" bash -c "mariadb -N -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$WP_DB'\" | grep -qv '^0$'"
  check "admin password is not in the job file" bash -c "! grep -rq 'Smoke-test-pass-123' /var/lib/webhosting-panel/jobs /var/lib/webhosting-panel/apps"
  check "second install in a used folder is refused" bash -c '! agent apps.install "$(app_params appwp.test joomla portal en)"'
  check "remove WordPress" agent_job apps.remove "{\"domain\":\"appwp.test\",\"id\":\"$WP_ID\"}"
  check "after removal: placeholder back, database gone" bash -c "[[ \$(ls /var/www/vhosts/appwp.test/public) == index.html ]] && ! mariadb -e 'USE \`$WP_DB\`' 2>/dev/null"
  check "add a site for Joomla" whp-site add joomla.test --php 8.4 --no-ssl --json
  check "install Joomla in a subfolder" agent_job apps.install "$(app_params joomla.test joomla portal en)"
  check "Joomla answers in /portal/" bash -c "curl -s -L -H 'Host: joomla.test' http://127.0.0.1/portal/ | grep -qi 'joomla'"
  check "Joomla administrator login" bash -c "[[ \$(curl -s -o /dev/null -w %{http_code} -H 'Host: joomla.test' http://127.0.0.1/portal/administrator/) == 200 ]]"
  check "Joomla installation folder is gone" test ! -e /var/www/vhosts/joomla.test/public/portal/installation

  # The rest of the catalog, side by side in subfolders of one site (their Nginx snippets must coexist).
  code() { curl -s -o /dev/null -w '%{http_code}' -H "Host: $1" "http://127.0.0.1$2"; }
  export -f code
  check "add a site for the app catalog" whp-site add apps.test --php 8.4 --no-ssl --json
  check "install Piwigo (nl) in /photos" agent_job apps.install "$(app_params apps.test piwigo photos nl)"
  check "Piwigo gallery answers" bash -c "curl -s -L -H 'Host: apps.test' http://127.0.0.1/photos/ | grep -qi piwigo"
  check "Piwigo database config is private and not served" bash -c "[[ \$(stat -c %a /var/www/vhosts/apps.test/public/photos/local/config/database.inc.php) == 600 && \$(code apps.test /photos/local/config/database.inc.php) == 404 ]]"
  check "install MediaWiki (nl) in /wiki" agent_job apps.install "$(app_params apps.test mediawiki wiki nl)"
  # MediaWiki redirects to its main page with an absolute URL: resolve the site name to this server.
  check "MediaWiki main page answers" bash -c "curl -s -L --resolve apps.test:80:127.0.0.1 'http://apps.test/wiki/index.php' | grep -qi 'mediawiki'"
  check "MediaWiki LocalSettings.php is private, maintenance is closed" bash -c "[[ \$(stat -c %a /var/www/vhosts/apps.test/public/wiki/LocalSettings.php) == 600 && \$(code apps.test /wiki/maintenance/run.php) == 404 ]]"
  check "install DokuWiki (nl, no database) in /docs" agent_job apps.install "$(app_params apps.test dokuwiki docs nl)"
  check "DokuWiki answers" bash -c "curl -s -L -H 'Host: apps.test' 'http://127.0.0.1/docs/doku.php' | grep -qi dokuwiki"
  check "DokuWiki data and conf are not served, install.php is gone" bash -c "[[ \$(code apps.test /docs/conf/users.auth.php) == 404 && \$(code apps.test /docs/data/pages/wiki/dokuwiki.txt) == 404 ]] && test ! -e /var/www/vhosts/apps.test/public/docs/install.php"
  check "DokuWiki admin password hash verifies" bash -c "php8.4 -r '\$l = preg_grep(\"/^smoke:/\", file(\"/var/www/vhosts/apps.test/public/docs/conf/users.auth.php\")); exit(password_verify(\"Smoke-test-pass-123\", explode(\":\", reset(\$l))[1]) ? 0 : 1);'"
  check "install phpBB in /forum" agent_job apps.install "$(app_params apps.test phpbb forum en)"
  check "phpBB board answers" bash -c "curl -s -L -H 'Host: apps.test' http://127.0.0.1/forum/ | grep -qi phpbb"
  check "phpBB installer is gone, config.php is closed" bash -c "test ! -e /var/www/vhosts/apps.test/public/forum/install && [[ \$(code apps.test /forum/config.php) == 404 ]]"
  check "install FreshRSS (nl) in /rss" agent_job apps.install "$(app_params apps.test freshrss rss nl)"
  RSS_ID=$(jq -r .result.id /tmp/app-job.json)
  check "FreshRSS login page answers" bash -c "curl -s -L -H 'Host: apps.test' http://127.0.0.1/rss/ | grep -qi freshrss"
  check "FreshRSS data is not served" bash -c "[[ \$(code apps.test /rss/data/config.php) == 404 ]]"
  check "FreshRSS admin password hash verifies" bash -c "php8.4 -r '\$c = include \"/var/www/vhosts/apps.test/public/rss/data/users/smoke/config.php\"; exit(password_verify(\"Smoke-test-pass-123\", \$c[\"passwordHash\"]) ? 0 : 1);'"
  check "FreshRSS feed refresh timer is active" systemctl is-active --quiet "whp-app-$RSS_ID.timer"
  check "FreshRSS feed refresh runs as the site user" bash -c "systemctl start whp-app-$RSS_ID.service && [[ \$(systemctl show -p User --value whp-app-$RSS_ID.service) == s_apps_test ]]"
  check "app list reports versions from disk" bash -c 'agent apps.list "{\"domain\":\"apps.test\"}" | jq -e "(.apps | length) > 0 and all(.apps[]; .version != null and .update_available == false)"'
  check "page cache is off for apps with their own sessions" bash -c "whp-site show apps.test --json | jq -e '.cache == false'"
  check "no admin password left in jobs, app state or site tmp" bash -c "! grep -rq 'Smoke-test-pass-123' /var/lib/webhosting-panel/jobs /var/lib/webhosting-panel/apps /var/www/vhosts/apps.test/tmp"
  check "remove FreshRSS: timer is gone" bash -c "agent_job apps.remove '{\"domain\":\"apps.test\",\"id\":\"$RSS_ID\"}' && test ! -e /etc/systemd/system/whp-app-$RSS_ID.timer"
  check "add a site for WooCommerce" whp-site add woo.test --php 8.4 --no-ssl --json
  check "install WooCommerce (nl)" agent_job apps.install "$(app_params woo.test woocommerce '' nl)"
  check "WooCommerce plugin is active" bash -c "runuser -u s_woo_test -- php8.4 /usr/local/bin/wp --path=/var/www/vhosts/woo.test/public plugin is-active woocommerce"
  check "WooCommerce shop uses euros" bash -c "[[ \$(runuser -u s_woo_test -- php8.4 /usr/local/bin/wp --path=/var/www/vhosts/woo.test/public option get woocommerce_currency) == EUR ]]"

  echo "== file manager"
  whp-site add files.test --php 8.4 --no-ssl --json >/dev/null
  fm() { agent "$1" "$(jq -nc '$ARGS.named' --arg domain files.test "${@:2}")"; }
  fm_spool() { local n; n=$1-$(openssl rand -hex 16); printf '%s' "$n"; }
  export -f fm fm_spool agent_job
  check "file manager lists the site root" bash -c 'fm files.list | jq -e "[.entries[].name] | index(\"public\") != null"'
  check "file manager stays inside the webspace" bash -c '! fm files.list --arg path ../site.test && ! fm files.list --arg path public/../../site.test'
  check "file manager spool belongs to the panel user" bash -c "[[ \$(stat -c '%U %a' /var/lib/webhosting-panel/files-spool) == 'whp 700' ]]"
  up=$(fm_spool up)
  printf '<?php echo "fm-ok";\n' | runuser -u whp -- tee "/var/lib/webhosting-panel/files-spool/$up" >/dev/null
  check "upload is written as the site user" bash -c "fm files.upload --arg dir public --arg name tools/fm.php --arg spool $up >/dev/null && [[ \$(stat -c %U /var/www/vhosts/files.test/public/tools/fm.php) == s_files_test ]] && curl -s -H 'Host: files.test' http://127.0.0.1/tools/fm.php | grep -q fm-ok"
  rd=$(fm_spool rd)
  check "editor read goes through the spool" bash -c "fm files.read --arg path public/tools/fm.php --arg spool $rd >/dev/null && runuser -u whp -- grep -q fm-ok /var/lib/webhosting-panel/files-spool/$rd"
  check "zip and unzip as jobs" bash -c 'agent_job files.compress "{\"domain\":\"files.test\",\"paths\":[\"public/tools\"],\"dir\":\"\",\"name\":\"tools\"}" && agent_job files.extract "{\"domain\":\"files.test\",\"path\":\"tools.zip\",\"to\":\"tmp\"}" && test -f /var/www/vhosts/files.test/tmp/tools/fm.php'
  check "managed folders cannot be deleted" bash -c '! agent files.delete "{\"domain\":\"files.test\",\"paths\":[\"public\"]}" && test -d /var/www/vhosts/files.test/public'
  check "web terminal tools installed" bash -c "command -v ttyd && command -v bwrap"
  check "terminal sandbox hides the server" bash -c "cd /opt/webhosting-panel/agent && python3 -c '
import subprocess, sys
from whp_agent.actions.files import _site
from whp_agent.actions.terminal import sandbox_argv
site = _site({\"domain\": \"files.test\"})
argv = sandbox_argv(site, [\"/bin/bash\", \"-c\", \"test ! -e /etc/webhosting-panel && test ! -e /var/www/vhosts/site.test && ! touch /usr/x 2>/dev/null && touch public/sandbox-ok && php -v >/dev/null\"])
sys.exit(subprocess.run(argv, user=site[\"uid\"], group=site[\"gid\"], extra_groups=[]).returncode)
' && test -f /var/www/vhosts/files.test/public/sandbox-ok"
  TERM_START=$(agent terminal.start '{"domain":"files.test","minutes":5}') || echo "terminal.start: $TERM_START"
  TOK=$(jq -r '.token // empty' <<<"$TERM_START" 2>/dev/null || true)
  check "terminal session starts (ttyd socket)" bash -c "[[ -n '$TOK' ]] && test -S /run/whp-terminal/$TOK/ttyd.sock || { ls -la /run/whp-terminal /run/whp-terminal/$TOK; systemctl status --no-pager whp-term-$TOK.service; journalctl -u whp-term-$TOK.service --no-pager | tail -20; false; }"
  check "ttyd serves the terminal page" bash -c "curl -s --unix-socket /run/whp-terminal/$TOK/ttyd.sock http://localhost/terminal/$TOK/ | grep -qi '<html'"
  check "terminal proxy refuses visitors without a session" bash -c "code=\$(curl -sk -o /dev/null -w %{http_code} --resolve \"$HOST:8443:127.0.0.1\" https://$HOST:8443/terminal/$TOK/); echo \"HTTP \$code\"; [[ \$code == 401 ]]"
  check "terminal session stops" bash -c "agent terminal.stop '{\"token\":\"$TOK\"}' >/dev/null && ! test -e /run/whp-terminal/$TOK"

  echo "== login protection"
  agent_call() {
    python3 - "$1" "$2" <<'PY'
import json, socket, sys
s = socket.socket(socket.AF_UNIX)
s.connect("/run/whp-agent/agent.sock")
s.sendall((json.dumps({"id": "smoke", "action": sys.argv[1], "params": json.loads(sys.argv[2])}) + "\n").encode())
buf = b""
while not buf.endswith(b"\n"):
    chunk = s.recv(65536)
    if not chunk:
        break
    buf += chunk
r = json.loads(buf)
print(json.dumps(r))
sys.exit(0 if r.get("ok") else 1)
PY
  }
  export -f agent_call
  # wait_ban <ip> <scenario>: CrowdSec bans the address for that scenario within 60 seconds.
  wait_ban() {
    local i
    for i in $(seq 1 60); do
      cscli decisions list --ip "$1" -o json 2>/dev/null | jq -e --arg s "$2" '[.[]?.decisions[]?.scenario] | index($s) != null' >/dev/null && return 0
      sleep 1
    done
    cscli decisions list --ip "$1"; cscli alerts list --ip "$1"
    return 1
  }
  now_iso() { date +%Y-%m-%dT%H:%M:%S%:z; }
  now_clf() { date '+%d/%b/%Y:%H:%M:%S %z'; }
  AUTH_LOG=/var/log/webhosting-panel/auth.log
  SITE_LOG=/var/www/vhosts/site.test/logs/access.log
  check "panel applies login protection" runuser -u whp -- php /opt/webhosting-panel/panel/artisan whp:security:apply
  # Local scenarios do not show up in "cscli scenarios list"; the ban checks below prove they are loaded.
  check "crowdsec accepts the generated configuration" bash -c 'grep -q "whp/wordpress-bf" /etc/crowdsec/scenarios/whp-login.yaml && crowdsec -c /etc/crowdsec/config.yaml -t'
  check "agent applies allowlists and per-site settings (crowdsec -t passes)" agent_call security.apply \
    '{"allowlist":[{"address":"198.51.100.0/24","comment":"ci"}],"sites":[{"domain":"site.test","allowlist":["203.0.113.52"]},{"domain":"off.test","enabled":false,"wordpress":false}]}'
  check "an invalid setting is refused" bash -c '! agent_call security.apply "{\"services\":{\"ssh\":{\"temp_after\":9,\"perm_after\":3}}}"'
  if nft list table inet whp >/dev/null 2>&1; then
    check "trusted addresses skip the nftables block sets" bash -c 'nft list set inet whp trusted_v4 | grep -q "198.51.100.0/24" && nft list chain inet whp input | grep -q "@trusted_v4 tcp dport @tcp_open accept"'
    check "nftables still loads after a full reload" bash -c 'nft -c -f /etc/nftables.conf && systemctl reload nftables && [[ $(nft list chain inet whp input | grep -c trusted_v4) == 2 ]]'
  fi
  sleep 5  # CrowdSec reloads its configuration
  for _ in 1 2 3 4 5 6; do
    printf '%s %s whp-panel[1]: auth_failed ip=203.0.113.50 user=ci@example.test\n' "$(now_iso)" "$HOST" >>"$AUTH_LOG"
    for ip in 203.0.113.51 203.0.113.52; do
      printf '%s - - [%s] "POST /wp-login.php HTTP/1.1" 200 4321 "-" "Mozilla/5.0" rt=0.050 cache=-\n' "$ip" "$(now_clf)" >>"$SITE_LOG"
    done
  done
  check "failed panel logins get the address blocked" wait_ban 203.0.113.50 whp/panel-bf
  check "failed WordPress logins get the address blocked" wait_ban 203.0.113.51 whp/wordpress-bf
  check "an address on the site allowlist is not blocked" bash -c '! cscli decisions list --ip 203.0.113.52 -o json | jq -e "length > 0"'
  check "the customer sees the attack on their site" bash -c "agent_call security.site_activity '{\"domain\":\"site.test\"}' | tee /dev/stderr | jq -e '[.data.alerts[].address] | index(\"203.0.113.51\") != null'"
  check "allowlisting by the customer lifts the block" bash -c "agent_call security.site_release '{\"domain\":\"site.test\",\"address\":\"203.0.113.51\"}' | jq -e '.data.released > 0' && ! cscli decisions list --ip 203.0.113.51 -o json | jq -e 'length > 0'"
  # Real failed mail logins from a public address on loopback (127.0.0.1 is never counted).
  ip addr add 203.0.113.60/32 dev lo 2>/dev/null || true
  ip addr add 203.0.113.61/32 dev lo 2>/dev/null || true
  check "mail threshold lowered for the test" agent_call security.apply '{"services":{"mail":{"temp_after":3,"window_minutes":10,"perm_after":0}}}'
  # The apply restarts CrowdSec; the mail journal is only read once its
  # journalctl reader runs, so wait for that before sending bad logins.
  for _ in $(seq 60); do
    systemctl is-active --quiet crowdsec && pgrep -f 'journalctl.*postfix' >/dev/null && break
    sleep 1
  done
  sleep 3
  python3 - <<'PY' || true
import smtplib, ssl
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
for _ in range(4):
    try:
        s = smtplib.SMTP("203.0.113.60", 587, source_address=("203.0.113.60", 0), timeout=15)
        s.starttls(context=ctx); s.login("nobody@example.test", "wrong-password")
    except Exception as e:
        print("smtp:", type(e).__name__)
        if isinstance(e, TimeoutError):
            break  # already blocked: the next attempts would only time out too
PY
  python3 - <<'PY' || true
import socket, ssl
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
for _ in range(4):
    try:
        raw = socket.create_connection(("203.0.113.61", 993), timeout=15, source_address=("203.0.113.61", 0))
        tls = ctx.wrap_socket(raw)
        tls.recv(4096)
        tls.sendall(b"a1 LOGIN nobody@example.test wrong-password\r\n")
        tls.recv(4096)
        tls.sendall(b"a2 LOGOUT\r\n"); tls.close()
    except Exception as e:
        print("imap:", type(e).__name__)
        if isinstance(e, TimeoutError):
            break  # already blocked: the next attempts would only time out too
PY
  echo "     CrowdSec acquisition:"; cat /etc/crowdsec/acquis.yaml /etc/crowdsec/acquis.d/*.yaml 2>/dev/null | grep -vE '^\s*(#|$)' | sed 's/^/       /'
  check "failed SMTP logins (submission) get the address blocked" wait_ban 203.0.113.60 whp/mail-bf
  journalctl -o json --no-pager -n 400 SYSLOG_IDENTIFIER=postfix/submission/smtpd 2>/dev/null \
    | jq -r 'select(.MESSAGE|tostring|test("SASL")) | "     smtpd unit: \(._SYSTEMD_UNIT)"' | sort -u || true
  check "failed IMAP logins get the address blocked" wait_ban 203.0.113.61 whp/mail-bf
  cscli decisions delete --ip 203.0.113.50 >/dev/null 2>&1 || true
  check "panel settings applied again" runuser -u whp -- php /opt/webhosting-panel/panel/artisan whp:security:apply

  echo "== website logs"
  printf '<?php trigger_error("smoke-php-warning", E_USER_WARNING); echo "ok";\n' >/var/www/vhosts/site.test/public/warn.php
  chown s_site_test: /var/www/vhosts/site.test/public/warn.php
  curl -s -o /dev/null -H 'Host: site.test' 'http://127.0.0.1/smoke-log-viewer?x=1'
  curl -s -o /dev/null -H 'Host: site.test' http://127.0.0.1/warn.php
  sleep 6  # access log buffer flushes every 5 seconds
  check "logs: access log entry with status and path" bash -c 'agent sitelog.read "{\"domain\":\"site.test\",\"log\":\"access\",\"q\":\"smoke-log-viewer\"}" | jq -e "(.entries | length > 0) and .entries[-1].path == \"/smoke-log-viewer?x=1\" and .cursor.offset > 0"'
  check "logs: PHP warning in the PHP error log" bash -c 'agent sitelog.read "{\"domain\":\"site.test\",\"log\":\"php\",\"levels\":[\"warning\"]}" | jq -e "[.entries[] | select(.message | test(\"smoke-php-warning\"))] | length > 0"'
  check "logs: list of log files" bash -c 'agent sitelog.files "{\"domain\":\"site.test\"}" | jq -e "(.logs.access | length > 0) and (.logs.access[0].name == \"access.log\")"'
  check "logs: a symlinked log is refused" bash -c 'ln -sf /etc/shadow /var/www/vhosts/site.test/logs/access.log.9; out=$(agent sitelog.read "{\"domain\":\"site.test\",\"log\":\"access\",\"file\":\"access.log.9\"}"); rc=$?; rm -f /var/www/vhosts/site.test/logs/access.log.9; [[ $rc != 0 ]] && grep -q forbidden <<<"$out"'
  # logrotate.service runs the generator before every run; the file from the install predates site.test.
  check "logrotate: config regenerated for the current websites" whp-site-logrotate
  check "logrotate: every website has a block, run as its own user" bash -c 'whp-site-logrotate --print | grep -q "su s_site_test s_site_test" && grep -q "/var/www/vhosts/site.test/logs/access.log" /etc/logrotate.d/whp-sites && logrotate --debug /etc/logrotate.d/whp-sites'
  check "logrotate: forced rotation, Nginx and PHP keep logging" bash -c 'logrotate --force /etc/logrotate.d/whp-sites && systemctl kill --kill-whom=main --signal=USR1 nginx.service && sleep 2 && [[ -f /var/www/vhosts/site.test/logs/access.log.1 ]] && curl -s -o /dev/null -H "Host: site.test" http://127.0.0.1/after-rotate && curl -s -o /dev/null -H "Host: site.test" http://127.0.0.1/warn.php && sleep 6 && grep -q after-rotate /var/www/vhosts/site.test/logs/access.log && [[ $(stat -c %U /var/www/vhosts/site.test/logs/php-error.log) == s_site_test ]]'
  check "logrotate: systemd runs the generator first" bash -c 'systemctl cat logrotate.service | grep -q whp-site-logrotate'
fi

# Per-feature smoke tests (one file per feature keeps this file out of merge conflicts).
for f in "$(dirname "$0")"/smoke.d/*.sh; do
  # shellcheck source=/dev/null
  [[ -f $f ]] && source "$f"
done

exit $fail
