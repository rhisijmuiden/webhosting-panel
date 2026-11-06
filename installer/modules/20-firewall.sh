# shellcheck shell=bash
# nftables: default drop on input, only the hosting ports are open.
# Our rules live in their own table so CrowdSec's tables are left alone.

module_firewall() {
  [[ $WHP_FIREWALL == yes ]] || { info "firewall disabled (--no-firewall)"; return 0; }
  apt_install nftables

  local err
  if ! err=$(nft list tables 2>&1); then
    warn "$(t fw_unavailable "$err")"
    return 0
  fi

  FW_TCP="$WHP_SSH_PORT, 80, 443, 8443"
  [[ $WHP_MAIL == yes ]] && FW_TCP+=", 25, 465, 587, 993, 4190"
  FW_UDP="443"

  install -d -m 0755 /etc/nftables.d
  backup_once /etc/nftables.conf
  render "$TPL/nftables/nftables.conf" /etc/nftables.conf 0755
  # Extra rules from the panel (firewall.add) go in /etc/nftables.d/*.nft.
  [[ -e /etc/nftables.d/00-placeholder.nft ]] || printf '# Rules added by the panel are stored in this directory.\n' >/etc/nftables.d/00-placeholder.nft

  run nft -c -f /etc/nftables.conf
  svc_enable nftables
  run systemctl restart nftables
  ok "nftables: tcp {$FW_TCP} udp {$FW_UDP}"
}
