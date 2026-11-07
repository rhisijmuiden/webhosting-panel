# Extra FTP/SFTP accounts per website

Customers (and admins) add extra logins to a website, for example for a web
designer, a photographer or a backup program. Every account

- is locked in **one folder** of its website (the whole website, `public`,
  `public/wp-content/uploads`, ...); a folder that does not exist yet is created,
- can be **read only** (download and browse; no upload, rename, delete, chmod),
- works with **SFTP** (port 2222) and **FTPS** (port 21, explicit TLS; plain FTP
  is refused),
- writes files as the website's own Linux user, so ownership is right for PHP
  and uploads count for the website's disk quota.

The main SFTP/SSH account of a website (`s_<domain>` on OpenSSH, port 22) is
not changed.

## Server

`installer/modules/75-ftp.sh` (idempotent, runs on every panel update):

| What | Value |
| --- | --- |
| Packages | `proftpd-core`, `proftpd-mod-crypto` (mod_tls, mod_sftp) |
| Config | `/etc/proftpd/proftpd.conf`, shared part `/etc/proftpd/whp/common.conf` (templates in `installer/templates/proftpd/`) |
| Accounts | `/etc/proftpd/whp/ftpd.passwd` (`login:hash:uid:gid::home:/usr/sbin/nologin`, 0600), `/etc/proftpd/whp/ftpd.group` (group `whp-ftp-ro` = read-only accounts) |
| Logins | Virtual users only (`AuthOrder mod_auth_file.c`): Linux users such as root can never log in to ProFTPD |
| Chroot | `DefaultRoot ~`: the account's folder |
| Ports | FTPS `WHP_FTP_PORT` (21), SFTP `WHP_SFTP_PORT` (2222), passive 49152-49407 (saved in `installer.env`, opened in `/etc/nftables.d/whp-ftp.nft`) |
| TLS | The server's Let's Encrypt certificate (the panel host name); the certbot deploy hook reloads ProFTPD |
| SFTP host keys | Own keys in PEM format, `/etc/proftpd/whp/ssh_host_{ecdsa,rsa}_key` (shown in the panel under How to connect) |
| Brute force | ProFTPD logs to the journal; CrowdSec reads `proftpd.service` (`acquis.d/whp-ftp.yaml`, collection `crowdsecurity/proftpd`) |

## Agent

`agent/whp_agent/actions/ftp.py`:

- `ftp.sync {accounts: [{login, domain, password_hash, folder, read_only}]}`: the
  complete list. Writes both files atomically. `login` must be `<name>@<domain>`,
  `password_hash` a SHA-512 crypt (`$6$`) or yescrypt (`$y$`) hash, `folder` a
  path below `/var/www/vhosts/<domain>` without `..`. Accounts of suspended or
  unknown websites are left out (`skipped` with a reason), as are folders that
  resolve outside the website. Removed accounts lose their open sessions
  (`ftpdctl kick user`). ProFTPD reads the files at every login: no reload.
- `ftp.status`: `installed`, `active`, ports, number of accounts and the SFTP host key fingerprints.

## Panel

- Table `ftp_accounts` (`App\Models\FtpAccount`), service `App\Services\FtpAccounts`,
  `App\Providers\FtpAccountsServiceProvider`. Only the hash is stored and sent;
  a generated password is shown once.
- Screens: `/ftp-accounts` (client, team area "Files") and `/admin/ftp-accounts`,
  plus the card "FTP accounts" on the Hosting & DNS tab of every domain page
  (views in `resources/views/ftp-accounts/`, NL in `lang/features/ftp-accounts/nl.json`).
- Every change, a suspended/reactivated website and a removed website send the
  complete list with `ftp.sync`. When the agent cannot be reached the change is
  retried every 5 minutes (`whp:ftp:sync --pending`); `whp:ftp:sync` also runs hourly.
- Limit per website: CRM plan limit `ftp_accounts`, subscription override
  `limit_overrides.ftp_accounts`, else `WHP_FTP_DEFAULT_LIMIT` (empty = unlimited).
  Admins are not limited. `WHP_FTP_CUSTOMER=false` makes the screens read only for customers.
