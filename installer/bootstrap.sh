#!/usr/bin/env bash
# One-line install on a fresh Debian 13 server:
#
#   curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/main/installer/bootstrap.sh | sudo bash
#   curl -fsSL .../bootstrap.sh | sudo bash -s -- --channel test --hostname server1.example.com
#
# Clones the repository to /opt/webhosting-panel (branch main for production,
# test for the test channel) and starts installer/install.sh with the same options.
set -euo pipefail
REPO=${WHP_REPO_URL:-https://github.com/rhisijmuiden/webhosting-panel.git}
DEST=/opt/webhosting-panel
branch=main
args=("$@")
for ((i = 0; i < ${#args[@]}; i++)); do
  if [[ ${args[i]} == --channel && ${args[i+1]:-} == test ]]; then branch='test'; fi
done
[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
if ! command -v git >/dev/null; then
  apt-get update -q && DEBIAN_FRONTEND=noninteractive apt-get install -y -q git ca-certificates
fi
if [[ -d $DEST/.git ]]; then
  git -C "$DEST" fetch -q origin "$branch" && git -C "$DEST" checkout -q "$branch" && git -C "$DEST" pull -q --ff-only
else
  git clone -q --branch "$branch" "$REPO" "$DEST"
fi
# Questions are read from the terminal even when this script comes from a pipe.
if { : </dev/tty; } 2>/dev/null; then
  exec bash "$DEST/installer/install.sh" "$@" </dev/tty
fi
exec bash "$DEST/installer/install.sh" "$@"
