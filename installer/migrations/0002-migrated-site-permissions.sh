# shellcheck shell=bash
# Websites copied by the Plesk migration before the fix got the agent's umask:
# directories 0750 and files 0640 owned by the site user, so Nginx (www-data)
# answered 403 Forbidden. Give exactly those modes the normal 0755/0644 again.
# Only the website directories (public/, app/) and only files of the site user.
for env in "$WHP_STATE"/sites/*.env; do
  [[ -f $env ]] || continue
  domain=$(env_get "$env" SITE_DOMAIN 2>/dev/null || true)
  user=$(env_get "$env" SITE_USER 2>/dev/null || true)
  [[ $domain =~ ^[a-z0-9.-]+$ ]] || continue
  [[ $user =~ ^s_[a-z0-9_]+$ ]] || continue
  id "$user" >/dev/null 2>&1 || continue
  for dir in "$WHP_VHOSTS/$domain/public" "$WHP_VHOSTS/$domain/app"; do
    [[ -d $dir && ! -L $dir ]] || continue
    find "$dir" -xdev -user "$user" -type d -perm 0750 -exec chmod 0755 {} +
    find "$dir" -xdev -user "$user" -type f -perm 0640 -exec chmod 0644 {} +
    find "$dir" -xdev -user "$user" -type f -perm 0750 -exec chmod 0755 {} +
  done
done
