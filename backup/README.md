# whp-backup

Backup engine of Webhosting Panel. A root CLI that the `whp-agent` calls with
`--json`; the panel never talks to restic or the remote storage itself.

- **restic** makes encrypted (AES-256), deduplicated, compressed snapshots.
- **rclone** carries them to the remote location: SFTP/SCP, FTP, FTPS
  (explicit or implicit TLS), Microsoft OneDrive / SharePoint, or a local or
  mounted path (NFS, USB disk).
- Every **target** is one restic repository with its own random key. Several
  targets can be active at the same time (for example a NAS over SFTP and
  OneDrive); `run` without `--target` backs up to all enabled targets.

## What is backed up

Every item is its own snapshot series, so it can be listed and restored on
its own (for example one customer's site, without touching the rest):

| Scope | Content |
| --- | --- |
| `system` | Panel config, Nginx, PHP, MariaDB, Postfix, Dovecot, Rspamd, certificates, CrowdSec, nftables, Unbound |
| `site:<domain>` | `/var/www/vhosts/<domain>` |
| `mail:<domain>` | `/var/vmail/<domain>` |
| `db:<name>` | Consistent dump of one MariaDB database (`--single-transaction`, routines, triggers, events), streamed straight into restic, no temporary file |

Paths and retention are set in `/etc/webhosting-panel/backup/backup.conf`
(see `etc/backup.conf`). Cache folders are skipped through `excludes.txt`.

## Full and incremental

- **Incremental** (`run --incremental`, default, daily 02:30): only changed
  files are read and uploaded.
- **Full** (`run --full`, weekly on Sunday 03:30): every file is read and
  hashed again instead of trusting the file metadata. That catches changes the
  metadata misses and starts a fresh chain point.

Because restic deduplicates, every snapshot (full or incremental) is a
complete restore point. Restoring never needs a chain of incrementals, and
deleting an old snapshot never breaks a newer one. The kind is stored as a tag
(`kind:full`, `kind:incremental`) and shown in the panel.

Retention (`KEEP_*`) is applied with `run --prune` or `prune`: by default the
last 3, 7 daily, 4 weekly and 6 monthly snapshots per series.

## Restore

```sh
whp-backup snapshots --target nas --scope site:example.nl
whp-backup restore --target nas --snapshot 1a2b3c4d --to /root/restore     # copy next to the live data
whp-backup restore --target nas --snapshot 1a2b3c4d --in-place             # put the site back as it was
whp-backup restore --target nas --snapshot 1a2b3c4d --in-place \
    --include /var/www/vhosts/example.nl/public/wp-config.php              # one file or folder
whp-backup restore --target nas --snapshot 9f8e7d6c --in-place --database example_copy  # db under another name
```

- An in-place restore first makes a `kind:pre-restore` snapshot of the
  current state, so the restore itself can be undone.
- In-place restores mirror the snapshot: files that were created after the
  backup are removed (only inside the restored path).
- Ownership and permissions are restored as they were.

### Disaster recovery on a new server

Store the repository key of every target somewhere safe (password manager):
`whp-backup target export-key <name>`. Without it the backup cannot be read.

On a freshly installed server:

```sh
whp-backup target add --name nas --type sftp --host nas.example.nl --user backup \
    --ssh-key /root/.ssh/id_ed25519 --path whp/web01 --repo-key-file /root/nas.key
whp-backup snapshots --target nas                       # everything from the old server
whp-backup restore --target nas --snapshot <system>  --to /root/old-system
whp-backup restore --target nas --snapshot <site>    --in-place
whp-backup restore --target nas --snapshot <db>      --in-place
```

## Targets

```sh
# SFTP / SCP (SSH). The host key is pinned on first use, or pass --known-hosts.
whp-backup target add --name nas --type sftp --host nas.example.nl --port 22 \
    --user backup --ssh-key /root/.ssh/whp_backup --path whp/web01

# FTPS (explicit TLS on 21, or --tls implicit on 990)
whp-backup target add --name ftps --type ftps --host ftp.example.nl --user backup \
    --password-file /root/ftp.pw --path whp

# Plain FTP sends the password unencrypted and must be allowed explicitly.
whp-backup target add --name oldftp --type ftp --allow-plain-ftp ...

# OneDrive: run `rclone authorize onedrive` on a computer with a browser and
# save the JSON it prints. For OneDrive for Business / SharePoint add
# --onedrive-drive-type business|documentLibrary --onedrive-drive-id <id>.
whp-backup target add --name onedrive --type onedrive --onedrive-token-file token.json --path Backups/web01

whp-backup target test nas
whp-backup target list
```

`scp` is accepted as a type and uses the SFTP protocol over SSH (every
OpenSSH server offers it); rclone and restic have no pure SCP transport.

Files per target in `/etc/webhosting-panel/backup/targets/<name>/` (all mode
600, directory 700): `target.conf`, `rclone.conf` (passwords obscured,
OneDrive token kept up to date by rclone), `repo.key`, `id_key`,
`known_hosts`.

## Schedules

Installed by `install.sh`:

| Timer | When | Command |
| --- | --- | --- |
| `whp-backup-incremental.timer` | daily 02:30 (±30 min) | `run --incremental --prune` |
| `whp-backup-full.timer` | Sunday 03:30 (±30 min) | `run --full --prune` |
| `whp-backup-check.timer` | 1st of the month 05:00 | `check --read-data` on every target (downloads 2% and verifies it) |

The panel changes the times with systemd drop-ins through the agent.

## JSON contract for the agent

Every command accepts `--json` and prints exactly one line on stdout:

```json
{"ok": true, "data": { ... }}
{"ok": false, "error": {"code": "invalid_params", "message": "..."}}
```

Error codes: `invalid_params`, `not_found`, `conflict`, `busy` (another job
runs; jobs never run in parallel), `unreachable`, `repo_error`,
`restore_failed`, `config_error`, `missing_dependency`, `unsupported`.

`status` returns the last run (`/var/lib/webhosting-panel/backup/last-run.json`)
and the targets; the dashboard uses it. Logs: `/var/log/webhosting-panel/backup.log`.

Secrets are never passed on the command line: passwords, keys and tokens
are given as files (`--password-file`, `--ssh-key`, `--onedrive-token-file`,
`--repo-key-file`), so they do not show up in `ps`.

## Tests

```sh
sudo WHP_TEST_DB=1 backup/tests/run.sh
# with a real SFTP server:
sudo WHP_TEST_SFTP=user@host:22 WHP_TEST_SFTP_KEY=~/.ssh/id_ed25519 backup/tests/run.sh
# with a real FTPS server:
sudo WHP_TEST_FTPS=user@host:21 WHP_TEST_FTPS_PASSWORD_FILE=pw backup/tests/run.sh
```

## Automatic restore tests

A backup is only proven when it has been restored. `whp-backup verify`
restores a sample of the newest restore points of every location into an
isolated scratch folder, checks them and removes them again. The panel runs
it on a schedule (Backups → Restore tests, default every Wednesday 06:00),
keeps the history and mails the administrators when a test fails.

```sh
whp-backup verify                                    # every enabled location, sample from backup.conf
whp-backup verify --target nas --sites 5 --databases 0 --kinds latest
whp-backup verify --target nas --scope site:example.nl --scope db:example_wp
```

Per location:

1. **Coverage**: every website, mail domain and database on this server must
   have a backup (failed when one has none) that is not older than
   `VERIFY_MAX_AGE_HOURS` (warning).
2. **Pick**: the server configuration plus `VERIFY_SITES` websites,
   `VERIFY_MAIL` mail domains and `VERIFY_DATABASES` databases, the ones tested
   longest ago first (`verify-history.json`), so a few runs cover everything.
   With `VERIFY_KINDS="both"` the newest restore point *and* the newest full
   one are tested, so both the incremental and the full chain are proven.
3. **Restore in isolation**: files go to `VERIFY_DIR`
   (`/var/lib/webhosting-panel/restore-test/run-*`, never a live path) with
   `restic restore --verify`, which checks the content of every file against
   its hash. Databases are imported into a private, throw-away MariaDB
   instance (own data directory, unix socket only, `--skip-networking`, client
   in `--sandbox` mode); the live MariaDB is never touched.
4. **Checks**: file count and total size equal the snapshot listing, symbolic
   links, random PHP files parse (`php -l`), wp-config.php readable, Maildir
   messages, configuration folders; dumps must end with the `-- Dump
   completed` marker, import without errors, have every table and pass
   `CHECK TABLE`.
5. **Clean up**: the scratch folder is removed and the private MariaDB stopped,
   also after a failure; leftovers of an interrupted run are removed at the
   start of the next one.

It holds the same lock as backups, so it never runs at the same time as a
backup or restore (the panel tries again 15 minutes later), runs with low CPU
and I/O priority, and skips an item (warning) when restoring it would leave
less than `VERIFY_MIN_FREE_PERCENT` of the disk free. The report is printed as
JSON and kept in `/var/lib/webhosting-panel/backup/verify-last.json`.

## Customer backups (own locations)

Separate from the server backups above, customers can back up their own
websites to their own FTP, FTPS or SFTP/SCP server, or to their own
Microsoft OneDrive, from the client panel (Backups → Add backup location). This is done by the agent
(`agent/whp_agent/actions/client_backup.py`), not by restic: the result is one
plain zip per backup that the customer can download, keep and upload again,
also on another server.

```
<domain>-20261106-030000-full.zip
<domain>-20261107-030000-incr-20261106-030000.zip   changes since that full backup
```

| In the zip | Content |
| --- | --- |
| `files/` | the website folder (`/var/www/vhosts/<domain>`, without `tmp/`, `sessions/`, `logs/`, caches) |
| `mail/` | `/var/vmail/<domain>` |
| `databases/<name>.sql` | dump of every database of the website |
| `whp-backup.json` | manifest: domain, kind, date, base backup, file list |

### OneDrive for customers

Customers connect their OneDrive with a "Connect with Microsoft" button; no
rclone or token pasting. This needs one Microsoft app for the whole panel,
registered once by the admin:

1. Microsoft Entra admin center → App registrations → New registration.
   Supported account types: *Accounts in any organizational directory and
   personal Microsoft accounts*. Redirect URI: platform **Web**,
   `https://<panel>/backups/onedrive/callback` (shown on the admin page).
2. Certificates & secrets → New client secret; copy the *value*.
3. Panel: Backups → Customer backups → *OneDrive for customers*: fill in the
   Application (client) ID and the secret.

The panel asks for `offline_access User.Read Files.ReadWrite` (PKCE + state),
reads the user's drive id, and stores the token encrypted with the location.
The agent gives rclone the token with the app's client id and secret; rclone
renews it, and the agent keeps the renewed token in
`/var/lib/webhosting-panel/client-backups/onedrive/t<id>.json` (0600) for the
next run. A new connection from the panel (other refresh token) replaces it.
OneDrive has no streaming upload, so rclone first spools each zip to a
temporary file on the server.

- **Full and incremental**: an incremental backup holds the files changed
  since the last full backup to the same location, all databases, and the
  list of files at that moment. Restoring it needs only that full backup,
  never a chain. Schedules: daily (full on one weekday, changes the other
  days) or weekly (full), run by `php artisan backups:client-tick` (every 5
  minutes from the panel scheduler). Retention: the newest N full backups
  and the incrementals on top of them stay on the location.
- **Restore** from the location, or from an uploaded zip (the browser uploads
  it in 8 MB chunks to `/var/lib/webhosting-panel/uploads`). Files are
  mirrored (files that are not in the backup are removed); mail is only
  added (new mail stays, messages already there are not doubled); databases
  get the tables of the dump, newer tables are removed. Optionally a full
  backup of the current state is made first.
- **Security**: files are read and written as the website's own Linux user
  (mail as `vmail`) by `agent/whp_agent/site_worker.py`, never as root.
  A dump is imported as the database's own MariaDB user (its password is
  changed for the import and put back afterwards), with `DEFINER` clauses
  removed, so a crafted `.sql` cannot reach other databases. Uploaded zips
  are checked before anything is extracted (paths, sizes, compression ratio,
  manifest). Host names of locations may not point to private or local
  addresses (set `CLIENT_BACKUP_ALLOW_PRIVATE=yes` in
  `/etc/webhosting-panel/client-backup.conf` to allow them); SSH host keys
  are pinned on the first test. Passwords are stored encrypted in the panel
  database and handed to the agent per call.
- Zips are not encrypted at rest: use SFTP or FTPS so they are encrypted
  on the way, and keep the backup server private.

## Admin backup browser

Backups → Browse by date shows every restore point of a location per day.
Pick a day, then select single items, a whole customer (websites, their
databases and mail are grouped by customer) or everything, and restore them
in place or as a copy to `/root/restore/<date>`. It runs as one agent job
(`backup.restore_many`): server configuration first, then websites, mail and
databases; a failed item does not stop the others. Backups → Customer
backups lists the locations and the history of all customer backups.
