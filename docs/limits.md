# Package limits, resource jail and PHP settings

## Limits of a package

Every limit of a hosting package is `NULL` for **unlimited** (the default for new
packages). A package is assigned **per website** through its subscription (see
[subscriptions.md](subscriptions.md)), so every limit applies to one website.
Usage limits: disk space, data traffic per month, mailboxes, databases, cron
jobs. The CRM API reads and writes the same columns (`null` = unlimited).

## Resource jail per subscription

Subscription 12 (one website) runs in `whp-sites-s12.slice` (below
`whp-sites.slice`, below `whp.slice`):

```
whp.slice
└── whp-sites.slice            MemoryHigh=90 %: sites never take the last 10 % from MariaDB/mail/panel
    ├── whp-sites-s12.slice    CPUQuota, MemoryHigh/Max, IO bandwidth, TasksMax from the package
    │   ├── whp-site-shop_nl.service      (PHP-FPM master of the site)
    │   ├── wp-cron / WP-CLI units of the site
    └── whp-sites-s13.slice
```

- No caps (unlimited): every subscription slice has the same CPU and IO weight, so
  under load each website gets an equal share, however many processes it starts.
- Caps: `whp-site slice s12 --cpu 200% --memory 2048 --io 50 --tasks 512`
  (agent `subscription.limits`; the older per-customer `c<id>` slices and
  `customer.limits` still work, installer migration 0003 removes unused ones). Memory throttles at 90 % and stops the heaviest
  process at 100 %. IO is limited on the disk that holds `/var/www/vhosts`.
- SSH/SFTP sessions of a site user (logind, `user-<uid>.slice`) get the same
  caps through a drop-in.
- PHP workers per website: `pm.max_children` (package, else the server default).
- MariaDB: `MAX_USER_CONNECTIONS` for every database user of a website
  (agent `db.limits`), also for databases created later.

The panel applies this when a package changes, when a subscription gets another
package, when a website is created and on every deploy (`php artisan whp:limits:apply`,
optionally one subscription id or `--customer=<id>`).

## Data traffic

Nginx writes `$server_name $bytes_sent $request_length` for every request
(static files included) to `/var/log/nginx/whp-traffic.log`. The agent reads the
new part every 15 minutes (`traffic.report`, follows logrotate by inode) and
keeps totals per site per month. The panel stores them in `website_traffic`.
At the package limit of a subscription the admins get an e-mail; with "suspend"
that website is suspended until the first of next month or until the admin
clicks "Put online now". Mail and FTP traffic are not counted.

## PHP settings

Admin → PHP settings → Fine-tuning: a default and a customer maximum for
`memory_limit`, `max_execution_time`, `max_input_time`, `max_input_vars`,
`post_max_size`, `upload_max_filesize`, the default number of workers and
whether customers may switch `display_errors` on.

Customers set their own values per website up to the maximum. `whp-site phpini`
writes them as `php_admin_value` (scripts cannot raise them with `ini_set`) and
writes `/etc/nginx/whp/sites/<domain>/10-php-limits.conf` with the matching
`client_max_body_size` and `fastcgi_read_timeout`; the FPM
`request_terminate_timeout` follows the longest PHP time limit.

## PHP extensions

Admin → PHP settings → Extensions, per PHP version:

- Debian packages (`php8.4-<name>`, Debian and Sury): recommended, updated with
  the normal security updates.
- PECL: compiled with `php-config` of that version (`php8.4-dev`,
  `build-essential` are installed when needed), enabled with `phpenmod`, and
  switched off again when PHP does not load it. Not updated automatically.

After a change the PHP-FPM units of all websites on that version are reloaded.
