# rhis/disk-usage

Disk usage explorer for the Webhosting Panel: which folders, databases and
mailboxes take up the space of a website, with drill-down into folders.

* Client: **Files & data → Disk usage** (`/disk-usage`). Team members need the
  *Files* area; database and mailbox names only show with the *Databases* and
  *Email* areas.
* Admin: **Hosting services → Disk usage** (`/admin/disk-usage`): every website
  sorted by use, totals, search and a "nearly full" filter.

## How it works

* `agent/whp_agent/diskscan.py` walks the website root in a separate process
  with `nice -n 15` and `ionice -c 3` (idle I/O). It only reads metadata, never
  follows symlinks (every folder is opened with `O_NOFOLLOW` relative to its
  parent), stays on one filesystem and counts hard links once. Sizes are
  blocks on disk, the same number the disk quota counts.
* The tree is pruned (60 largest sub folders per folder, 10 levels, 5000
  folders in total; the rest is summed as "smaller folders") and stored in
  `/var/lib/webhosting-panel/disk-usage/<domain>.json` (root, 0600).
* The panel asks `disk.usage` for one folder at a time; the first visit of a
  website starts its first scan, the page refreshes until it is ready.
  "Scan again" starts a new one. The scheduler runs `disk-usage:scan` every
  night (03:40), which rescans every website whose last scan is older than 20 hours.
* Databases come from MariaDB (`information_schema`), mailboxes from the usage
  Dovecot reports (`mailboxes.used_kb`, refreshed by the dns-mail package).
* The disk quota covers **files only** (the main website and its subdomains);
  databases and mailboxes have their own limits.
* Well-known folders get a hint (cache, backups inside the site, logs, uploads,
  vendor/node_modules) and large archives are flagged as possible old backups.

## Settings (`panel.env`)

| Variable | Default | |
| --- | --- | --- |
| `WHP_DISK_USAGE_CUSTOMER` | `true` | Show the explorer to customers |
| `WHP_DISK_USAGE_MAX_AGE_HOURS` | `20` | Nightly scan skips newer scans |
| `WHP_DISK_USAGE_SCAN_AT` | `03:40` | Time of the nightly scan |
| `WHP_DISK_USAGE_WARN_PERCENT` | `85` | "Nearly full" threshold |

No server changes are needed: the agent action ships with the code and
`nice`/`ionice` are part of every Debian install.
