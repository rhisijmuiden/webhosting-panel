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
