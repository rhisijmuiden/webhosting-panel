# shellcheck shell=bash
# Shared helpers for the Webhosting Panel installer.
# Sourced by install.sh and by the helper CLIs in installer/bin.

WHP_CODE_DIR=${WHP_CODE_DIR:-/opt/webhosting-panel}
WHP_ETC=${WHP_ETC:-/etc/webhosting-panel}
WHP_STATE=${WHP_STATE:-/var/lib/webhosting-panel}
WHP_LOG_DIR=${WHP_LOG_DIR:-/var/log/webhosting-panel}
WHP_INSTALL_LOG=${WHP_INSTALL_LOG:-$WHP_LOG_DIR/install.log}
WHP_VHOSTS=${WHP_VHOSTS:-/var/www/vhosts}
WHP_LANG=${WHP_LANG:-}

# ---------------------------------------------------------------- output

if [[ -t 1 ]]; then
  C_RESET=$'\e[0m' C_BOLD=$'\e[1m' C_DIM=$'\e[2m'
  C_RED=$'\e[31m' C_GREEN=$'\e[32m' C_YELLOW=$'\e[33m' C_BLUE=$'\e[34m'
else
  C_RESET='' C_BOLD='' C_DIM='' C_RED='' C_GREEN='' C_YELLOW='' C_BLUE=''
fi

_log_file() {
  [[ -n ${WHP_INSTALL_LOG:-} && -d ${WHP_INSTALL_LOG%/*} ]] || return 0
  printf '%s %s\n' "$(date '+%F %T')" "$*" >>"$WHP_INSTALL_LOG"
}

info()  { printf '%s\n' "${C_BLUE}•${C_RESET} $*"; _log_file "INFO  $*"; }
ok()    { printf '%s\n' "${C_GREEN}✓${C_RESET} $*"; _log_file "OK    $*"; }
warn()  { printf '%s\n' "${C_YELLOW}!${C_RESET} $*" >&2; _log_file "WARN  $*"; }
die()   { printf '%s\n' "${C_RED}✗ $*${C_RESET}" >&2; _log_file "FATAL $*"; exit 1; }
step()  { printf '\n%s\n' "${C_BOLD}== $* ==${C_RESET}"; _log_file "STEP  $*"; }

# Run a command, send its output to the install log, and show the tail on error.
run() {
  _log_file "RUN   $*"
  local out rc=0
  out=$("$@" 2>&1) || rc=$?
  [[ -n $out ]] && _log_file "$out"
  if ((rc != 0)); then
    printf '%s\n' "$out" | tail -n 25 >&2
    die "$(t cmd_failed): $*"
  fi
}

# ---------------------------------------------------------------- i18n

# t <key> [printf args...]: translated message, English when a key is missing.
t() {
  local key=$1; shift
  local var="L_${key}" fallback="EN_${key}"
  local fmt=${!var:-${!fallback:-$key}}
  # shellcheck disable=SC2059
  printf "$fmt" "$@"
}

load_lang() {
  local dir=$1
  if [[ -z $WHP_LANG ]]; then
    case ${LC_ALL:-${LC_MESSAGES:-${LANG:-}}} in
      nl*) WHP_LANG='nl' ;;
      *) WHP_LANG='en' ;;
    esac
  fi
  # shellcheck source=../lang/en.sh
  source "$dir/en.sh"
  if [[ $WHP_LANG != en && -f $dir/$WHP_LANG.sh ]]; then
    # shellcheck disable=SC1090
    source "$dir/$WHP_LANG.sh"
  fi
}

# ---------------------------------------------------------------- prompts

WHP_INTERACTIVE=${WHP_INTERACTIVE:-auto}

_tty() { [[ $WHP_INTERACTIVE != no ]] && { : </dev/tty; } 2>/dev/null; }

# ask <var> <prompt> [default] [secret]
ask() {
  local __var=$1 prompt=$2 default=${3:-} secret=${4:-} reply=''
  if [[ -n ${!__var:-} ]]; then return 0; fi
  if _tty; then
    local hint=''
    [[ -n $default && -z $secret ]] && hint=" ${C_DIM}[$default]${C_RESET}"
    if [[ -n $secret ]]; then
      read -r -s -p "$prompt$hint: " reply </dev/tty; printf '\n' >/dev/tty
    else
      read -r -p "$prompt$hint: " reply </dev/tty
    fi
  fi
  printf -v "$__var" '%s' "${reply:-$default}"
}

# ask_yn <var> <prompt> <default y|n>
ask_yn() {
  local __var=$1 prompt=$2 default=$3 reply=''
  if [[ -n ${!__var:-} ]]; then return 0; fi
  if _tty; then
    read -r -p "$prompt [$( [[ $default == y ]] && echo Y/n || echo y/N )]: " reply </dev/tty
  fi
  reply=${reply:-$default}
  case ${reply,,} in y|yes|j|ja) printf -v "$__var" yes ;; *) printf -v "$__var" no ;; esac
}

# ---------------------------------------------------------------- system

is_container() { systemd-detect-virt --container --quiet 2>/dev/null; }
virt_type()    { systemd-detect-virt 2>/dev/null || echo none; }

svc_enable() { run systemctl enable --now "$@"; }
svc_restart() { run systemctl restart "$@"; }
svc_reload() {
  local s
  for s in "$@"; do
    systemctl reload "$s" >/dev/null 2>&1 || run systemctl restart "$s"
  done
}

apt_install() {
  run env DEBIAN_FRONTEND=noninteractive apt-get install -y -q \
    -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
    --no-install-recommends "$@"
}

# Print only the packages that exist in the apt cache (some PHP extensions
# differ between versions, e.g. opcache is built into PHP 8.5).
apt_available() {
  local p
  for p in "$@"; do
    if apt-cache show "$p" >/dev/null 2>&1; then printf '%s\n' "$p"; fi
  done
}

pkg_installed() { [[ $(dpkg-query -W -f='${Status}' "$1" 2>/dev/null || true) == *'install ok installed'* ]]; }

# Keep a single copy of the distribution file before we replace it.
backup_once() {
  local f=$1
  [[ -e $f && ! -e $f.whp-orig ]] && cp -a "$f" "$f.whp-orig"
  return 0
}

random_secret() {
  local s
  s=$(head -c 96 /dev/urandom | base64 -w0 | tr -dc 'A-Za-z0-9')
  printf '%s' "${s:0:${1:-32}}"
}

# render <template> <destination> [mode]
# Replaces {{NAME}} with the value of shell variable NAME. Unset variables abort.
render() {
  local src=$1 dst=$2 mode=${3:-0644} content key
  [[ -f $src ]] || die "template not found: $src"
  content=$(<"$src")
  while [[ $content =~ \{\{([A-Z0-9_]+)\}\} ]]; do
    key=${BASH_REMATCH[1]}
    [[ -v $key ]] || die "template $src uses unset variable $key"
    content=${content//"{{$key}}"/${!key}}
  done
  install -D -m "$mode" /dev/null "$dst.whp-new"
  printf '%s\n' "$content" >"$dst.whp-new"
  mv -f "$dst.whp-new" "$dst"
}

# Read KEY=value pairs from an env file without executing it.
env_get() {
  local file=$1 key=$2 line
  [[ -r $file ]] || return 1
  line=$(grep -E "^${key}=" "$file" | tail -n1) || return 1
  line=${line#*=}
  line=${line#\"}; line=${line%\"}
  printf '%s' "$line"
}

# env_set <file> <key> <value>: add or replace a line, keeping file mode.
env_set() {
  local file=$1 key=$2 value=$3 tmp
  [[ -e $file ]] || install -m 0600 /dev/null "$file"
  tmp=$(mktemp "$file.XXXX")
  grep -vE "^${key}=" "$file" >"$tmp" || true
  printf '%s=%s\n' "$key" "$value" >>"$tmp"
  chmod --reference="$file" "$tmp"
  chown --reference="$file" "$tmp"
  mv -f "$tmp" "$file"
}

# Secret store, root only. Values survive re-runs of the installer.
secret() {
  local key=$1 len=${2:-32} val
  install -d -m 0750 "$WHP_ETC"
  [[ -f $WHP_ETC/secrets.env ]] || install -m 0600 /dev/null "$WHP_ETC/secrets.env"
  if val=$(env_get "$WHP_ETC/secrets.env" "$key") && [[ -n $val ]]; then
    printf '%s' "$val"; return
  fi
  val=$(random_secret "$len")
  env_set "$WHP_ETC/secrets.env" "$key" "$val"
  printf '%s' "$val"
}

ram_mb() { awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo; }

# Certificate pair for a hostname: Let's Encrypt when present, else snakeoil.
cert_paths() {
  local host=$1
  if [[ -r /etc/letsencrypt/live/$host/fullchain.pem ]]; then
    CERT_FILE=/etc/letsencrypt/live/$host/fullchain.pem
    KEY_FILE=/etc/letsencrypt/live/$host/privkey.pem
  else
    CERT_FILE=/etc/ssl/certs/ssl-cert-snakeoil.pem
    KEY_FILE=/etc/ssl/private/ssl-cert-snakeoil.key
  fi
}

nginx_has_http3() { [[ $(nginx -V 2>&1 || true) == *--with-http_v3_module* ]]; }

valid_domain() {
  [[ $1 =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]
}

# Disk quota support for the filesystem that holds the websites.
# Prints: ext4 | xfs | none  (project quotas, enforced by the kernel)
quota_mode() {
  local dir=${1:-$WHP_VHOSTS} fs opts
  fs=$(findmnt -n -o FSTYPE --target "$dir" 2>/dev/null || true)
  opts=$(findmnt -n -o OPTIONS --target "$dir" 2>/dev/null || true)
  case $fs in
    ext4) [[ $opts == *prjquota* ]] && command -v setquota >/dev/null && { echo ext4; return; } ;;
    xfs)  [[ $opts =~ (prjquota|pquota|pqnoenforce) ]] && command -v xfs_quota >/dev/null && { echo xfs; return; } ;;
  esac
  echo none
}
