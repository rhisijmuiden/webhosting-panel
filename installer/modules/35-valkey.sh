# shellcheck shell=bash
# Valkey (Redis compatible) on localhost with ACLs: customer PHP runs on the
# same machine, so nothing is reachable without a password.

module_valkey() {
  apt_install valkey-server

  local ram
  ram=$(ram_mb)
  VALKEY_MAXMEM=$(( ram / 8 ))
  (( VALKEY_MAXMEM < 128 )) && VALKEY_MAXMEM=128
  (( VALKEY_MAXMEM > 2048 )) && VALKEY_MAXMEM=2048

  VALKEY_DEFAULT_PASS=$(secret VALKEY_DEFAULT_PASS 40)
  VALKEY_WHP_PASS=$(secret VALKEY_WHP_PASS 40)
  VALKEY_DEFAULT_SHA=$(printf '%s' "$VALKEY_DEFAULT_PASS" | sha256sum | cut -d' ' -f1)
  VALKEY_WHP_SHA=$(printf '%s' "$VALKEY_WHP_PASS" | sha256sum | cut -d' ' -f1)

  WHP_SUM_BEFORE=$(files_sum /etc/valkey/whp.conf /etc/valkey/users.acl /etc/valkey/valkey.conf)
  render "$TPL/valkey/whp.conf" /etc/valkey/whp.conf 0640
  render "$TPL/valkey/users.acl" /etc/valkey/users.acl 0640
  chown root:valkey /etc/valkey/whp.conf /etc/valkey/users.acl
  if ! grep -q '^include /etc/valkey/whp.conf' /etc/valkey/valkey.conf; then
    backup_once /etc/valkey/valkey.conf
    printf '\n# Webhosting Panel overrides (must stay last)\ninclude /etc/valkey/whp.conf\n' >>/etc/valkey/valkey.conf
  fi
  svc_enable valkey-server
  svc_restart_if_changed valkey-server /etc/valkey/whp.conf /etc/valkey/users.acl /etc/valkey/valkey.conf
  local i
  for i in {1..10}; do
    [[ $(REDISCLI_AUTH=$VALKEY_WHP_PASS valkey-cli --user whp ping 2>/dev/null || true) == PONG ]] && break
    sleep 1
  done
  [[ $(REDISCLI_AUTH=$VALKEY_WHP_PASS valkey-cli --user whp ping 2>/dev/null || true) == PONG ]] || die "valkey does not answer"
  # Object cache per website (agent objcache.*): every site that switches it on
  # gets its own valkey-server as the site user (whp-objcache-<user>.service),
  # with its config here. The shared server above is never used by websites.
  install -d -m 0755 -o root -g root "$WHP_ETC/objcache"
  install -d -m 0750 -o root -g root "$WHP_STATE/objcache"
  ok "valkey ${VALKEY_MAXMEM} MB, ACL users: default, whp"
}
