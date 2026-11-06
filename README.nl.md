# Webhosting Panel

Eigen hostingpanel voor Debian 13: een snelle, veilige web- en mailserver met een
beheer- en klantpanel, gekoppeld aan het RHIS-CRM.

*English: [README.md](README.md)*

## Server installeren

```bash
curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/main/installer/bootstrap.sh | sudo bash
```

Zie [docs/installer.nl.md](docs/installer.nl.md).

## Branches

| Branch | Kanaal | Gebruik |
| --- | --- | --- |
| `main` | productie | Wat servers op het productiekanaal draaien |
| `test` | test | Nieuw werk komt hier eerst, daarna naar `main` |

Wisselen van kanaal kan in het panel (Info-scherm) of met `--channel test` bij installatie.
