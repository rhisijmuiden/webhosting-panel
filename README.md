# Webhosting Panel

A hosting control panel for Debian 13, built to replace Plesk: websites with
Nginx and PHP-FPM, MariaDB, mail, Let's Encrypt, CrowdSec, backups, and an API
for the RHIS CRM.

| Folder | What |
| --- | --- |
| `installer/` | Debian 13 install script |
| `panel/` | Laravel admin and client panel ([README](panel/README.md)) |
| `agent/` | `whp-agent`, the root daemon that applies changes for the panel |
| `packages/` | Panel modules (DNS and mail, backups, CRM API) |
| `brand/` | RHIS house style used by the panel |
| `docs/` | Architecture and API documentation |

Branches: `main` is production, `test` is the test channel. The panel can switch
between them and update itself from the Info dialog.

How the parts talk to each other: [docs/architecture/interfaces.md](docs/architecture/interfaces.md).
