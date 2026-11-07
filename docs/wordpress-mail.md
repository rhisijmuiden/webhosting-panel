# WordPress mail through this server

Contact forms, web shop orders and password mails of WordPress go through the
mail server of this server, from an address of the website and signed with DKIM.

## Route of a message

```
wp_mail() -> PHPMailer (mu-plugin whp-mail) -> SMTP 127.0.0.1:10587 (postfix/website)
          -> Rspamd: DKIM signature (selector whp, key of the From domain) -> recipient
```

- **Postfix service** `127.0.0.1:10587` (`installer/modules/61-website-mail.sh`, called
  from `module_mail`): loopback only, relays for `mynetworks`, no HELO or
  "sender domain resolves" checks (new sites often have no DNS yet), logs as
  `postfix/website`. The mail log shows these messages with origin *website*.
- **Why SMTP and not PHP's sendmail**: Postfix' `sendmail` needs the setgid
  `postdrop`, which is blocked under `NoNewPrivileges`. WP-CLI and the system
  WP-cron run that way, so mail from scheduled tasks used to fail.
- **mu-plugin** `wp-content/mu-plugins/whp-mail.php` (source:
  `agent/whp_agent/data/whp-mail.php`) with its settings in
  `wp-content/mu-plugins/whp-mail/config.php`:
  - `wordpress@...` (the WordPress default) becomes the chosen sender
    (default `noreply@<website domain>`); a sender name fills in "WordPress".
  - "Always send from this website" (default on): a From in a domain that is
    not one of the website's DKIM domains (a form "from" the visitor) becomes
    the website's address, the original sender becomes Reply-To.
  - Envelope sender = From, so SPF and DMARC align; header `X-Website`.
  - Runs last on `phpmailer_init`, so it also wins over SMTP plugins that use
    WordPress' mailer.
  - Logs every message as a JSON line in `<site root>/logs/wp-mail.log`
    (shown as "Recent mail"; rotated at 1 MB).

## Copies and staging

- The settings are bound to the folder of the install (`realpath(ABSPATH)`):
  in a clone, staging copy or restore elsewhere the plugin does nothing.
- `site.clone` and the staging push leave the plugin and its settings out.
- A staging copy (`whp-staging.php`) holds all mail back (`pre_wp_mail`), and
  `wp.mail_apply` refuses to switch it on.

## Panel

- Per install: **WordPress → install → Website mail** (`/admin/wordpress/{id}/mail`,
  `/wordpress/{id}/mail`): on/off, sender address (website domain or another
  mail domain of the customer with a DKIM key), sender name, "always send from
  this website", deliverability checks (DKIM key, server in SPF, DNS), test
  message (sent with `wp_mail()` with all plugins, shows the Postfix queue ID)
  and recent mail. A card on the install page and a line in the WordPress tab
  of the website page link to it.
- `whp:wordpress:mail-sync` (every 15 minutes) switches new installs over,
  except staging copies and installs with an active SMTP plugin
  (`config/wp-mail.php` → `smtp_plugins`). Installs found while the server had
  no website mail service yet are tried again after the update.

| Setting (panel.env) | Default | Meaning |
|---|---|---|
| `WHP_WP_MAIL_AUTO` | `true` | Switch new installs over automatically |
| `WHP_WP_MAIL_FROM` | `noreply` | Local part of the default sender |
| `WHP_WP_MAIL_CUSTOMER` | `true` | Customers may change the sender and send tests |

## Agent actions

`wp.mail_status`, `wp.mail_apply`, `wp.mail_test` in `agent/whp_agent/actions/wp_mail.py`.
Tests: `agent/tests/test_wp_mail.py` (including the plugin itself under PHP),
`panel/tests/Feature/WordPressMailTest.php`, Debian 13 smoke `tests/wp_mail_smoke.py`.
