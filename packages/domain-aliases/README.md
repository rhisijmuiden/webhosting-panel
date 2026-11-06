# Domain aliases (`rhis/domain-aliases`)

Extra domains on a website, for example `example.be` and `example.com` next to
`example.nl`. Admin `/admin/domain-aliases`, client `/domain-aliases`, plus a
card on every website page.

| What | How |
| --- | --- |
| Web | `whp-site alias add <site> <alias> [--redirect] [--no-www] [--no-ssl]`: own Nginx server block in the site's vhost, same location body as the site (`serve`) or a 301 to the site (`redirect`). A suspended site is suspended on its aliases too. |
| SSL | Own Let's Encrypt certificate per alias (cert name = alias), so an alias whose DNS is not ready never blocks the site's certificate. Pending certificates are retried hourly by `whp-site ssl --pending`. |
| DNS | A `mail_domains` row per alias (dns-mail package): web records, and with mail also MX/SPF/DKIM/DMARC, are pushed to Cloudflare when the panel manages the zone. The customer can move the zone with the DNS wizard. |
| Mail | `mail: true` sets `mail_domains.alias_of_id`: `MailPusher` turns every mailbox and alias of the main domain into `user@alias -> user@main` and lets each mailbox send as `user@alias`. Addresses the alias domain has itself win. |
| Limit | CRM plan limit `domain_aliases` (or the subscription override), else `WHP_ALIASES_DEFAULT_LIMIT`; `null` = unlimited. Admins are not bound to it. `WHP_ALIASES_CUSTOMER=false` stops customers adding/removing. |

## Converting into a website of its own

1. Room in the package (`max_websites`): the customer (or an admin) converts directly.
2. No room: the customer orders a catalog product of kind `alias_upgrade`
   (pushed by the CRM with its webshop price). The CRM gets `order.requested`;
   when it sets the order to `accepted` or `completed` the alias is converted.
3. The CRM can always convert with `POST /api/v1/crm/domain-aliases/{id}/convert`,
   optionally with `plan_id` + `external_id` for a subscription of its own.

Converting removes the alias block (keeping the certificate), turns the mail
forwarding into ordinary mail aliases on the alias' mail domain, then creates
an empty website through `WebsiteManager` (or `Subscriptions::create`). The CRM
gets `domain_alias.converted`.

Agent actions (`agent/whp_agent/actions/domain_aliases.py`): `site.alias_list`,
`site.alias_add`, `site.alias_mode`, `site.alias_ssl`, `site.alias_remove`.
