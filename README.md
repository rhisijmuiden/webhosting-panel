# Webhosting Panel

A hosting control panel for Debian 13, built to replace Plesk: a fast, secure web
and mail server (Nginx and PHP-FPM, MariaDB, mail, Let's Encrypt, CrowdSec), with
an admin and client panel, backups, and an API for the RHIS CRM.

*Nederlands: [README.nl.md](README.nl.md)*

## Install a server

```bash
curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/main/installer/bootstrap.sh | sudo bash
```

See [docs/installer.md](docs/installer.md).

## Layout

| Folder | What |
| --- | --- |
| `installer/` | Debian 13 install script |
| `panel/` | Laravel admin and client panel ([README](panel/README.md)) |
| `agent/` | `whp-agent`, the root daemon that applies changes for the panel |
| `packages/` | Panel modules (DNS and mail, backups, CRM API) |
| `brand/` | RHIS house style used by the panel |
| `docs/` | Architecture and API documentation |

How the parts talk to each other: [docs/architecture/interfaces.md](docs/architecture/interfaces.md).

## Branches

| Branch | Channel | Use |
| --- | --- | --- |
| `main` | production | What servers on the production channel run |
| `test` | test | New work is merged here first, then released to `main` |

Switching channel is done from the panel (Info screen) or with `--channel test` on install.
