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

A domain's sync only looks at names inside that domain, and skips names that
belong to another panel domain in the same zone (`shop.example.com` next to
`example.com`) and server records marked `managed:rhis-panel server` (DANE).
See `Rhis\DnsMail\Dns\RecordScope`.

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
mail.spam_policy_sync   {default_quarantine_score?, domains: [{domain, quarantine_score: 6..50|null, junk?: bool, allow?: [...], block?: [...]}]}
                        -> {changed, domains}   (always send the full list; allow/block entries are addresses or domains)
mail.quarantine_list    {domain, user?} -> {domain, messages: [{user, uid, date, size, from, to, subject, score, virus}], truncated}
mail.quarantine_release {domain, user, uid, learn?: true} -> {user, uid, learned}   (moves to INBOX, learns ham)
mail.quarantine_delete  {domain, user, uids: [...], learn?: false} -> {user, deleted, learned}   (learn = learn as spam first)
mail.tlsa               {} -> {usage: 3, selector: 1, matching_type: 1, certificate, cert_file, not_after, reuse_key}
```

Mailboxes: the panel hashes passwords with PHP `password_hash(..., PASSWORD_ARGON2ID)`
and sends `{ARGON2ID}` + that hash, so plain passwords never reach the agent.
`mail.mailboxes_sync` rewrites `/etc/dovecot/users` and the Postfix maps
`virtual_mailboxes`, `virtual_aliases` and `sender_login` (every mailbox may
send as itself plus its `send_as` addresses), creates `/var/vmail/<domain>/<user>`
(vmail, 0700) before the first login, and skips addresses on domains that are
not RHIS mail domains. A disabled mailbox still receives mail but cannot log in.

## Panel screens

| Where | What |
| --- | --- |
| Admin > Hosting > Domains & mail | All domains; add a domain without a website; per domain the tabs Mail settings, Mailboxes, Spam & quarantine and DNS records. |
| Admin > System > DNS & mail server | Cloudflare token (stored encrypted), mail host, public IPs, relay SPF includes, DMARC/TLS report addresses, Microsoft 365 order link and price, default quarantine score, DANE. |
| Client portal > Mail & DNS | The customer's own domains with the same tabs (no DNS-management switch, no delete). |

- Creating a website creates its mail domain, a DKIM key, updates the agent and publishes DNS straight away.
- Saving mail settings pushes the routing to the agent and publishes to Cloudflare; records that already exist with other values show up as a conflict on the DNS tab with a "take over" preview.
- The Microsoft 365 wizard walks through the MS= verification code, tenant, publish and DKIM in Defender, and shows the "order through RHIS" card when an order page is set.
- Customers add and remove their own records (A, AAAA, CNAME, MX, TXT); records the panel manages are locked.
- Mailboxes respect `max_mailboxes` of the hosting package. Passwords are hashed in the panel (`{ARGON2ID}`), the Apple profile is a download per mailbox.
- `Rhis\DnsMail\Testing\FakeCloudflare` is an in-memory Cloudflare API for tests and local development.

## Spam, quarantine and sender lists

Scanning is set up by the installer: Rspamd with greylisting (from score 4),
Bayes in Valkey, RBLs, DMARC and ClamAV. From score 6 Rspamd adds
`X-Spam: Yes`, above 15 it refuses the message, and it always adds
`X-Spam-Level` with one `*` per point.

Where a scanned message lands is decided by a Sieve script that Dovecot runs
before the user's own filters. The agent writes it from the panel's settings
(`mail.spam_policy_sync`, sent with every mail push):

| Order | Rule | Goes to |
| --- | --- | --- |
| 1 | Sender on the domain's allow list | Inbox, whatever the score |
| 2 | Sender on the domain's block list | Junk |
| 3 | Score at or above the domain's quarantine score (default 10) | Quarantine folder |
| 4 | `X-Spam: Yes` (score 6 and up), when "Move to Junk" is on | Junk |

The Quarantine folder is expunged after 30 days by Dovecot. In the panel, the
Spam & quarantine tab (admin and client portal) lists what is held across all
mailboxes of the domain. **Deliver to inbox** moves a message to the inbox and
teaches Bayes it is good mail; it can also put the sender on the allow list.
**Delete** expunges and teaches Bayes it is spam. Viruses cannot be released.

Allow-listing does not skip greylisting or the hard reject at 15; those happen
before delivery.

Dovecot setup lives in [mail/antispam](../mail/antispam): `dovecot.conf`
(the `sieve_script` before-script and the Quarantine mailbox) and
`before.sieve`, the script the server starts with until the panel pushes its
first policy.

## DANE and BIMI

**DANE** (Admin > System > DNS & mail server): the panel publishes
`_25._tcp.<mail host> TLSA 3 1 1 <sha256 of the public key>` with the value
from `mail.tlsa`. It refuses while DNSSEC is off on the zone (checked through
the Cloudflare API) or while certbot would give the certificate a new key at
renewal (`reuse_key = True` missing in the renewal config), because both would
make DANE-checking servers refuse our mail. Once on, `dns-mail:dane` runs every
night at 04:20 and keeps the record equal to the certificate.

**BIMI** (per domain, Mail settings tab): a logo URL (square SVG Tiny PS) and an
optional VMC/CMC certificate URL give `default._bimi.<domain> TXT
"v=BIMI1; l=…; a=…"`. The record is only published while DMARC is
`quarantine` or `reject` at 100%, since mail apps ignore it otherwise.

## Planned next

- QR code on the mailbox page for setting up phones.
- Customer DNS management through the RHIS CRM API (phase 4).

## DNS wizard: move a domain's DNS to RHIS Cloudflare

For customers whose domain is registered elsewhere and who want RHIS to run
their DNS. The domain stays at its registrar; only the nameservers change.
Client portal and admin: domain > DNS records > **Start the DNS wizard**
(`/mail/domains/{id}/dns/connect`).

1. **Add the domain.** The panel creates a full zone in the RHIS Cloudflare
   account (or reuses one that is already there), lets Cloudflare copy the
   records it finds at the current provider (`dns_records/scan`) and
   publishes the panel's records. Nothing is live yet.
2. **Check the records.** Copied records that clash with the hosting show up
   as conflicts on the DNS tab, with the usual take-over preview.
3. **Change the nameservers.** The wizard shows the two Cloudflare
   nameservers (copy buttons), the old ones to remove, the registrar's name
   and a DNSSEC warning (remove the DS record first).
4. **Checks.** `dns-mail:check` (every 5 minutes) looks up the public NS
   records and asks Cloudflare: every 5 minutes in the first hour, every 15
   minutes for two days, then hourly; after 28 days the attempt expires.
   When Cloudflare marks the zone active the customer's portal users get a
   mail (`DnsLiveNotification`, in their own language) and a notice on the
   dashboard until they open the wizard or DNS tab.

Needs a Cloudflare token with **Zone: Edit** and **Account Settings: Read**
next to DNS: Edit, and the account ID (Admin > System > DNS & mail server;
empty works when the token sees one account).

## Customer DNS records and the Cloudflare proxy

- Customers add, **edit** and remove their own A, AAAA, CNAME, MX and TXT
  records; panel records stay locked.
- The **Cloudflare** column switches the proxy per own record with one click.
  Names that carry mail or other non-web traffic (`mail`, `smtp`, `imap`,
  `pop`, `webmail`, `autoconfig`, `autodiscover`, `mta-sts`, `ftp`, `sftp`,
  `ssh`, anything starting with `_`) can never be proxied
  (`DnsRecord::mayProxy`).
- **Cloudflare proxy for the website** switches apex, www and alias records
  together (client and admin).
- Every RHIS-mail domain gets `mail.<domain>` A/AAAA records to this server,
  DNS only. MX, SRV and client settings still use the server's mail host
  name; moving them to `mail.<domain>` needs per-domain certificates in
  Postfix and Dovecot (SNI), which is a follow-up.

## Domains with DNS elsewhere: record check

When "Publish records to Cloudflare automatically" is off, the DNS tab lists
every record the domain needs and checks each one against the public DNS
(`Rhis\DnsMail\Dns\RecordCheck` through the server's resolver):
**Correct**, **Add this record** or **Change this record** (with what is
there now, e.g. an old web server's IP or a different SPF). The result is
stored in `mail_domains.dns_check`, refreshed by **Check now** and by
`dns-mail:check` every 6 hours, and shown as the "Own DNS" pill in the
domain lists.
