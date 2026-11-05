# DNS, mail routing and webmail (phase 3)

This part of the panel keeps each domain's DNS on Cloudflare in line with how
the domain is hosted, decides per domain whether its mailboxes live on this
server or in Microsoft 365, and gives customers a webmail and easy mail app
setup.

## Pieces

| Path | What |
| --- | --- |
| `packages/dns-mail/` | Composer package `Rhis\DnsMail\`: Cloudflare client, sync planner, mail DNS profiles, SPF/DMARC generators, MTA-STS, client setup files (autoconfig, autodiscover, Apple profile). No framework dependency; a Laravel service provider wires it into the panel. |
| `agent/whp_agent/actions/mail.py` | Agent actions `mail.set_routing`, `mail.dkim_generate`, `mail.dkim_get`, `mail.issue_endpoint_cert`. |
| `webmail/snappymail/` | `install.sh` (SnappyMail on Debian 13, own PHP-FPM pool, data outside the web root), RHIS theme, default config. |
| `webmail/autoconfig/` | Small PHP endpoint for `autoconfig.`, `autodiscover.` and `mta-sts.` hosts of every hosted domain. |
| `webmail/nginx/webmail.conf.tpl` | Nginx vhost for webmail and those endpoints. |

## Cloudflare sync

The panel talks to Cloudflare directly from PHP (no root needed) with an API
token that has **Zone: Read** and **DNS: Edit**.

Records the panel creates carry the comment `managed:rhis-panel …`. The sync
planner groups records in *slots* (a name plus a kind: address, MX, SPF,
DMARC, DKIM, SRV, …) and follows these rules:

1. A slot the panel wants and that holds only its own records (or nothing): create, update and delete as needed.
2. A slot the panel wants that holds records someone else made: report a **conflict** and change nothing, unless the admin ticks **Take over existing records** (`adopt`). When the foreign data is already correct, it is left alone.
3. A managed record in a slot that is no longer wanted (for example after switching a domain from RHIS mail to Microsoft 365): delete it.
4. Every other record (customer TXT verifications, records added by hand in Cloudflare or the client portal): never touched.

Changes go to Cloudflare in one atomic batch request. `SyncPlan::toArray()`
gives the preview the admin or customer sees before applying.

## Mail providers per domain

| | RHIS Mail | Microsoft 365 | Other |
| --- | --- | --- | --- |
| MX | `mail host`, 10 | `<domain-with-dashes>.mail.protection.outlook.com` (or the host from the admin center), 0 | not touched |
| SPF | `mx` + server IPs + relay includes + extras | `include:spf.protection.outlook.com` + server (when websites send mail) + extras | not touched |
| DKIM | `whp._domainkey` TXT, key made by the agent, signed by Rspamd | Microsoft `selector1/2` CNAMEs + our `whp` key for website mail | our `whp` key only, when websites send mail |
| DMARC | generated | generated | not touched |
| Discovery | `autoconfig`, `autodiscover` CNAME, SRV for IMAPS/submission(s)/autodiscover, `_imap`/`_pop3` marked unavailable | `autodiscover` CNAME to Outlook | none |
| MTA-STS / TLS-RPT | policy `mx: <mail host>` | policy `mx: *.mail.protection.outlook.com` | none |
| Local delivery | domain in `/etc/postfix/whp/virtual_domains`, webmail login | not local: mail from this server to the domain goes out to Microsoft | not local |

Switching a domain from RHIS to Microsoft 365 removes it from local delivery
and webmail; the mailboxes stay on disk so they can be migrated.

Microsoft 365 notes: IMAP/SMTP with passwords is switched off in Exchange
Online, so webmail for those domains is Outlook on the web. Newer tenants can
get MX hosts under `mx.microsoft` and different DKIM targets; the domain
settings take the values from the admin and Defender portals when they differ
from the classic format.

## Mail app setup

- **Thunderbird, K-9/Thunderbird for Android, FairEmail, many others:** autoconfig.
- **Outlook (classic):** POX autodiscover, plus the `_autodiscover._tcp` SRV record.
- **iPhone, iPad, Mac:** `.mobileconfig` profile from the client portal; signed with the server certificate when it can be read.
- **Everything else:** SRV records (RFC 6186/8314) and the manual settings card: IMAP 993 SSL/TLS, SMTP 465 SSL/TLS or 587 STARTTLS, user name is the full address.

The endpoints need certificates for `autoconfig.`, `autodiscover.` and
`mta-sts.<domain>`; `mail.issue_endpoint_cert` requests one Let's Encrypt
certificate per domain (cert name `mail-endpoints.<domain>`) with HTTP-01 on
`/var/lib/webhosting-panel/acme`.

## Agent actions

```text
mail.set_routing        {mail_host, brand?, domains: [{domain, provider: rhis|microsoft365|external, mta_sts_mode?: testing|enforce|none}]}
                        -> {changed: [...], local_domains: [...]}   (always send the full list)
mail.dkim_generate      {domain, replace?: bool, bits?: 2048|3072} -> {domain, selector, algorithm, bits, public_key}
mail.dkim_get           {domain} -> {domain, selector, algorithm, public_key}
mail.issue_endpoint_cert {domain, email?} -> {job}
mail.mailboxes_sync     {mailboxes: [{email, password_hash: "{ARGON2ID}$argon2id$…", quota_mb?, enabled?, send_as?: [...]}],
                         aliases: [{address ("@domain" = catch-all), targets: [...]}]}
                        -> {changed, created_homes, mailboxes, aliases, skipped_not_local}   (always send the full list)
mail.mailbox_usage      {} -> {usage: {email: {used_kb, limit_kb}}}
mail.mailbox_purge      {email} -> {email, moved_to}   (only after the mailbox is gone; moves mail to /var/vmail/.deleted)
```

Mailboxes: the panel hashes passwords with PHP `password_hash(..., PASSWORD_ARGON2ID)`
and sends `{ARGON2ID}` + that hash, so plain passwords never reach the agent.
`mail.mailboxes_sync` rewrites `/etc/dovecot/users` and the Postfix maps
`virtual_mailboxes`, `virtual_aliases` and `sender_login` (every mailbox may
send as itself plus its `send_as` addresses), creates `/var/vmail/<domain>/<user>`
(vmail, 0700) before the first login, and skips addresses on domains that are
not RHIS mail domains. A disabled mailbox still receives mail but cannot log in.

## Planned next

- Inbound anti-spam tuning in Rspamd (greylisting, Bayes, RBLs, fuzzy hashes), ClamAV virus scanning, and a quarantine view for customers to release false positives.
- DANE (TLSA for the mail host, needs DNSSEC on the zone) and BIMI.
- Panel screens: Cloudflare settings, DNS preview/apply, mail tab per domain with the SPF/DMARC generators, mail app setup card.
