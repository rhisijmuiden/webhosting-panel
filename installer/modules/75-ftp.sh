# shellcheck shell=bash
# Extra FTP/SFTP accounts per website (panel: FTP accounts). ProFTPD with
# virtual users only: whp-agent (ftp.sync) writes /etc/proftpd/whp/ftpd.passwd
# from the panel, every account runs as its website's Linux user and is locked
# in one folder of that website, optionally read-only.
#   FTPS on 21 (explicit TLS, plain FTP is refused), passive 49152-49407
#   SFTP on 2222 (OpenSSH keeps 22 for the main account of every website)
# Idempotent: runs on every panel update (install.sh --upgrade).

WHP_FTP_PORT=${WHP_FTP_PORT:-21}
WHP_SFTP_PORT=${WHP_SFTP_PORT:-2222}
FTP_PASV_MIN=49152
FTP_PASV_MAX=49407

module_ftp() {
  local prev
  # A port chosen earlier (installer.env) wins over the defaults.
  prev=$(env_get "$WHP_ETC/installer.env" WHP_FTP_PORT 2>/dev/null) && [[ -n $prev ]] && WHP_FTP_PORT=$prev
  prev=$(env_get "$WHP_ETC/installer.env" WHP_SFTP_PORT 2>/dev/null) && [[ -n $prev ]] && WHP_SFTP_PORT=$prev
  if [[ $WHP_SFTP_PORT == "${WHP_SSH_PORT:-22}" ]]; then
    warn "SFTP port $WHP_SFTP_PORT is the SSH port; extra SFTP accounts use 2222"
    WHP_SFTP_PORT=2222
  fi
  env_set "$WHP_ETC/installer.env" WHP_FTP_PORT "$WHP_FTP_PORT"
  env_set "$WHP_ETC/installer.env" WHP_SFTP_PORT "$WHP_SFTP_PORT"
  env_set "$WHP_ETC/installer.env" WHP_FTP_PASV "$FTP_PASV_MIN-$FTP_PASV_MAX"

  apt_install proftpd-core proftpd-mod-crypto openssh-client ssl-cert
  # Members of this group are the read-only accounts (ftpd.group). A real system
  # group, so its id never belongs to anything else on the server.
  getent group whp-ftp-ro >/dev/null || run groupadd --system whp-ftp-ro
  install -d -m 0750 /etc/proftpd/whp
  install -d -m 0750 -o root -g adm /var/log/proftpd
  local f
  for f in ftpd.passwd ftpd.group; do
    [[ -e /etc/proftpd/whp/$f ]] || install -m 0600 /dev/null "/etc/proftpd/whp/$f"
  done
  # Own SFTP host keys in PEM format: every mod_sftp build reads those
  # (the OpenSSH-format keys of sshd need extra libraries in some builds).
  [[ -s /etc/proftpd/whp/ssh_host_rsa_key ]] || run ssh-keygen -q -m PEM -t rsa -b 3072 -N '' -C "$WHP_HOSTNAME" -f /etc/proftpd/whp/ssh_host_rsa_key
  [[ -s /etc/proftpd/whp/ssh_host_ecdsa_key ]] || run ssh-keygen -q -m PEM -t ecdsa -b 256 -N '' -C "$WHP_HOSTNAME" -f /etc/proftpd/whp/ssh_host_ecdsa_key
  chmod 0600 /etc/proftpd/whp/ssh_host_*_key
  [[ -s /etc/proftpd/whp/ftpd.group ]] \
    || printf 'whp-ftp-ro:x:%s:\n' "$(getent group whp-ftp-ro | cut -d: -f3)" >/etc/proftpd/whp/ftpd.group

  cert_paths "$WHP_HOSTNAME"
  [[ -r $CERT_FILE ]] || run make-ssl-cert generate-default-snakeoil
  FTP_PORT=$WHP_FTP_PORT
  SFTP_PORT=$WHP_SFTP_PORT
  WHP_SUM_BEFORE=$(files_sum /etc/proftpd/proftpd.conf /etc/proftpd/whp/common.conf)
  backup_once /etc/proftpd/proftpd.conf
  render "$TPL/proftpd/proftpd.conf" /etc/proftpd/proftpd.conf
  render "$TPL/proftpd/common.conf" /etc/proftpd/whp/common.conf
  local test_out
  if ! test_out=$(proftpd -t 2>&1); then
    printf '%s\n' "$test_out" >>"$WHP_INSTALL_LOG"
    warn "proftpd configuration test failed: $(grep -iE 'fatal|error|unable' <<<"$test_out" | tail -n 3 | tr '\n' ' ')"
    return 0
  fi
  svc_enable proftpd
  svc_restart_if_changed proftpd /etc/proftpd/proftpd.conf /etc/proftpd/whp/common.conf

  if [[ $WHP_FIREWALL == yes ]] && [[ -d /etc/nftables.d ]]; then
    local want
    want=$(printf '# Managed by the Webhosting Panel installer (75-ftp.sh): FTPS, passive data ports and SFTP.\nadd element inet whp tcp_open { %s, %s, %s-%s }\n' \
      "$WHP_FTP_PORT" "$WHP_SFTP_PORT" "$FTP_PASV_MIN" "$FTP_PASV_MAX")
    if [[ ! -f /etc/nftables.d/whp-ftp.nft || $(</etc/nftables.d/whp-ftp.nft) != "$want" ]]; then
      printf '%s\n' "$want" >/etc/nftables.d/whp-ftp.nft
      if nft -c -f /etc/nftables.conf >>"$WHP_INSTALL_LOG" 2>&1; then
        run systemctl reload-or-restart nftables
      else
        rm -f /etc/nftables.d/whp-ftp.nft
        warn "nftables refused the FTP ports"
      fi
    fi
  fi

  if [[ $WHP_CROWDSEC == yes ]] && command -v cscli >/dev/null; then
    WHP_SUM_BEFORE=$(files_sum /etc/crowdsec/acquis.d/whp-ftp.yaml)
    render "$TPL/crowdsec/whp-ftp.yaml" /etc/crowdsec/acquis.d/whp-ftp.yaml
    cscli collections install crowdsecurity/proftpd >>"$WHP_INSTALL_LOG" 2>&1 || warn "crowdsec collection crowdsecurity/proftpd not installed"
    svc_restart_if_changed crowdsec /etc/crowdsec/acquis.d/whp-ftp.yaml
  fi
  ok "proftpd: FTPS $WHP_FTP_PORT (passive $FTP_PASV_MIN-$FTP_PASV_MAX), SFTP $WHP_SFTP_PORT"
}
