# Webhosting Panel

Own hosting control panel for Debian 13: a fast, secure web and mail server with an
admin and client panel, integrated with the RHIS CRM.

*Nederlands: [README.nl.md](README.nl.md)*

## Install a server

```bash
curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/main/installer/bootstrap.sh | sudo bash
```

See [docs/installer.md](docs/installer.md).

## Branches

| Branch | Channel | Use |
| --- | --- | --- |
| `main` | production | What servers on the production channel run |
| `test` | test | New work is merged here first, then released to `main` |

Switching channel is done from the panel (Info screen) or with `--channel test` on install.
