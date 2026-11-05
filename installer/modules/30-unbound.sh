# shellcheck shell=bash
# Unbound as local validating resolver (needed for DNSBL lookups by Rspamd,
# public resolvers are rate limited by most blocklists).

module_unbound() {
  apt_install unbound dns-root-data
  # DNSSEC trust anchor (normally created on first start).
  if [[ ! -s /var/lib/unbound/root.key ]]; then
    install -d -o unbound -g unbound /var/lib/unbound
    if [[ -s /usr/share/dns/root.key ]]; then cp /usr/share/dns/root.key /var/lib/unbound/root.key; fi
    unbound-anchor -a /var/lib/unbound/root.key >>"$WHP_INSTALL_LOG" 2>&1 || true
    chown unbound:unbound /var/lib/unbound/root.key
  fi
  render "$TPL/unbound/whp.conf" /etc/unbound/unbound.conf.d/whp.conf
  run unbound-checkconf
  svc_enable unbound
  svc_restart unbound

  local i
  for i in 1 2 3 4 5; do
    if [[ -n $(dig +short +time=2 +tries=1 @127.0.0.1 debian.org A 2>/dev/null || true) ]]; then
      if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
        # Unbound takes over; this also closes LLMNR (5355) and mDNS.
        systemctl disable --now systemd-resolved >/dev/null 2>&1 || true
        systemctl mask systemd-resolved >/dev/null 2>&1 || true
      fi
      if [[ -L /etc/resolv.conf ]]; then
        cp --remove-destination "$(readlink -f /etc/resolv.conf)" /etc/resolv.conf.whp-orig 2>/dev/null || true
        rm -f /etc/resolv.conf
      fi
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
