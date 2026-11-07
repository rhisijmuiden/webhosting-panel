#!/usr/bin/env bash
# Dekwerk - server installer for Debian 13 (Trixie), bare metal or VM.
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
  --channel NAME           production (branch main) or test (branch test); asked
                           when missing on an interactive install. The installer
                           switches its checkout to that branch and its latest code.
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
ORIG_ARGS=("$@")

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
# Ctrl-C: stop the command behind the spinner too (background jobs ignore SIGINT).
trap '[[ -n $_RUN_PID ]] && kill "$_RUN_PID" 2>/dev/null; printf "\n"; die "$(t interrupted)"' INT TERM
WHP_PROGRESS=yes

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
# Nothing chosen yet: ask on an interactive fresh install, production otherwise.
CHANNEL_ASK=no
if [[ -z $WHP_CHANNEL ]]; then
  if [[ -z $CONFIRM && $WHP_UPGRADE != yes ]] && _tty; then CHANNEL_ASK=yes; else WHP_CHANNEL=production; fi
fi
if [[ -z $WHP_RELAY_PASS ]] && prev=$(env_get "$WHP_ETC/secrets.env" RELAY_PASS 2>/dev/null); then
  WHP_RELAY_PASS=$prev
fi

printf '\n%s\n' "${C_BOLD}$(t welcome)${C_RESET}"
if [[ -r $REPO_DIR/VERSION ]]; then
  printf '%s\n' "${C_DIM}$(t welcome_version "$(<"$REPO_DIR/VERSION")" \
    "$(git -C "$REPO_DIR" symbolic-ref --short -q HEAD 2>/dev/null || echo -)" \
    "$(git -C "$REPO_DIR" rev-parse --short HEAD 2>/dev/null || echo -)")${C_RESET}"
fi

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
  [[ $CHANNEL_ASK == yes ]] && ask_channel

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

# ask_channel: production or test, with the branch of this checkout as default.
ask_channel() {
  local reply='' def=1
  [[ $(git -C "$REPO_DIR" symbolic-ref --short -q HEAD 2>/dev/null || true) == test ]] && def=2
  printf '\n%s\n  1) %s\n  2) %s\n' "$(t q_channel)" "$(t channel_production)" "$(t channel_test)"
  read -r -p "$(t q_choice) [$def]: " reply </dev/tty || true
  case ${reply:-$def} in 2|t|test) WHP_CHANNEL='test' ;; *) WHP_CHANNEL=production ;; esac
}

# channel_sync: a fresh install from the server's own checkout (/opt/webhosting-panel)
# continues with the newest code of the chosen channel: switch to its branch, pull,
# and start again from the new code when anything changed. The answers are saved
# first, so the new run asks nothing. CI and development checkouts elsewhere, an
# --upgrade (the panel updater picks the code) and local changes are left alone.
channel_sync() {
  [[ $WHP_UPGRADE != yes && $REPO_DIR == "$WHP_CODE_DIR" && -d $REPO_DIR/.git && -z ${WHP_CHANNEL_SYNCED:-} ]] || return 0
  local want=main cur before after g=(git -C "$REPO_DIR")
  [[ $WHP_CHANNEL == test ]] && want='test'
  cur=$("${g[@]}" symbolic-ref --short -q HEAD 2>/dev/null) || return 0
  [[ $cur == main || $cur == test ]] || return 0
  if [[ -n $("${g[@]}" status --porcelain --untracked-files=no 2>/dev/null) ]]; then
    warn "$(t channel_dirty "$REPO_DIR")"; return 0
  fi
  before=$("${g[@]}" rev-parse HEAD)
  info "$(t channel_fetch "$WHP_CHANNEL" "$want")"
  if ! "${g[@]}" fetch -q origin "$want" >>"$WHP_INSTALL_LOG" 2>&1; then
    warn "$(t channel_fetch_failed "$want")"; return 0
  fi
  if [[ $cur != "$want" ]]; then
    "${g[@]}" checkout -q "$want" >>"$WHP_INSTALL_LOG" 2>&1 \
      || "${g[@]}" checkout -q -b "$want" "origin/$want" >>"$WHP_INSTALL_LOG" 2>&1 \
      || { warn "$(t channel_fetch_failed "$want")"; return 0; }
  fi
  "${g[@]}" merge -q --ff-only "origin/$want" >>"$WHP_INSTALL_LOG" 2>&1 || warn "$(t channel_fetch_failed "$want")"
  after=$("${g[@]}" rev-parse HEAD)
  [[ $after == "$before" ]] && return 0
  info "$(t channel_restart "$(cat "$REPO_DIR/VERSION" 2>/dev/null || echo dev)" "$("${g[@]}" rev-parse --short HEAD)")"
  export WHP_CHANNEL_SYNCED=1
  exec bash "$REPO_DIR/installer/install.sh" "${ORIG_ARGS[@]}" --channel "$WHP_CHANNEL" --yes
}

# ---------------------------------------------------------------- run

for m in "$INSTALLER_DIR"/modules/*.sh; do
  # shellcheck disable=SC1090
  source "$m"
done

preflight
gather
save_answers
channel_sync

# The plan: every step that runs on this server, numbered, shown up front.
PLAN=()
plan() { PLAN+=("$1:$2"); }
plan step_base       module_base
plan step_quota      module_quota
plan step_ssh        module_ssh
plan step_firewall   module_firewall
plan step_unbound    module_unbound
plan step_valkey     module_valkey
plan step_mariadb    module_mariadb
plan step_php        module_php
plan step_nginx      module_nginx
plan step_web_rules  module_web_rules
plan step_runtimes   module_runtimes
plan step_wp_visual  module_wp_visual
if [[ $WHP_MAIL == yes ]]; then plan step_mail module_mail; fi
plan step_antivirus  module_antivirus
if [[ $WHP_CROWDSEC == yes ]]; then plan step_crowdsec module_crowdsec; fi
plan step_ftp        module_ftp
plan step_panel      module_panel
plan step_phpmyadmin module_phpmyadmin
plan step_sitelogs   module_site_logs
plan step_migrations module_migrations
plan step_verify     module_verify
plan step_finish     module_finish
STEP_TOTAL=${#PLAN[@]}

printf '\n%s\n' "${C_BOLD}$(t plan_title "$STEP_TOTAL")${C_RESET}"
n=0
for entry in "${PLAN[@]}"; do
  n=$((n + 1))
  printf '  %s%*d.%s %s\n' "$C_DIM" "${#STEP_TOTAL}" "$n" "$C_RESET" "$(t "${entry%%:*}")"
done
printf '%s\n' "${C_DIM}$(t plan_log "$WHP_INSTALL_LOG")${C_RESET}"
_log_file "PLAN  $STEP_TOTAL steps: ${PLAN[*]}"

FIRST_INSTALL=no
[[ $WHP_UPGRADE != yes && ! -e $WHP_STATE/panel/.admin-created ]] && FIRST_INSTALL=yes
INSTALL_STARTED=$SECONDS
for entry in "${PLAN[@]}"; do
  step_begin "$(t "${entry%%:*}")"
  "${entry#*:}"
  step_end
done
final_report
