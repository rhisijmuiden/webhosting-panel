# shellcheck shell=bash
# ClamAV: scans mail (through Rspamd) and webroots (daily timer).
# php-malware-finder YARA rules add detection of obfuscated PHP backdoors,
# the kind that comes with bad WordPress plugins.

PMF_REPO=https://github.com/jvoisin/php-malware-finder.git

module_antivirus() {
  if [[ $WHP_CLAMAV == auto ]]; then
    if (( $(ram_mb) >= 2500 )); then WHP_CLAMAV=yes; else
      WHP_CLAMAV=no
      warn "less than 2.5 GB RAM: ClamAV skipped (clamd needs about 1.2 GB). Use --clamav to force."
    fi
  fi
  [[ $WHP_CLAMAV == yes ]] || return 0

  apt_install clamav clamav-daemon clamav-freshclam yara
  install -d -m 0755 /etc/clamav/clamd.conf.d 2>/dev/null || true
  # Let clamd read every webroot.
  usermod -a -G www-data clamav 2>/dev/null || true
  backup_once /etc/clamav/clamd.conf
  sed -i -E 's/^(MaxFileSize|StreamMaxLength) .*/\1 50M/; s/^#?TCPSocket .*//; s/^#?TCPAddr .*//' /etc/clamav/clamd.conf
  grep -q '^TCPSocket 3310' /etc/clamav/clamd.conf || printf 'TCPSocket 3310\nTCPAddr 127.0.0.1\n' >>/etc/clamav/clamd.conf

  svc_enable clamav-freshclam
  # clamd only starts once signatures exist; the first download can take a while.
  local i
  for i in {1..90}; do
    compgen -G '/var/lib/clamav/main.c[lv]d' >/dev/null && break
    sleep 2
  done
  if compgen -G '/var/lib/clamav/main.c[lv]d' >/dev/null; then
    svc_enable clamav-daemon
    svc_restart clamav-daemon
  else
    warn "ClamAV signatures not downloaded yet; clamav-daemon starts after freshclam finishes"
    systemctl enable clamav-daemon >/dev/null 2>&1 || true
  fi

  if [[ $WHP_MAIL == yes && -d /etc/rspamd/local.d ]]; then
    render "$TPL/rspamd-av/antivirus.conf" /etc/rspamd/local.d/antivirus.conf 0640
    chgrp _rspamd /etc/rspamd/local.d/antivirus.conf
    run rspamadm configtest
    svc_reload rspamd
  fi

  # YARA rules for PHP malware.
  if [[ -d /opt/php-malware-finder/.git ]]; then
    git -C /opt/php-malware-finder pull -q --ff-only >>"$WHP_INSTALL_LOG" 2>&1 || true
  else
    git clone -q --depth 1 "$PMF_REPO" /opt/php-malware-finder >>"$WHP_INSTALL_LOG" 2>&1 \
      || warn "php-malware-finder rules could not be downloaded, only ClamAV is used"
  fi

  install -d -m 0750 "$WHP_STATE/malware"
  install -m 0755 "$INSTALLER_DIR/bin/whp-malware-scan" /usr/local/sbin/whp-malware-scan
  render "$TPL/systemd/whp-malware-scan.service" /etc/systemd/system/whp-malware-scan.service
  render "$TPL/systemd/whp-malware-scan.timer" /etc/systemd/system/whp-malware-scan.timer
  run systemctl daemon-reload
  svc_enable whp-malware-scan.timer
  ok "clamav $(clamscan --version 2>/dev/null | cut -d/ -f1 | awk '{print $2}'), daily webroot scan"
}
