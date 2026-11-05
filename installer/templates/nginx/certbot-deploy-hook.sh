#!/bin/sh
# Managed by the Webhosting Panel installer: reload services after a renewal.
for s in nginx postfix dovecot; do
  systemctl is-active --quiet "$s" && systemctl reload "$s"
done
exit 0
