# shellcheck shell=bash
# Website mail: a Postfix SMTP service on loopback only (127.0.0.1:10587) that
# WordPress sends through (mu-plugin whp-mail, agent wp.mail_*). Called from
# module_mail, so it only exists when the mail server is installed.
#
# Why its own service: the sites' PHP talks SMTP to it, which also works where
# sendmail cannot (setgid postdrop is blocked under NoNewPrivileges: WP-CLI and
# the system WP-cron). It skips the checks meant for the internet (HELO, a sender
# domain that resolves: new sites often have no DNS yet), relays only for
# loopback, and logs as postfix/website, so the mail log can tell website mail
# apart. Rspamd signs it with the DKIM key of the From domain, as all local mail.

WEBSITE_SMTP_ADDR=127.0.0.1:10587

mail_websites() {
  local svc=$WEBSITE_SMTP_ADDR
  run postconf -M "$svc/inet=$svc inet n - y - - smtpd"
  run postconf -P \
    "$svc/inet/syslog_name=postfix/website" \
    "$svc/inet/smtpd_client_restrictions=permit_mynetworks,reject" \
    "$svc/inet/smtpd_helo_restrictions=" \
    "$svc/inet/smtpd_sender_restrictions=reject_non_fqdn_sender" \
    "$svc/inet/smtpd_relay_restrictions=permit_mynetworks,reject" \
    "$svc/inet/smtpd_recipient_restrictions=reject_non_fqdn_recipient" \
    "$svc/inet/smtpd_sasl_auth_enable=no" \
    "$svc/inet/smtpd_tls_security_level=none" \
    "$svc/inet/smtpd_tls_auth_only=no" \
    "$svc/inet/milter_macro_daemon_name=ORIGINATING"
  run postfix check
  ok "website mail: SMTP on $svc (loopback only)"
}
