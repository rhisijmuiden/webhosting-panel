# shellcheck shell=bash disable=SC2016
# Redirects & access (whp-agent webrules.apply). Sourced by tests/smoke.sh,
# which provides check/pass/bad and $fail.
echo "== redirects & access"
if [[ ! -S /run/whp-agent/agent.sock ]]; then
  echo "SKIP redirects & access (agent not running)"
else
  WR_AC="python3 $(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/agent_call.py"
  export WR_AC
  wr_get() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: rules.test' "http://127.0.0.1$1" "${@:2}"; }
  export -f wr_get
  check "add site rules.test" whp-site add rules.test --no-ssl --json
  WR_PUB=/var/www/vhosts/rules.test/public
  install -d "$WR_PUB/private" "$WR_PUB/errors"
  printf '<?php echo "php-ok";\n' >"$WR_PUB/index.php"
  printf 'secret-area\n' >"$WR_PUB/private/index.html"
  printf 'custom-not-found\n' >"$WR_PUB/errors/404.html"
  chown -R "$(stat -c %U /var/www/vhosts/rules.test)": "$WR_PUB"
  WR_HASH=$(openssl passwd -6 'Smoke-pass-1')
  WR_RULES=$(jq -cn --arg h "$WR_HASH" '{domain:"rules.test",
    redirects:[{from:"/old-page",to:"/new-page",code:301},
               {from:"/blog",to:"https://example.org/news",code:302,prefix:true}],
    protected:[{path:"/private",realm:"Smoke area",users:[{name:"smoke",hash:$h}]}],
    error_pages:{"404":"/errors/404.html"}}')
  check "apply rules" bash -c "$WR_AC webrules.apply '$WR_RULES' | jq -e '.redirects == 2 and .protected == 1 and .missing_error_pages == []'"
  check "nginx config valid with rules" nginx -t
  check "exact redirect 301" bash -c '[[ $(wr_get /old-page/) == "301 http://rules.test/new-page" ]]'
  check "prefix redirect keeps path and query" bash -c '[[ $(wr_get "/blog/2026/post?x=1") == "302 https://example.org/news/2026/post?x=1" ]]'
  check "other pages are not redirected" bash -c '[[ $(wr_get /older-page) == "200 " ]]'
  check "protected folder asks for a password" bash -c '[[ $(wr_get /private/) == "401 " ]]'
  check "protected folder opens with the password" bash -c "curl -s -u smoke:Smoke-pass-1 -H 'Host: rules.test' http://127.0.0.1/private/ | grep -q secret-area"
  check "wrong password refused" bash -c '[[ $(wr_get /private/ -u smoke:wrong) == "401 " ]]'
  check "PHP below a protected folder is protected too" bash -c '[[ $(wr_get /private/x.php) == "401 " ]]'
  check "neighbour path is not protected" bash -c '[[ $(wr_get /privatefoo) == "200 " ]]'
  check "certificate challenges stay reachable" bash -c "install -d /var/www/acme/.well-known/acme-challenge && echo tok >/var/www/acme/.well-known/acme-challenge/wr && $WR_AC webrules.apply '{\"domain\":\"rules.test\",\"protected\":[{\"path\":\"/\",\"users\":[{\"name\":\"a\",\"hash\":\"$WR_HASH\"}]}],\"access\":{\"mode\":\"deny\",\"networks\":[\"127.0.0.1\"]}}' >/dev/null && [[ \$(wr_get /) == '403 ' ]] && curl -s -H 'Host: rules.test' http://127.0.0.1/.well-known/acme-challenge/wr | grep -q tok"
  check "blocked address gets 403" bash -c '[[ $(wr_get /index.php) == "403 " ]]'
  check "custom 404 page" bash -c "$WR_AC webrules.apply '$WR_RULES' >/dev/null && curl -s -H 'Host: rules.test' http://127.0.0.1/missing.png | grep -q custom-not-found"
  check "invalid rule refused, site keeps working" bash -c "! $WR_AC webrules.apply '{\"domain\":\"rules.test\",\"redirects\":[{\"from\":\"/a;b\",\"to\":\"/c\"}]}' && [[ \$(wr_get /old-page) == '301 http://rules.test/new-page' ]]"
  if $WR_AC webrules.status | jq -e '.geoip.available' >/dev/null; then
    check "country blocking applies (localhost has no country, not blocked)" bash -c "$WR_AC webrules.apply '{\"domain\":\"rules.test\",\"access\":{\"countries_mode\":\"allow\",\"countries\":[\"NL\",\"BE\"]}}' >/dev/null && nginx -t && [[ \$(wr_get /) == '200 ' ]]"
  else
    echo "SKIP country blocking (GeoIP database not downloaded)"
  fi
  # HTTPS with a self-signed certificate: HSTS and force HTTPS.
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=rules.test -addext subjectAltName=DNS:rules.test \
    -keyout /tmp/wr.key -out /tmp/wr.crt >/dev/null 2>&1
  check "import certificate" whp-site ssl rules.test --import-cert /tmp/wr.crt --import-key /tmp/wr.key --json
  check "HTTP redirects to HTTPS by default" bash -c '[[ $(wr_get /) == "301 https://rules.test/" ]]'
  check "default HSTS header" bash -c "curl -sk -D - -o /dev/null --resolve rules.test:443:127.0.0.1 https://rules.test/ | grep -qi '^strict-transport-security: max-age=31536000'"
  check "own HSTS header and plain HTTP allowed" bash -c "$WR_AC webrules.apply '{\"domain\":\"rules.test\",\"force_https\":false,\"hsts\":{\"max_age\":63072000,\"subdomains\":true,\"preload\":true}}' | jq -e '.force_https == false' && curl -sk -D - -o /dev/null --resolve rules.test:443:127.0.0.1 https://rules.test/ | grep -qi 'max-age=63072000; includeSubDomains; preload' && [[ \$(wr_get /) == '200 ' ]]"
  check "other sites keep the default HSTS" bash -c "! grep -rq site.test /etc/nginx/whp/hsts/"
  check "HSTS off" bash -c "$WR_AC webrules.apply '{\"domain\":\"rules.test\",\"hsts\":{\"enabled\":false}}' >/dev/null && ! curl -sk -D - -o /dev/null --resolve rules.test:443:127.0.0.1 https://rules.test/ | grep -qi strict-transport"
  check "force HTTPS again" bash -c '[[ $(wr_get /) == "301 https://rules.test/" ]]'
  check "remove site cleans up rules" bash -c "whp-site remove rules.test --json >/dev/null && [[ ! -e /etc/nginx/whp/hsts/rules.test.conf && ! -e /etc/nginx/whp/auth/rules.test && ! -e /var/lib/webhosting-panel/sites/rules.test.http-allowed ]] && nginx -t"
fi
