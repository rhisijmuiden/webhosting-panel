# Server migrations

Every panel update runs `installer/install.sh --upgrade` on the server (from
`agent/scripts/update.sh`). That re-runs all installer modules with the saved
answers, so **new packages, config templates, systemd units, users and
directories reach existing servers automatically** when you add them to a
module. Modules must stay idempotent: safe to run again and again.

Use a migration only for a **one-off** change that a module cannot express:
moving data, renaming or removing an old service or file, converting a setting.

- File name: `NNNN-short-name.sh` (next free number, 4 digits), e.g. `0002-move-backups.sh`.
- Runs once per server, in number order, after all modules; also on a fresh install.
- It is sourced in a subshell with `set -Eeuo pipefail` and has every installer
  helper and variable (`run`, `apt_install`, `render`, `secret`, `env_get`,
  `$WHP_HOSTNAME`, `$TPL`, ...).
- Write it idempotent anyway (a half-done run is retried).
- A failing migration stops the update; the updater rolls the code back.
- Applied migrations are listed in `/var/lib/webhosting-panel/provision/migrations-applied`.
- Never ask the server owner to run commands by hand: put it in a module or a migration.
