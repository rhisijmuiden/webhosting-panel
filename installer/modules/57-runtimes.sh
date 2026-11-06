# shellcheck shell=bash
# Node.js and Python for the customer apps (panel: Node.js & Python).
# Native binaries, no Docker: Node.js from the official nodejs.org builds
# (checked against SHASUMS256.txt), Python through uv (python-build-standalone).
# Every version lives in its own folder below /opt/whp-runtimes, so customers
# can pick a version per app and the admin can add or remove versions in the
# panel (agent actions runtime.*). This module installs uv and the default
# versions; it is idempotent and runs on every panel update.

UV_VERSION=0.13.7
NODE_DEFAULT_MAJOR=24
PYTHON_DEFAULT=3.13
WHP_RUNTIMES=/opt/whp-runtimes

module_runtimes() {
  install -d -m 0755 "$WHP_RUNTIMES" "$WHP_RUNTIMES/node" "$WHP_RUNTIMES/python" "$WHP_RUNTIMES/python/bin" \
    "$WHP_RUNTIMES/cache"
  # Per-app environment files and start scripts (root only, read by systemd before it drops privileges).
  install -d -m 0700 "$WHP_ETC/webapps"
  install -d -m 0750 "$WHP_STATE/webapps"
  apt_install ca-certificates curl xz-utils build-essential
  install_uv
  install_node_default
  install_python_default
  return 0
}

runtime_arch() {
  case $(dpkg --print-architecture) in
    amd64) echo x64 ;; arm64) echo arm64 ;; *) echo unsupported ;;
  esac
}

install_uv() {
  local have triple tmp url
  have=$(/usr/local/bin/uv --version 2>/dev/null | awk '{print $2}' || true)
  if [[ $have == "$UV_VERSION" ]]; then ok "uv $have"; return 0; fi
  case $(runtime_arch) in x64) triple=x86_64-unknown-linux-gnu ;; arm64) triple=aarch64-unknown-linux-gnu ;; *) warn "uv: unsupported CPU"; return 0 ;; esac
  url=https://github.com/astral-sh/uv/releases/download/$UV_VERSION/uv-$triple.tar.gz
  tmp=$(mktemp -d)
  if curl -fsSL --retry 3 -o "$tmp/uv.tar.gz" "$url" && curl -fsSL --retry 3 -o "$tmp/uv.sha256" "$url.sha256" \
     && [[ $(sha256sum "$tmp/uv.tar.gz" | cut -d' ' -f1) == "$(awk '{print $1}' "$tmp/uv.sha256")" ]]; then
    tar -xzf "$tmp/uv.tar.gz" -C "$tmp"
    install -m 0755 "$tmp/uv-$triple/uv" /usr/local/bin/uv
    install -m 0755 "$tmp/uv-$triple/uvx" /usr/local/bin/uvx
    ok "uv $UV_VERSION"
  else
    warn "uv $UV_VERSION could not be downloaded or verified; Python apps need /usr/local/bin/uv"
  fi
  rm -rf "$tmp"
}

install_node_default() {
  local major=$NODE_DEFAULT_MAJOR arch tmp file version sum
  if [[ -x $WHP_RUNTIMES/node/$major/bin/node ]]; then ok "Node.js $("$WHP_RUNTIMES/node/$major/bin/node" --version)"; return 0; fi
  # An admin removed every Node.js version on purpose: do not put one back on each update.
  [[ -f $WHP_STATE/webapps/node-default-done ]] && return 0
  arch=$(runtime_arch)
  [[ $arch != unsupported ]] || { warn "Node.js: unsupported CPU"; return 0; }
  tmp=$(mktemp -d)
  if curl -fsSL --retry 3 -o "$tmp/SHASUMS256.txt" "https://nodejs.org/dist/latest-v$major.x/SHASUMS256.txt"; then
    file=$(grep -oE "node-v[0-9.]+-linux-$arch\.tar\.xz" "$tmp/SHASUMS256.txt" | head -n1)
    sum=$(awk -v f="$file" '$2 == f {print $1}' "$tmp/SHASUMS256.txt")
    version=${file#node-}; version=${version%%-linux*}
    if [[ -n $file ]] && curl -fsSL --retry 3 -o "$tmp/$file" "https://nodejs.org/dist/$version/$file" \
       && [[ $(sha256sum "$tmp/$file" | cut -d' ' -f1) == "$sum" ]]; then
      install -d -m 0755 "$WHP_RUNTIMES/node/.$major.new"
      tar -xJf "$tmp/$file" -C "$WHP_RUNTIMES/node/.$major.new" --strip-components=1 --no-same-owner
      rm -rf "$WHP_RUNTIMES/node/$major"
      mv "$WHP_RUNTIMES/node/.$major.new" "$WHP_RUNTIMES/node/$major"
      printf '%s\n' "$version" >"$WHP_RUNTIMES/node/$major/.whp-version"
      : >"$WHP_STATE/webapps/node-default-done"
      ok "Node.js $version"
    else
      warn "Node.js $major could not be downloaded or verified (add it later in the panel)"
    fi
  else
    warn "nodejs.org is not reachable: Node.js $major is not installed (add it later in the panel)"
  fi
  rm -rf "$tmp"
}

install_python_default() {
  local v=$PYTHON_DEFAULT
  [[ -x /usr/local/bin/uv ]] || return 0
  if [[ -x $WHP_RUNTIMES/python/bin/python$v ]]; then ok "Python $("$WHP_RUNTIMES/python/bin/python$v" --version 2>&1 | awk '{print $2}')"; return 0; fi
  [[ -f $WHP_STATE/webapps/python-default-done ]] && return 0
  if UV_PYTHON_INSTALL_DIR=$WHP_RUNTIMES/python UV_PYTHON_BIN_DIR=$WHP_RUNTIMES/python/bin UV_CACHE_DIR=$WHP_RUNTIMES/cache/uv \
     run /usr/local/bin/uv python install --no-progress "$v"; then
    chmod -R a+rX "$WHP_RUNTIMES/python"
    : >"$WHP_STATE/webapps/python-default-done"
    ok "Python $v"
  else
    warn "Python $v could not be installed (add it later in the panel)"
  fi
}
