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
check "suspend shows the 503 page" bash -c "whp-site suspend site.test --json | jq -e .suspended >/dev/null && [[ \$(curl -s -o /tmp/s.html -w %{http_code} -H 'Host: site.test' http://127.0.0.1/x.php) == 503 ]] && grep -q 'temporarily unavailable' /tmp/s.html"
check "suspend stops PHP and locks login" bash -c "! systemctl is-active --quiet whp-site-site_test && [[ \$(chage -l s_site_test | grep -i 'account expires') != *never* ]]"
check "suspend is idempotent" bash -c "whp-site suspend site.test --json | jq -e .suspended >/dev/null"
check "unsuspend restores the site" bash -c "whp-site unsuspend site.test --json | jq -e '.suspended == false' >/dev/null && curl -s -H 'Host: site.test' http://127.0.0.1/ | grep -q php= && [[ \$(chage -l s_site_test | grep -i 'account expires') == *never* ]]"
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
  cscli decisions delete --ip 203.0.113.50 >/dev/null 2>&1 || true
  check "panel settings applied again" runuser -u whp -- php /opt/webhosting-panel/panel/artisan whp:security:apply
fi

exit $fail
