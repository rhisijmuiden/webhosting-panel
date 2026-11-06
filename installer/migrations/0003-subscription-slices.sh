# shellcheck shell=bash
# Subscriptions: every website now runs in the slice of its own subscription
# (whp-sites-s<id>.slice) instead of one slice per customer (whp-sites-c<id>.slice).
# The panel moves the sites over on this update (panel/deploy/install.sh runs
# whp:limits:apply before the migrations). Remove the customer slices that no
# site uses any more; a slice that still has a site is kept as it is.
for unit in /etc/systemd/system/whp-sites-c*.slice; do
  [[ -f $unit ]] || continue
  owner=${unit##*/whp-sites-}
  owner=${owner%.slice}
  [[ $owner =~ ^c[0-9]{1,9}$ ]] || continue
  if grep -qsxE "SITE_OWNER=\"?$owner\"?" "$WHP_STATE"/sites/*.env; then
    continue
  fi
  systemctl stop "whp-sites-$owner.slice" >/dev/null 2>&1 || true
  rm -f "$unit" "$WHP_STATE/slices/$owner.env"
done
systemctl daemon-reload
