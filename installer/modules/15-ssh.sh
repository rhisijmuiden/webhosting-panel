# shellcheck shell=bash
# SSH hardening. Password login is only switched off when a key is present,
# so nobody gets locked out of a fresh server.

ssh_detect_port() {
  local p=''
  if command -v sshd >/dev/null; then
    # No early exit in the pipe: with pipefail a SIGPIPE in sshd would abort the installer.
    p=$(sshd -T 2>/dev/null | awk '$1=="port" && !f {print $2; f=1}' || true)
  fi
  printf '%s' "${p:-22}"
}

module_ssh() {
  [[ -n $WHP_SSH_PORT ]] || WHP_SSH_PORT=$(ssh_detect_port)
  env_set "$WHP_ETC/installer.env" WHP_SSH_PORT "$WHP_SSH_PORT"

  if ! command -v sshd >/dev/null; then
    info "openssh-server not installed, skipped"
    return 0
  fi
  [[ $WHP_SSH_HARDEN == yes ]] || { info "SSH hardening disabled"; return 0; }

  local keys=no f
  for f in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys; do
    [[ -s $f ]] && keys=yes
  done

  # Without keys: keep whatever the server allows now, so nobody is locked out.
  local sshd_cfg
  sshd_cfg=$(sshd -T 2>/dev/null || true)
  SSH_PASSWORD_AUTH=$(awk '$1=="passwordauthentication"{print $2}' <<<"$sshd_cfg" | tail -n1)
  SSH_ROOT_LOGIN=$(awk '$1=="permitrootlogin"{print $2}' <<<"$sshd_cfg" | tail -n1)
  [[ -n $SSH_PASSWORD_AUTH ]] || SSH_PASSWORD_AUTH=yes
  [[ -n $SSH_ROOT_LOGIN ]] || SSH_ROOT_LOGIN=prohibit-password
  if [[ $keys == yes ]]; then
    SSH_PASSWORD_AUTH=no
    SSH_ROOT_LOGIN=prohibit-password
  else
    warn "$(t ssh_skip_nokeys)"
  fi
  render "$TPL/ssh/10-whp.conf" /etc/ssh/sshd_config.d/10-whp.conf
  if sshd -t 2>>"$WHP_INSTALL_LOG"; then
    svc_reload ssh
    ok "sshd: password login $SSH_PASSWORD_AUTH, root login $SSH_ROOT_LOGIN"
  else
    rm -f /etc/ssh/sshd_config.d/10-whp.conf
    warn "sshd config test failed, hardening reverted"
  fi
}
