# shellcheck shell=bash
# Hard disk quotas per website (project quotas on the filesystem that holds
# /var/www). Root filesystems cannot get project quotas while mounted, so the
# recommended setup is a separate data disk: --quota-device /dev/sdX turns an
# EMPTY disk into XFS with prjquota and mounts it on /var/www.

module_quota() {
  apt_install quota xfsprogs

  if [[ -n $WHP_QUOTA_DEVICE ]] && ! mountpoint -q /var/www; then
    local dev=$WHP_QUOTA_DEVICE
    [[ -b $dev ]] || die "not a block device: $dev"
    if [[ -n $(blkid -o value -s TYPE "$dev" 2>/dev/null)$(blkid -o value -s PTTYPE "$dev" 2>/dev/null) ]] || findmnt -rn -S "$dev" >/dev/null; then
      die "$(t quota_device_busy "$dev")"
    fi
    run mkfs.xfs -q -L whp-data "$dev"
    local uuid
    uuid=$(blkid -o value -s UUID "$dev")
    install -d -m 0755 /var/www
    grep -q "UUID=$uuid" /etc/fstab || printf 'UUID=%s /var/www xfs defaults,noatime,prjquota 0 2\n' "$uuid" >>/etc/fstab
    run systemctl daemon-reload
    run mount /var/www
    install -d -m 0755 "$WHP_VHOSTS" /var/www/acme
  fi

  local mode fs mnt
  install -d -m 0755 "$WHP_VHOSTS"
  mode=$(quota_mode "$WHP_VHOSTS")
  fs=$(findmnt -n -o FSTYPE --target "$WHP_VHOSTS")
  mnt=$(findmnt -n -o TARGET --target "$WHP_VHOSTS")
  env_set "$WHP_ETC/installer.env" WHP_QUOTA_MODE "$mode"
  if [[ $mode == none ]]; then
    if [[ $WHP_REQUIRE_QUOTA == yes ]]; then die "$(t quota_missing "$mnt" "$fs")"; fi
    warn "$(t quota_missing "$mnt" "$fs")"
  else
    ok "$(t quota_ok "$mnt" "$fs")"
  fi
}
