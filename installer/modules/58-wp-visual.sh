# shellcheck shell=bash
# Screenshots for the smart WordPress updates (panel: WordPress > Smart updates).
# Before and after a scheduled update the agent photographs the website with
# headless Chromium (agent/whp_agent/shot_worker.py over the DevTools pipe) and
# compares the pictures with Pillow; a site that looks different is rolled back.
# Chromium runs as the unprivileged system user whp-shot in a sandboxed
# transient unit, never as root. Idempotent: runs on every panel update.

WP_VISUAL_USER=whp-shot

module_wp_visual() {
  local pkgs=(python3-pil fonts-dejavu-core fonts-liberation2 fonts-noto-color-emoji)
  # The headless shell is the lighter build meant for automation; plain chromium otherwise.
  if apt-cache show chromium-headless-shell >/dev/null 2>&1; then
    pkgs+=(chromium-headless-shell)
  else
    pkgs+=(chromium)
  fi
  apt_install "${pkgs[@]}"
  getent group "$WP_VISUAL_USER" >/dev/null || run groupadd --system "$WP_VISUAL_USER"
  getent passwd "$WP_VISUAL_USER" >/dev/null || run useradd --system --gid "$WP_VISUAL_USER" \
    --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin "$WP_VISUAL_USER"
  install -d -m 0711 "$WHP_STATE/wordpress"
  install -d -m 0711 "$WHP_STATE/wordpress/shots"
  install -d -m 0700 "$WHP_STATE/wordpress/visual"
  if [[ -x /usr/bin/chromium-headless-shell || -x /usr/bin/chromium ]]; then
    ok "screenshots for smart WordPress updates"
  else
    warn "Chromium is missing: smart WordPress updates only use the health check"
  fi
  return 0
}
