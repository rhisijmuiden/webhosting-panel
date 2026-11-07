#!/usr/bin/env bash
# One-line install on a fresh Debian 13 server:
#
#   curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/main/installer/bootstrap.sh | sudo bash
#   curl -fsSL .../bootstrap.sh | sudo bash -s -- --channel test --hostname server1.example.com
#
# Asks for the release channel when none is given (production = branch main,
# test = branch test), clones or updates /opt/webhosting-panel to the newest code
# of that branch and starts installer/install.sh with the same options.
set -euo pipefail
REPO=${WHP_REPO_URL:-https://github.com/rhisijmuiden/webhosting-panel.git}
DEST=/opt/webhosting-panel
branch=main
args=("$@")
channel='' quiet=no lang=${LC_ALL:-${LC_MESSAGES:-${LANG:-}}}
for ((i = 0; i < ${#args[@]}; i++)); do
  case ${args[i]} in
    --channel) channel=${args[i+1]:-} ;;
    -y|--yes|--upgrade) quiet=yes ;;
    --lang) lang=${args[i+1]:-} ;;
  esac
done
[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }

# No channel given and someone at the keyboard: ask before cloning, because the
# channel decides which code (and so which version) gets installed.
if [[ -z $channel && $quiet == no ]] && { : </dev/tty; } 2>/dev/null; then
  if [[ $lang == nl* ]]; then
    printf '\nWebhosting Panel\n\nReleasekanaal\n  1) productie - stabiele versies (branch main, aanbevolen)\n  2) test - nieuwste functies eerst (branch test)\n' >/dev/tty
    prompt='Keuze [1]: '
  else
    printf '\nWebhosting Panel\n\nRelease channel\n  1) production - stable releases (branch main, recommended)\n  2) test - newest features first (branch test)\n' >/dev/tty
    prompt='Choice [1]: '
  fi
  reply=''
  read -r -p "$prompt" reply </dev/tty || true
  case $reply in 2|t|test) channel='test' ;; *) channel=production ;; esac
  args+=(--channel "$channel")
fi
[[ $channel == test ]] && branch='test'
if ! command -v git >/dev/null; then
  apt-get update -q && DEBIAN_FRONTEND=noninteractive apt-get install -y -q git ca-certificates
fi
if [[ -d $DEST/.git ]]; then
  echo "Updating $DEST to the newest code of branch $branch"
  git -C "$DEST" fetch -q origin "$branch"
  git -C "$DEST" checkout -q "$branch" 2>/dev/null || git -C "$DEST" checkout -q -b "$branch" "origin/$branch"
  git -C "$DEST" merge -q --ff-only "origin/$branch"
else
  echo "Downloading the newest code of branch $branch to $DEST"
  git clone -q --branch "$branch" "$REPO" "$DEST"
fi
# Questions are read from the terminal even when this script comes from a pipe.
if { : </dev/tty; } 2>/dev/null; then
  exec bash "$DEST/installer/install.sh" "${args[@]}" </dev/tty
fi
exec bash "$DEST/installer/install.sh" "${args[@]}"
