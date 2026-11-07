# shellcheck shell=bash disable=SC2016
# WordPress plugin sets (apps.install with plugin_set, agent/whp_agent/wp_sets.py).
# Sourced by tests/smoke.sh, which provides check/pass/bad and $fail.
echo "== WordPress plugin sets"
if [[ ! -S /run/whp-agent/agent.sock ]]; then
  echo "SKIP WordPress plugin sets (agent not running)"
else
  PS_AC="python3 $(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/agent_call.py"
  export PS_AC
  ps_job() {
    local id i st
    id=$($PS_AC "$1" "$2" | jq -r .job) || return 1
    for i in $(seq 1 300); do
      st=$($PS_AC job.status "{\"job\":\"$id\"}")
      [[ $(jq -r .status <<<"$st") != running ]] && break
      sleep 2
    done
    printf '%s\n' "$st" >/tmp/plugin-set-job.json
    jq -e '.status == "succeeded"' <<<"$st" >/dev/null || { jq . <<<"$st"; return 1; }
  }
  export -f ps_job
  PS_PARAMS=$(jq -cn '{domain:"setwp.test", app:"wordpress", path:"", title:"Plugin set", admin_user:"smoke",
    admin_email:"smoke@example.test", admin_password:"Smoke-test-pass-123", locale:"nl",
    plugin_set:{name:"Smoke set",
      plugins:[{source:"classic-editor", activate:true}, {source:"whp-plugin-that-does-not-exist", activate:true}],
      themes:[{source:"generatepress", activate:true}],
      remove_default_content:true, remove_inactive_themes:true}}')
  PS_WP="runuser -u s_setwp_test -- /usr/local/bin/wp --path=/var/www/vhosts/setwp.test/public --skip-plugins --skip-themes"
  export PS_PARAMS PS_WP
  check "add site setwp.test" whp-site add setwp.test --php 8.4 --no-ssl --json
  check "install WordPress with a plugin set" ps_job apps.install "$PS_PARAMS"
  check "report: classic-editor installed, the missing plugin failed" bash -c 'jq -e ".result.plugin_set | .failed == 1 and ([.items[] | select(.ok) | .name] | sort) == [\"classic-editor\",\"generatepress\"]" /tmp/plugin-set-job.json'
  check "classic-editor is active" bash -c '$PS_WP plugin is-active classic-editor'
  check "GeneratePress is the active theme" bash -c '[[ $($PS_WP theme list --status=active --field=name) == generatepress ]]'
  check "the bundled Twenty* themes are removed" bash -c '! $PS_WP theme list --field=name | grep -q "^twenty"'
  check "example post and page are gone" bash -c '[[ $($PS_WP post list --post_type=post,page --post_status=publish --format=count) == 0 ]]'
  check "Dutch translation of classic-editor installed" bash -c 'ls /var/www/vhosts/setwp.test/public/wp-content/languages/plugins/ | grep -q "^classic-editor-nl_NL"'
  check "plugin files belong to the site user" bash -c '[[ $(stat -c %U /var/www/vhosts/setwp.test/public/wp-content/plugins/classic-editor) == s_setwp_test ]]'
  check "WordPress with the set answers" bash -c "curl -s -H 'Host: setwp.test' http://127.0.0.1/ | grep -q generatepress"
  check "a bad source is refused before anything starts" bash -c '! $PS_AC apps.install "$(jq -c ".plugin_set.plugins[0].source = \"--exec=id\"" <<<"$PS_PARAMS")"'
  PS_ID=$(jq -r .result.id /tmp/plugin-set-job.json)
  [[ -n $PS_ID && $PS_ID != null ]] && ps_job apps.remove "{\"domain\":\"setwp.test\",\"id\":\"$PS_ID\"}" >/dev/null || true
  whp-site remove setwp.test --json >/dev/null 2>&1 || true
fi
