# shellcheck shell=bash
# Base packages, repositories, time zone, automatic security updates, kernel tuning.

add_repositories() {
  install -d -m 0755 /etc/apt/keyrings

  # PHP 8.3 and 8.5 (and the latest 8.4 builds) from Ondřej Surý.
  if [[ ! -f /usr/share/keyrings/debsuryorg-archive-keyring.gpg ]]; then
    run curl -fsSL -o /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
    run dpkg -i /tmp/debsuryorg-archive-keyring.deb
    rm -f /tmp/debsuryorg-archive-keyring.deb
  fi
  cat >/etc/apt/sources.list.d/php-sury.sources <<SRC
Types: deb
URIs: https://packages.sury.org/php/
Suites: trixie
Components: main
Signed-By: /usr/share/keyrings/debsuryorg-archive-keyring.gpg
SRC

  if [[ $WHP_MAIL == yes ]]; then
    # Rspamd upstream asks not to use distribution packages.
    run curl -fsSL -o /etc/apt/keyrings/rspamd.asc https://rspamd.com/apt-stable/gpg.key
    cat >/etc/apt/sources.list.d/rspamd.sources <<SRC
Types: deb
URIs: https://rspamd.com/apt-stable/
Suites: trixie
Components: main
Signed-By: /etc/apt/keyrings/rspamd.asc
SRC
  fi

  if [[ $WHP_CROWDSEC == yes ]]; then
    run curl -fsSL -o /etc/apt/keyrings/crowdsec.asc https://packagecloud.io/crowdsec/crowdsec/gpgkey
    cat >/etc/apt/sources.list.d/crowdsec.sources <<SRC
Types: deb
URIs: https://packagecloud.io/crowdsec/crowdsec/any/
Suites: any
Components: main
Signed-By: /etc/apt/keyrings/crowdsec.asc
SRC
  fi
}

module_base() {
  export DEBIAN_FRONTEND=noninteractive

  # Hostname (written in place: in test containers these files are bind mounts,
  # so they are rewritten in place).
  local short=${WHP_HOSTNAME%%.*}
  if [[ $(cat /etc/hostname 2>/dev/null) != "$WHP_HOSTNAME" ]]; then
    printf '%s\n' "$WHP_HOSTNAME" >/etc/hostname || true
    hostname "$WHP_HOSTNAME" 2>/dev/null || true
  fi
  if ! grep -qE "[[:space:]]$WHP_HOSTNAME([[:space:]]|$)" /etc/hosts; then
    printf '127.0.1.1\t%s %s\n' "$WHP_HOSTNAME" "$short" >>/etc/hosts || true
  fi

  # Postfix asks questions during install; answer them up front.
  printf 'postfix postfix/main_mailer_type select Internet Site\npostfix postfix/mailname string %s\n' "$WHP_HOSTNAME" \
    | debconf-set-selections
  printf '%s\n' "$WHP_HOSTNAME" >/etc/mailname

  run apt-get -o DPkg::Lock::Timeout=600 update -q
  apt_install ca-certificates curl gnupg tzdata
  add_repositories
  run apt-get -o DPkg::Lock::Timeout=600 update -q
  run env DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 -y -q \
    -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold full-upgrade

  apt_install \
    acl apt-listchanges bash-completion bind9-dnsutils certbot cron git htop jq less \
    locales logrotate nano openssl python3 rsync ssl-cert sudo unattended-upgrades \
    unzip xz-utils zstd

  ln -sf "/usr/share/zoneinfo/$WHP_TZ" /etc/localtime
  printf '%s\n' "$WHP_TZ" >/etc/timezone

  # English and Dutch locales for the panel.
  sed -i -E 's/^# *(en_US.UTF-8|nl_NL.UTF-8)/\1/' /etc/locale.gen
  run locale-gen

  # Automatic security updates, including the third party repositories.
  cat >/etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
CONF
  cat >/etc/apt/apt.conf.d/52whp-unattended-upgrades <<'CONF'
// Managed by the Webhosting Panel installer.
Unattended-Upgrade::Origins-Pattern {
  "origin=Debian,codename=${distro_codename}-security,label=Debian-Security";
  "origin=Debian,codename=${distro_codename},label=Debian";
  "origin=deb.sury.org";
  "site=rspamd.com";
  "site=packagecloud.io";
};
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Automatic-Reboot "false";
CONF

  # Journald: keep logs bounded.
  install -d /etc/systemd/journald.conf.d
  printf '[Journal]\nSystemMaxUse=1G\nMaxRetentionSec=1month\n' >/etc/systemd/journald.conf.d/whp.conf
  systemctl restart systemd-journald 2>/dev/null || true

  # Cloud images (cloud-init) rewrite /etc/hosts and the hostname on boot.
  if [[ -d /etc/cloud/cloud.cfg.d ]]; then
    printf '# Managed by the Webhosting Panel installer\npreserve_hostname: true\nmanage_etc_hosts: false\n' \
      >/etc/cloud/cloud.cfg.d/99-whp.cfg
  fi

  # Swap: without it MariaDB or ClamAV get killed under memory pressure.
  if ! is_container && [[ -z $(swapon --show --noheadings 2>/dev/null) && ! -e /swapfile ]]; then
    local swap_mb
    swap_mb=$(( $(ram_mb) / 2 ))
    (( swap_mb < 2048 )) && swap_mb=2048
    (( swap_mb > 8192 )) && swap_mb=8192
    if fallocate -l "${swap_mb}M" /swapfile 2>>"$WHP_INSTALL_LOG"; then
      chmod 0600 /swapfile
      run mkswap /swapfile
      run swapon /swapfile
      grep -q '^/swapfile ' /etc/fstab || printf '/swapfile none swap sw 0 0\n' >>/etc/fstab
      ok "swap ${swap_mb} MB"
    fi
  fi

  if ! is_container; then
    render "$TPL/sysctl/90-whp.conf" /etc/sysctl.d/90-whp.conf
    sysctl -q -e --system >/dev/null 2>&1 || true
    timedatectl set-ntp true 2>/dev/null || true
  fi

  install -d -m 0750 "$WHP_ETC"
  install -d -m 0751 "$WHP_STATE"
  install -d -m 0755 "$WHP_VHOSTS" /var/www/acme
  ok "base system ready"
}
