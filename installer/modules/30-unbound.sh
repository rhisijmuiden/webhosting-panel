# shellcheck shell=bash
# Unbound as local validating resolver (needed for DNSBL lookups by Rspamd,
# public resolvers are rate limited by most blocklists).

module_unbound() {
  apt_install unbound
  render "$TPL/unbound/whp.conf" /etc/unbound/unbound.conf.d/whp.conf
  run unbound-checkconf
  svc_enable unbound
  svc_restart unbound

  local i
  for i in 1 2 3 4 5; do
    if dig +short +time=2 +tries=1 @127.0.0.1 debian.org A | grep -q .; then
      if ! grep -qE '^nameserver 127\.0\.0\.1$' /etc/resolv.conf 2>/dev/null; then
        backup_once /etc/resolv.conf
        # Write in place: in containers resolv.conf is often a bind mount.
        printf '# Managed by the Webhosting Panel installer (Unbound)\nnameserver 127.0.0.1\nnameserver ::1\noptions edns0 trust-ad\n' >/etc/resolv.conf
      fi
      ok "unbound answers on 127.0.0.1"
      return 0
    fi
    sleep 2
  done
  warn "$(t resolver_fallback)"
}
