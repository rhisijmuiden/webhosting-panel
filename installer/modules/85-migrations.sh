# shellcheck shell=bash
# Numbered server migrations: one-off changes to existing servers that the
# idempotent modules above cannot express (move data, rename a service, clean up
# an old file). Each file in installer/migrations/ runs once per server, in
# order, on a fresh install as well as on every panel update (install.sh --upgrade).
# See installer/migrations/README.md.

module_migrations() {
  local dir=$INSTALLER_DIR/migrations state=$WHP_STATE/provision/migrations-applied f id n=0
  install -d -m 0750 "$WHP_STATE/provision"
  [[ -f $state ]] || install -m 0640 /dev/null "$state"
  for f in "$dir"/[0-9][0-9][0-9][0-9]-*.sh; do
    [[ -e $f ]] || continue
    id=$(basename "$f" .sh)
    if grep -qxF -- "$id" "$state"; then continue; fi
    info "migration $id"
    # Own subshell: a migration cannot change the installer's variables, and its
    # failure stops the run (the panel updater then rolls the code back).
    # Started in the background and waited for: inside an if/|| bash would
    # silently switch off set -e for the whole migration.
    local rc=0
    # shellcheck disable=SC1090 # migrations are found at run time
    (set -Eeuo pipefail; source "$f") >>"$WHP_INSTALL_LOG" 2>&1 &
    wait $! || rc=$?
    ((rc == 0)) || die "migration $id failed with exit code $rc (see $WHP_INSTALL_LOG)"
    printf '%s\n' "$id" >>"$state"
    n=$((n + 1))
  done
  ok "server migrations: $n applied, $(grep -c . "$state" || true) total"
}
