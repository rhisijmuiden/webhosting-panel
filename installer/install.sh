#!/usr/bin/env bash
# Webhosting Panel - server installer for Debian 13 (Trixie), bare metal or VM.
#
#   installer/install.sh [options]
#   installer/install.sh --help
#
# Re-running is safe: managed files are rewritten, secrets and data are kept.
set -Eeuo pipefail
umask 022

INSTALLER_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_DIR=$(cd "$INSTALLER_DIR/.." && pwd)
TPL=$INSTALLER_DIR/templates

# shellcheck source=lib/common.sh
source "$INSTALLER_DIR/lib/common.sh"

SUPPORTED_PHP=(8.3 8.4 8.5)
PANEL_PHP=8.4
WHP_REPO_URL_DEFAULT=https://github.com/rhisijmuiden/webhosting-panel.git

usage() {
  cat <<'EOF'
Usage: install.sh [options]

  --hostname FQDN          Server hostname (also used for the panel and mail)
  --email ADDRESS          Administrator e-mail (Let's Encrypt, alerts)
  --relay-host HOST        SMTP relay for outgoing mail (empty: direct delivery)
  --relay-port PORT        Relay port, 587 (STARTTLS, default) or 465 (TLS)
  --relay-user USER        Relay username
  --relay-pass PASS        Relay password (or set WHP_RELAY_PASS)
  --php LIST               PHP versions, comma separated (default 8.3,8.4,8.5)
  --timezone TZ            Time zone (default Europe/Amsterdam)
  --channel NAME           production (branch main) or test (branch test)
  --ssh-port PORT          SSH port to allow in the firewall (default: detected)
  --[no-]ssh-harden        Key-only SSH login when keys exist (default on)
  --no-firewall            Do not configure nftables
  --no-crowdsec            Do not install CrowdSec
  --no-mail                Do not install Postfix, Dovecot and Rspamd
  --no-letsencrypt         Do not request certificates during install
  --quota-device DEV       Format this EMPTY disk as XFS with project quotas for /var/www
  --require-quota          Stop when website disk quotas cannot be enforced
  --[no-]clamav            ClamAV antivirus (default: on with 2.5 GB RAM or more)
  --crowdsec-enroll KEY    Enroll this engine in the CrowdSec console
  --lang en|nl             Language of the installer messages
  -y, --yes                Non-interactive: use options and defaults, no questions
  --upgrade                Bring an installed server up to date with this code: saved
                           answers, no questions, no full-upgrade, reload instead of
                           restart where possible. The panel updater runs this.
  --force                  Continue on failed checks (not recommended)
  -h, --help               This help
EOF
}

# ---------------------------------------------------------------- options

WHP_HOSTNAME=${WHP_HOSTNAME:-}
WHP_EMAIL=${WHP_EMAIL:-}
WHP_RELAY_HOST=${WHP_RELAY_HOST-__ask__}
WHP_RELAY_PORT=${WHP_RELAY_PORT:-}
WHP_RELAY_USER=${WHP_RELAY_USER-__ask__}
WHP_RELAY_PASS=${WHP_RELAY_PASS:-}
WHP_PHP=${WHP_PHP:-}
WHP_TZ=${WHP_TZ:-}
WHP_CHANNEL=${WHP_CHANNEL:-}
WHP_SSH_PORT=${WHP_SSH_PORT:-}
WHP_SSH_HARDEN=${WHP_SSH_HARDEN:-}
WHP_FIREWALL=${WHP_FIREWALL:-yes}
WHP_CROWDSEC=${WHP_CROWDSEC:-yes}
WHP_MAIL=${WHP_MAIL:-yes}
WHP_LETSENCRYPT=${WHP_LETSENCRYPT:-yes}
WHP_CLAMAV=${WHP_CLAMAV:-auto}
WHP_QUOTA_DEVICE=${WHP_QUOTA_DEVICE:-}
WHP_REQUIRE_QUOTA=${WHP_REQUIRE_QUOTA:-no}
WHP_CROWDSEC_ENROLL=${WHP_CROWDSEC_ENROLL:-}
WHP_REPO_URL=${WHP_REPO_URL:-$WHP_REPO_URL_DEFAULT}
FORCE=no
CONFIRM=''

while (($#)); do
  case $1 in
    --hostname) WHP_HOSTNAME=$2; shift ;;
    --email) WHP_EMAIL=$2; shift ;;
    --relay-host) WHP_RELAY_HOST=$2; shift ;;
    --relay-port) WHP_RELAY_PORT=$2; shift ;;
    --relay-user) WHP_RELAY_USER=$2; shift ;;
    --relay-pass) WHP_RELAY_PASS=$2; shift ;;
    --php) WHP_PHP=$2; shift ;;
    --timezone) WHP_TZ=$2; shift ;;
    --channel) WHP_CHANNEL=$2; shift ;;
    --ssh-port) WHP_SSH_PORT=$2; shift ;;
    --ssh-harden) WHP_SSH_HARDEN=yes ;;
    --no-ssh-harden) WHP_SSH_HARDEN=no ;;
    --no-firewall) WHP_FIREWALL=no ;;
    --no-crowdsec) WHP_CROWDSEC=no ;;
    --no-mail) WHP_MAIL=no ;;
    --no-letsencrypt) WHP_LETSENCRYPT=no ;;
    --clamav) WHP_CLAMAV=yes ;;
    --quota-device) WHP_QUOTA_DEVICE=$2; shift ;;
    --require-quota) WHP_REQUIRE_QUOTA=yes ;;
    --no-clamav) WHP_CLAMAV=no ;;
    --crowdsec-enroll) WHP_CROWDSEC_ENROLL=$2; shift ;;
    --lang) WHP_LANG=$2; shift ;;
    -y|--yes) WHP_INTERACTIVE=no; CONFIRM=yes ;;
    --upgrade) WHP_UPGRADE=yes; WHP_INTERACTIVE=no; CONFIRM=yes ;;
    --force) FORCE=yes ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

load_lang "$INSTALLER_DIR/lang"
install -d -m 0750 "$WHP_LOG_DIR"
trap 'die "line $LINENO: $BASH_COMMAND"' ERR

# On --upgrade every saved choice wins over the defaults (also --no-mail and the like).
if [[ $WHP_UPGRADE == yes ]]; then
  [[ -r $WHP_ETC/installer.env ]] || die "--upgrade: $WHP_ETC/installer.env not found (server not installed?)"
  for k in FIREWALL CROWDSEC MAIL LETSENCRYPT CLAMAV QUOTA_DEVICE REQUIRE_QUOTA; do
    v="WHP_$k"
    if prev=$(env_get "$WHP_ETC/installer.env" "$v"); then printf -v "$v" '%s' "$prev"; fi
  done
fi

# Previous answers are reused on a re-run.
if [[ -r $WHP_ETC/installer.env ]]; then
  for k in HOSTNAME EMAIL RELAY_HOST RELAY_PORT RELAY_USER PHP TZ SSH_PORT SSH_HARDEN; do
    v="WHP_$k"
    if [[ -z ${!v:-} || ${!v} == __ask__ ]] && prev=$(env_get "$WHP_ETC/installer.env" "$v"); then
      printf -v "$v" '%s' "$prev"
    fi
  done
fi
# The release channel belongs to the panel (Info > Switch channel writes it to
# panel.env). An upgrade must keep that choice, or every update from the panel
# would silently put the server back on production. Order: --channel, panel.env,
# installer.env, production.
if [[ -z $WHP_CHANNEL ]] && prev=$(env_get "$WHP_ETC/panel.env" WHP_CHANNEL) && [[ -n $prev ]]; then
  WHP_CHANNEL=$prev
fi
if [[ -z $WHP_CHANNEL ]] && prev=$(env_get "$WHP_ETC/installer.env" WHP_CHANNEL) && [[ -n $prev ]]; then
  WHP_CHANNEL=$prev
fi
WHP_CHANNEL=${WHP_CHANNEL:-production}
if [[ -z $WHP_RELAY_PASS ]] && prev=$(env_get "$WHP_ETC/secrets.env" RELAY_PASS 2>/dev/null); then
  WHP_RELAY_PASS=$prev
fi

printf '%s\n' "${C_BOLD}$(t welcome)${C_RESET}"

# ---------------------------------------------------------------- questions

gather() {
  local fqdn
  fqdn=$(hostname -f 2>/dev/null || hostname)
  ask WHP_HOSTNAME "$(t q_hostname)" "$fqdn"
  WHP_HOSTNAME=${WHP_HOSTNAME,,}
  valid_domain "$WHP_HOSTNAME" || die "$(t bad_hostname "$WHP_HOSTNAME")"
  ask WHP_EMAIL "$(t q_email)" "postmaster@${WHP_HOSTNAME#*.}"

  if [[ $WHP_MAIL == yes ]]; then
    [[ $WHP_RELAY_HOST == __ask__ ]] && WHP_RELAY_HOST=''  && ask WHP_RELAY_HOST "$(t q_relay)" ''
    if [[ -n $WHP_RELAY_HOST ]]; then
      ask WHP_RELAY_PORT "$(t q_relay_port)" 587
      [[ $WHP_RELAY_USER == __ask__ ]] && WHP_RELAY_USER='' && ask WHP_RELAY_USER "$(t q_relay_user)" ''
      [[ -n $WHP_RELAY_USER ]] && ask WHP_RELAY_PASS "$(t q_relay_pass)" '' secret
    fi
  fi
  [[ $WHP_RELAY_HOST == __ask__ ]] && WHP_RELAY_HOST=''
  [[ $WHP_RELAY_USER == __ask__ ]] && WHP_RELAY_USER=''
  WHP_RELAY_PORT=${WHP_RELAY_PORT:-587}

  ask WHP_PHP "$(t q_php)" "$(IFS=,; echo "${SUPPORTED_PHP[*]}")"
  local v list=()
  IFS=', ' read -r -a list <<<"$WHP_PHP"
  PHP_VERSIONS=()
  for v in "${list[@]}" "$PANEL_PHP"; do
    [[ -z $v ]] && continue
    [[ " ${SUPPORTED_PHP[*]} " == *" $v "* ]] || die "$(t bad_php "$v" "${SUPPORTED_PHP[*]}")"
    [[ " ${PHP_VERSIONS[*]-} " == *" $v "* ]] || PHP_VERSIONS+=("$v")
  done
  mapfile -t PHP_VERSIONS < <(printf '%s\n' "${PHP_VERSIONS[@]}" | sort -V)
  WHP_PHP=$(IFS=,; echo "${PHP_VERSIONS[*]}")

  ask WHP_TZ "$(t q_tz)" Europe/Amsterdam
  [[ -e /usr/share/zoneinfo/$WHP_TZ ]] || WHP_TZ=Europe/Amsterdam
  ask_yn WHP_SSH_HARDEN "$(t q_ssh_harden)" y

  case $WHP_CHANNEL in production|main) WHP_CHANNEL=production ;; test) ;; *) die "channel: production|test" ;; esac

  if [[ -z $CONFIRM ]]; then
    printf '\n  hostname   %s\n  e-mail     %s\n  relay      %s\n  php        %s\n  channel    %s\n\n' \
      "$WHP_HOSTNAME" "$WHP_EMAIL" "${WHP_RELAY_HOST:+$WHP_RELAY_HOST:$WHP_RELAY_PORT}${WHP_RELAY_HOST:--}" \
      "$WHP_PHP" "$WHP_CHANNEL"
    ask_yn CONFIRM "$(t q_confirm)" y
    [[ $CONFIRM == yes ]] || die "$(t aborted)"
  fi
}

save_answers() {
  install -d -m 0750 "$WHP_ETC"
  local f=$WHP_ETC/installer.env
  [[ -f $f ]] || install -m 0640 /dev/null "$f"
  local k
  for k in HOSTNAME EMAIL RELAY_HOST RELAY_PORT RELAY_USER PHP TZ CHANNEL SSH_PORT SSH_HARDEN LETSENCRYPT \
           FIREWALL CROWDSEC MAIL CLAMAV QUOTA_DEVICE REQUIRE_QUOTA; do
    local v="WHP_$k"
    env_set "$f" "$v" "${!v:-}"
  done
  if [[ $WHP_UPGRADE == yes ]]; then
    env_set "$f" WHP_UPGRADED_AT "$(date -u +%FT%TZ)"
  else
    env_set "$f" WHP_INSTALLED_AT "$(date -u +%FT%TZ)"
  fi
  [[ -n $WHP_RELAY_PASS ]] && env_set "$WHP_ETC/secrets.env" RELAY_PASS "$WHP_RELAY_PASS"
  return 0
}

# ---------------------------------------------------------------- run

for m in "$INSTALLER_DIR"/modules/*.sh; do
  # shellcheck disable=SC1090
  source "$m"
done

preflight
gather
save_answers

step "$(t step_base)";      module_base
step "$(t step_quota)";     module_quota
step "$(t step_ssh)";       module_ssh
step "$(t step_firewall)";  module_firewall
step "$(t step_unbound)";   module_unbound
step "$(t step_valkey)";    module_valkey
step "$(t step_mariadb)";   module_mariadb
step "$(t step_php)";       module_php
step "$(t step_nginx)";     module_nginx
step "$(t step_web_rules)"; module_web_rules
step "$(t step_runtimes)";  module_runtimes
if [[ $WHP_MAIL == yes ]]; then step "$(t step_mail)"; module_mail; fi
step "$(t step_antivirus)"; module_antivirus
if [[ $WHP_CROWDSEC == yes ]]; then step "$(t step_crowdsec)"; module_crowdsec; fi
step "$(t step_ftp)";       module_ftp
step "$(t step_panel)";     module_panel
step "$(t step_phpmyadmin)"; module_phpmyadmin
step "$(t step_sitelogs)";  module_site_logs
step "$(t step_migrations)"; module_migrations
step "$(t step_finish)";    module_finish
