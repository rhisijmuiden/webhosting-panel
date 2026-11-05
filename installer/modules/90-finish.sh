# shellcheck shell=bash
# Summary and a final health check.

module_finish() {
  local summary=$WHP_ETC/install-summary.txt version=dev
  [[ -r $WHP_CODE_DIR/VERSION ]] && version=$(<"$WHP_CODE_DIR/VERSION")
  env_set "$WHP_ETC/installer.env" WHP_INSTALLER_VERSION "$version"

  install -m 0600 /dev/null "$summary"
  {
    echo "Webhosting Panel $version - installed $(date '+%F %T %Z')"
    echo
    echo "Hostname      $WHP_HOSTNAME"
    echo "Panel         https://$WHP_HOSTNAME:8443"
    echo "Channel       $WHP_CHANNEL"
    echo "PHP           ${PHP_VERSIONS[*]} (panel: $PANEL_PHP)"
    echo "Mail relay    ${WHP_RELAY_HOST:-none (direct delivery)}"
    echo "Firewall      tcp {${FW_TCP:-off}} udp {${FW_UDP:-off}}"
    echo "Secrets       $WHP_ETC/secrets.env"
    echo "Install log   $WHP_INSTALL_LOG"
    echo
    "$INSTALLER_DIR/bin/whp-status" --plain || true
    echo
    echo "$(t next_steps):"
    echo " - $(t next_dns "$WHP_HOSTNAME")"
    [[ -n $WHP_RELAY_HOST ]] && echo " - $(t next_spf)"
    echo " - whp-site add example.com --php 8.4"
    echo " - $(t next_panel "$WHP_HOSTNAME")"
  } >"$summary"

  printf '\n'
  cat "$summary"
  printf '\n%s\n' "${C_GREEN}${C_BOLD}$(t "done")${C_RESET}"
  info "$(t summary_saved "$summary")"
}
