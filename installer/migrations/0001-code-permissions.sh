# shellcheck shell=bash
# Earlier updates wrote code files with the agent's umask (0640 root:whp):
# Nginx (www-data) could not read CSS/JS and the panel lost its styling.
git -c "safe.directory=$WHP_CODE_DIR" -C "$WHP_CODE_DIR" ls-files -z | (cd "$WHP_CODE_DIR" && xargs -0r chmod a+r)
find "$WHP_CODE_DIR" \( -path "$WHP_CODE_DIR/.git" -o -path "$WHP_CODE_DIR/panel/storage" -o -path "$WHP_CODE_DIR/panel/vendor" \
  -o -path "$WHP_CODE_DIR/panel/bootstrap/cache" \) -prune -o -type d -print0 | xargs -0r chmod a+rx
