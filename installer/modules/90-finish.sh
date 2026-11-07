# shellcheck shell=bash
# Summary and a final health check.

module_finish() {
  local summary=$WHP_ETC/install-summary.txt version=dev what=installed
  [[ -r $WHP_CODE_DIR/VERSION ]] && version=$(<"$WHP_CODE_DIR/VERSION")
  [[ $WHP_UPGRADE == yes ]] && what=updated
  env_set "$WHP_ETC/installer.env" WHP_INSTALLER_VERSION "$version"

  install -m 0600 /dev/null "$summary"
  {
    echo "Dekwerk $version - $what $(date '+%F %T %Z')"
    echo
    echo "Hostname      $WHP_HOSTNAME"
    echo "Panel         https://$WHP_HOSTNAME:8443"
    if [[ -e $WHP_STATE/panel/.admin-created ]]; then
      echo "Panel admin   $WHP_EMAIL (password: grep PANEL_ADMIN_PASS $WHP_ETC/secrets.env)"
    fi
    echo "Channel       $WHP_CHANNEL"
    echo "PHP           ${PHP_VERSIONS[*]} (panel: $PANEL_PHP)"
    echo "Mail relay    ${WHP_RELAY_HOST:-none (direct delivery)}"
    echo "Firewall      tcp {${FW_TCP:-off}} udp {${FW_UDP:-off}}"
    echo "Secrets       $WHP_ETC/secrets.env"
    echo "Install log   $WHP_INSTALL_LOG"
    echo "Ready check   ${VERIFY_PASSED:-0} passed, ${#VERIFY_FAILED[@]} missing"
    local f
    for f in "${VERIFY_FAILED[@]}"; do echo "  missing     $f"; done
    echo
    "$INSTALLER_DIR/bin/whp-status" --plain || true
    echo
    echo "$(t next_steps):"
    echo " - $(t next_dns "$WHP_HOSTNAME")"
    [[ -n $WHP_RELAY_HOST ]] && echo " - $(t next_spf)"
    echo " - whp-site add example.com --php 8.4"
    echo " - $(t next_panel "$WHP_HOSTNAME")"
  } >"$summary"
  ok "$(t summary_saved "$summary")"
}

# final_report: the screen after the last step. Called by install.sh once every
# step finished: a full bar, what was installed, how to log in, what needs a look.
final_report() {
  local took version=dev line w f pw
  took=$(_dur $((SECONDS - ${INSTALL_STARTED:-0})))
  [[ -r $WHP_CODE_DIR/VERSION ]] && version=$(<"$WHP_CODE_DIR/VERSION")
  STEP_NO=0
  printf '\n%s[%d/%d]%s %s %3d%%  %s%s%s\n' "$C_BOLD" "$STEP_TOTAL" "$STEP_TOTAL" "$C_RESET" \
    "$C_GREEN$(_bar 100)$C_RESET" 100 "$C_GREEN$C_BOLD" "$(if [[ $WHP_UPGRADE == yes ]]; then t done_upgrade; else t "done"; fi)" "$C_RESET"

  local rows=()
  rows+=("$(t sum_version)|$version ($WHP_CHANNEL)")
  rows+=("$(t sum_panel)|https://$WHP_HOSTNAME:8443")
  if [[ -e $WHP_STATE/panel/.admin-created ]]; then
    rows+=("$(t sum_login)|$WHP_EMAIL")
    # The first password is only shown on the terminal of the first install,
    # never in a log or in the panel updater's output.
    if [[ ${FIRST_INSTALL:-no} == yes && -t 1 ]] && pw=$(env_get "$WHP_ETC/secrets.env" PANEL_ADMIN_PASS); then
      rows+=("$(t sum_password)|$pw")
    else
      rows+=("$(t sum_password)|grep PANEL_ADMIN_PASS $WHP_ETC/secrets.env")
    fi
  fi
  rows+=("$(t sum_time)|$took")
  rows+=("$(t sum_check)|$(t verify_ok "${VERIFY_PASSED:-0}" "$((${VERIFY_PASSED:-0} + ${#VERIFY_FAILED[@]}))")")
  rows+=("$(t sum_summary)|$WHP_ETC/install-summary.txt")
  rows+=("$(t sum_log)|$WHP_INSTALL_LOG")

  w=0
  for line in "${rows[@]}"; do f=${line%%|*}; ((${#f} > w)) && w=${#f}; done
  printf '\n'
  for line in "${rows[@]}"; do
    printf '  %s%-*s%s  %s\n' "$C_DIM" "$w" "${line%%|*}" "$C_RESET" "${line#*|}"
  done

  if ((${#VERIFY_FAILED[@]})); then
    printf '\n%s\n' "${C_YELLOW}${C_BOLD}$(t sum_missing)${C_RESET}"
    for f in "${VERIFY_FAILED[@]}"; do printf '  %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$f"; done
  fi
  if ((${#WHP_WARNINGS[@]})); then
    printf '\n%s\n' "${C_YELLOW}${C_BOLD}$(t sum_warnings "${#WHP_WARNINGS[@]}")${C_RESET}"
    for f in "${WHP_WARNINGS[@]}"; do printf '  %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$f"; done
  fi

  printf '\n%s\n' "${C_BOLD}$(t next_steps)${C_RESET}"
  printf '  - %s\n' "$(t next_dns "$WHP_HOSTNAME")"
  [[ -n $WHP_RELAY_HOST ]] && printf '  - %s\n' "$(t next_spf)"
  printf '  - %s\n' "$(t next_panel "$WHP_HOSTNAME")"
  printf '\n'
  _log_file "DONE  $STEP_TOTAL steps in $took, ${#VERIFY_FAILED[@]} missing, ${#WHP_WARNINGS[@]} warnings"
  return 0
}
