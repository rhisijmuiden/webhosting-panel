# shellcheck shell=bash
# Mail: Postfix (outgoing through a relay host), Dovecot 2.4 (IMAP, LMTP,
# Sieve), Rspamd (spam filter, DKIM signing) with Bayes data in Valkey.
#
# Layout (contract with the mail module of the panel):
#   /etc/postfix/whp/virtual_domains     domain   OK
#   /etc/postfix/whp/virtual_mailboxes   address  domain/user/
#   /etc/postfix/whp/virtual_aliases     address  target[,target]
#   /etc/dovecot/users                   passwd-file for passdb and userdb
#   /etc/dovecot/master-users            master user whp-import (IMAP import, loopback only)
#   /var/vmail/<d>/<u>/whp-autoreply.sieve  autoresponder (agent mail.autoreply_sync)
#   /var/vmail/<domain>/<user>/Maildir   vmail:vmail (5000:5000)
#   /var/lib/rspamd/dkim/<domain>.whp.key  DKIM keys, selector "whp"
# All maps are texthash: no postmap needed, "postfix reload" applies changes.

VMAIL_ID=5000

mail_users() {
  getent group vmail >/dev/null || run groupadd --system --gid "$VMAIL_ID" vmail
  getent passwd vmail >/dev/null || run useradd --system --uid "$VMAIL_ID" --gid vmail \
    --home-dir /var/vmail --shell /usr/sbin/nologin vmail
  install -d -m 0770 -o vmail -g vmail /var/vmail
}

mail_postfix() {
  apt_install postfix postfix-pcre postfix-lmdb
  install -d -m 0755 /etc/postfix/whp
  local m
  for m in virtual_domains virtual_mailboxes virtual_aliases sender_login; do
    [[ -f /etc/postfix/whp/$m ]] || printf '# Managed by the Webhosting Panel (texthash map)\n' >"/etc/postfix/whp/$m"
  done

  cert_paths "$WHP_HOSTNAME"
  backup_once /etc/postfix/main.cf
  backup_once /etc/postfix/master.cf

  local relay=''
  if [[ -n $WHP_RELAY_HOST ]]; then
    relay="[$WHP_RELAY_HOST]:$WHP_RELAY_PORT"
  fi

  run postconf -e \
    "myhostname = $WHP_HOSTNAME" \
    "myorigin = \$myhostname" \
    "mydestination = localhost, localhost.localdomain" \
    "mynetworks = 127.0.0.0/8 [::1]/128" \
    "inet_interfaces = all" \
    "inet_protocols = all" \
    "relayhost = $relay" \
    "smtpd_banner = \$myhostname ESMTP" \
    "biff = no" \
    "append_dot_mydomain = no" \
    "recipient_delimiter = +" \
    "message_size_limit = 52428800" \
    "mailbox_size_limit = 0" \
    "disable_vrfy_command = yes" \
    "smtpd_helo_required = yes" \
    "strict_rfc821_envelopes = yes" \
    "smtputf8_enable = yes" \
    "enable_long_queue_ids = yes" \
    "virtual_mailbox_domains = texthash:/etc/postfix/whp/virtual_domains" \
    "virtual_mailbox_maps = texthash:/etc/postfix/whp/virtual_mailboxes" \
    "virtual_alias_maps = texthash:/etc/postfix/whp/virtual_aliases" \
    "virtual_transport = lmtp:unix:private/dovecot-lmtp" \
    "smtpd_sasl_type = dovecot" \
    "smtpd_sasl_path = private/auth" \
    "smtpd_sasl_auth_enable = no" \
    "smtpd_sender_login_maps = texthash:/etc/postfix/whp/sender_login" \
    "smtpd_helo_restrictions = permit_mynetworks, permit_sasl_authenticated, reject_invalid_helo_hostname, reject_non_fqdn_helo_hostname" \
    "smtpd_sender_restrictions = reject_non_fqdn_sender, reject_unknown_sender_domain" \
    "smtpd_relay_restrictions = permit_mynetworks, permit_sasl_authenticated, defer_unauth_destination" \
    "smtpd_recipient_restrictions = reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_pipelining, permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination" \
    "smtpd_data_restrictions = reject_unauth_pipelining" \
    "smtpd_tls_cert_file = $CERT_FILE" \
    "smtpd_tls_key_file = $KEY_FILE" \
    "smtpd_tls_security_level = may" \
    "smtpd_tls_auth_only = yes" \
    "smtpd_tls_mandatory_protocols = >=TLSv1.2" \
    "smtpd_tls_protocols = >=TLSv1.2" \
    "smtpd_tls_loglevel = 1" \
    "smtpd_tls_received_header = yes" \
    "smtpd_tls_session_cache_database = lmdb:\${data_directory}/smtpd_scache" \
    "smtp_tls_session_cache_database = lmdb:\${data_directory}/smtp_scache" \
    "smtp_tls_protocols = >=TLSv1.2" \
    "smtp_tls_mandatory_protocols = >=TLSv1.2" \
    "smtp_tls_loglevel = 1" \
    "milter_protocol = 6" \
    "milter_default_action = accept" \
    "smtpd_milters = inet:127.0.0.1:11332" \
    "non_smtpd_milters = inet:127.0.0.1:11332"

  if [[ -n $relay ]]; then
    # Encrypt and verify the connection to the relay.
    run postconf -e "smtp_tls_security_level = secure" "smtp_tls_CApath = /etc/ssl/certs" \
      "smtp_tls_wrappermode = $([[ $WHP_RELAY_PORT == 465 ]] && echo yes || echo no)"
    if [[ -n $WHP_RELAY_USER ]]; then
      install -m 0600 /dev/null /etc/postfix/sasl_passwd
      printf '%s %s:%s\n' "$relay" "$WHP_RELAY_USER" "$WHP_RELAY_PASS" >/etc/postfix/sasl_passwd
      run postconf -e "smtp_sasl_auth_enable = yes" \
        "smtp_sasl_password_maps = texthash:/etc/postfix/sasl_passwd" \
        "smtp_sasl_security_options = noanonymous" \
        "smtp_sasl_tls_security_options = noanonymous"
    else
      run postconf -e "smtp_sasl_auth_enable = no"
    fi
  else
    run postconf -e "smtp_tls_security_level = may" "smtp_sasl_auth_enable = no"
    warn "no relay host: outgoing mail is delivered directly (port 25 must be open outbound)"
  fi

  # Submission (587, STARTTLS) and submissions (465, implicit TLS) for mail clients.
  local svc
  for svc in submission submissions; do
    run postconf -M "$svc/inet=$svc inet n - y - - smtpd"
    run postconf -P \
      "$svc/inet/syslog_name=postfix/$svc" \
      "$svc/inet/smtpd_sasl_auth_enable=yes" \
      "$svc/inet/smtpd_tls_auth_only=yes" \
      "$svc/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject" \
      "$svc/inet/smtpd_sender_restrictions=reject_authenticated_sender_login_mismatch" \
      "$svc/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" \
      "$svc/inet/smtpd_recipient_restrictions=" \
      "$svc/inet/milter_macro_daemon_name=ORIGINATING"
  done
  run postconf -P "submission/inet/smtpd_tls_security_level=encrypt" \
    "submissions/inet/smtpd_tls_wrappermode=yes"

  run postfix check
  svc_enable postfix
  svc_restart postfix
  ok "postfix $(postconf -h mail_version), relay: ${relay:-none}"
}

mail_dovecot() {
  apt_install dovecot-core dovecot-imapd dovecot-lmtpd dovecot-sieve dovecot-managesieved
  cert_paths "$WHP_HOSTNAME"
  DOVECOT_CERT=$CERT_FILE
  DOVECOT_KEY=$KEY_FILE
  # Dovecot reads the key as root before dropping privileges; nothing to chgrp.
  backup_once /etc/dovecot/dovecot.conf
  render "$TPL/dovecot/dovecot.conf" /etc/dovecot/dovecot.conf
  [[ -f /etc/dovecot/users ]] || install -m 0640 -o root -g dovecot /dev/null /etc/dovecot/users
  mail_import_login

  # Spam routing script: shipped once, afterwards owned by the panel agent.
  local sieve_dir=/var/lib/webhosting-panel/mail/sieve src=$REPO_DIR/mail/antispam/before.sieve
  [[ -f $src ]] || src=$TPL/dovecot/before.sieve
  # vmail (LMTP) must reach the script: traverse-only on the parents.
  chmod o+x "$WHP_STATE"
  install -d -m 0751 "$WHP_STATE/mail"
  chmod o+x "$WHP_STATE/mail"
  install -d -m 0755 "$sieve_dir"
  [[ -f $sieve_dir/before.sieve ]] || install -m 0644 "$src" "$sieve_dir/before.sieve"
  run doveconf -n
  run sievec "$sieve_dir/before.sieve"
  svc_enable dovecot
  svc_restart dovecot
  ok "dovecot $(dovecot --version | cut -d' ' -f1)"
}

# IMAP import from other providers (agent mail.imap_import_*): imapsync, and a
# Dovecot master user it logs in with on loopback, so customers' own passwords
# are never needed. The password lives in secrets.env (IMAP_IMPORT_MASTER_PASS).
# imapsync is not in Debian 13: the upstream script (pinned version, checked
# sha256) with its Perl modules from Debian. A failure here never stops the
# installer; the panel then shows mail import as not available.
IMAPSYNC_VERSION=2.314
IMAPSYNC_SHA256=89faed96f7c389723ddd7e78a00421b90d5b85bf79407fcf1b11209f3d4d7416
IMAPSYNC_PERL=(libmail-imapclient-perl libio-socket-ssl-perl libio-tee-perl libterm-readkey-perl
  libunicode-string-perl libreadonly-perl libsys-meminfo-perl libregexp-common-perl libfile-tail-perl
  libencode-imaputf7-perl libfile-copy-recursive-perl libdigest-hmac-perl libauthen-ntlm-perl
  libdata-uniqid-perl libjson-perl libjson-webtoken-perl libcrypt-openssl-rsa-perl libhtml-parser-perl
  libwww-perl libcgi-pm-perl)

mail_imapsync() {
  local bin=/usr/local/bin/imapsync pkg have=() missing=() tmp
  for pkg in "${IMAPSYNC_PERL[@]}"; do
    if apt-cache show "$pkg" >/dev/null 2>&1; then have+=("$pkg"); else missing+=("$pkg"); fi
  done
  ((${#missing[@]})) && warn "imapsync: Perl packages not in apt: ${missing[*]}"
  apt_install "${have[@]}"
  if [[ ! -x $bin ]] || ! grep -q "imapsync,v $IMAPSYNC_VERSION " "$bin"; then
    tmp=$(mktemp)
    if curl -fsSL --proto '=https' --tlsv1.2 --retry 3 -o "$tmp" \
        "https://raw.githubusercontent.com/imapsync/imapsync/imapsync-$IMAPSYNC_VERSION/imapsync" \
      && [[ $(sha256sum "$tmp" | cut -d' ' -f1) == "$IMAPSYNC_SHA256" ]]; then
      install -m 0755 "$tmp" "$bin"
    else
      warn "imapsync $IMAPSYNC_VERSION download failed or checksum mismatch: mail import stays off"
    fi
    rm -f "$tmp"
  fi
  if [[ -x $bin ]] && perl -c "$bin" >/dev/null 2>&1; then
    ok "imapsync $IMAPSYNC_VERSION"
  elif [[ -x $bin ]]; then
    warn "imapsync is installed but Perl modules are missing ($(perl -c "$bin" 2>&1 | head -n1)): mail import stays off"
    chmod 0644 "$bin"
  fi
}

mail_import_login() {
  mail_imapsync
  local pw hash
  pw=$(secret IMAP_IMPORT_MASTER_PASS 40)
  if [[ ! -s /etc/dovecot/master-users ]]; then
    hash=$(printf '%s' "$pw" | openssl passwd -6 -stdin)
    install -m 0640 -o root -g dovecot /dev/null /etc/dovecot/master-users
    printf 'whp-import:{SHA512-CRYPT}%s::::::allow_nets=127.0.0.1/32,::1/128\n' "$hash" >/etc/dovecot/master-users
  fi
}

mail_rspamd() {
  apt_install rspamd
  install -d -m 0750 -o _rspamd -g _rspamd /var/lib/rspamd/dkim
  RSPAMD_CONTROLLER_HASH=$(rspamadm pw -p "$(secret RSPAMD_CONTROLLER_PASS 32)" | tail -n1)
  VALKEY_DEFAULT_PASS=$(secret VALKEY_DEFAULT_PASS 40)
  local f
  for f in "$TPL"/rspamd/*; do
    render "$f" "/etc/rspamd/local.d/${f##*/}" 0640
    chgrp _rspamd "/etc/rspamd/local.d/${f##*/}"
  done
  run rspamadm configtest
  svc_enable rspamd
  svc_restart rspamd
  ok "rspamd $(rspamd --version 2>/dev/null | grep -oE '[0-9]+\.[0-9.]+' | sed -n 1p || true)"
}

module_mail() {
  mail_users
  mail_rspamd
  mail_postfix   # creates the postfix user and spool that Dovecot's sockets live in
  mail_dovecot
  svc_restart postfix
}
