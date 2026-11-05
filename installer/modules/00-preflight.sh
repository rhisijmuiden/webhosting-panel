# shellcheck shell=bash
# Checks before anything is changed.

preflight() {
  step "$(t step_preflight)"
  [[ $EUID -eq 0 ]] || die "$(t need_root)"
  [[ $(cat /proc/1/comm 2>/dev/null) == systemd ]] || die "$(t need_systemd)"

  local codename='' pretty=unknown
  if [[ -r /etc/os-release ]]; then
    codename=$(. /etc/os-release; echo "${VERSION_CODENAME:-}")
    pretty=$(. /etc/os-release; echo "${PRETTY_NAME:-unknown}")
  fi
  if [[ $codename != trixie ]]; then
    if [[ $FORCE == yes ]]; then warn "$(t need_trixie "$pretty")"; else die "$(t need_trixie "$pretty")"; fi
  fi

  local arch
  arch=$(dpkg --print-architecture)
  case $arch in amd64|arm64) ;; *) die "$(t bad_arch "$arch")" ;; esac

  local ram disk
  ram=$(ram_mb)
  ((ram >= 1900)) || warn "$(t low_ram "$ram")"
  disk=$(df -BG --output=avail / | tail -n1 | tr -dc 0-9)
  ((disk >= 20)) || warn "$(t low_disk "$disk")"

  info "$(t detected_virt "$(virt_type)")"

  local p
  for p in apache2 exim4-base exim4-daemon-light sendmail-bin bind9 ufw firewalld; do
    if pkg_installed "$p"; then
      case $p in
        exim4*) info "removing $p (replaced by Postfix)"; run env DEBIAN_FRONTEND=noninteractive apt-get purge -y -q 'exim4*' ;;
        *) if [[ $FORCE == yes ]]; then warn "$(t conflict_pkg "$p")"; else die "$(t conflict_pkg "$p")"; fi ;;
      esac
    fi
  done
  ok "$pretty, $arch, ${ram} MB RAM, ${disk} GB free"
}
