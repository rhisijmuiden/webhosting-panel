# shellcheck shell=bash
# CrowdSec: detects attacks in the logs (SSH, Nginx, Postfix, Dovecot,
# WordPress and Laravel scans) and blocks them in nftables. Also gets the
# community blocklist.

module_crowdsec() {
  apt_install crowdsec
  install -d -m 0755 /etc/crowdsec/acquis.d
  render "$TPL/crowdsec/whp.yaml" /etc/crowdsec/acquis.d/whp.yaml
  run cscli hub update
  local c
  for c in crowdsecurity/linux crowdsecurity/sshd crowdsecurity/nginx crowdsecurity/base-http-scenarios \
           crowdsecurity/http-cve crowdsecurity/wordpress crowdsecurity/postfix crowdsecurity/dovecot; do
    cscli collections install "$c" >>"$WHP_INSTALL_LOG" 2>&1 || warn "crowdsec collection $c not installed"
  done
  svc_enable crowdsec
  svc_restart crowdsec

  if [[ $WHP_FIREWALL == yes ]] && nft list tables >/dev/null 2>&1; then
    # The package registers itself with the local API during install.
    apt_install crowdsec-firewall-bouncer-nftables
    svc_enable crowdsec-firewall-bouncer
  fi
  if [[ -n $WHP_CROWDSEC_ENROLL ]]; then
    cscli console enroll -e context "$WHP_CROWDSEC_ENROLL" --name "$WHP_HOSTNAME" >>"$WHP_INSTALL_LOG" 2>&1 \
      || warn "crowdsec console enrollment failed"
  fi
  ok "crowdsec $(cscli version 2>&1 | awk -F': *' '/version/ && !f {print $2; f=1}' || true)"
}
