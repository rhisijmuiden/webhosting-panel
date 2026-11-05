# Serverinstaller (Debian 13)

*English: [installer.md](installer.md)*

De installer maakt van een verse **Debian 13 (Trixie)**-server (fysiek of VM) een
veilige, snelle hostingserver voor het Webhosting Panel. Opnieuw draaien is veilig:
beheerde bestanden worden herschreven, data en wachtwoorden blijven bewaard.

## Installeren

```bash
# productiekanaal (branch main)
curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/main/installer/bootstrap.sh | sudo bash

# testkanaal (branch test), zonder vragen
curl -fsSL https://raw.githubusercontent.com/rhisijmuiden/webhosting-panel/test/installer/bootstrap.sh \
  | sudo bash -s -- --channel test -y --hostname server1.voorbeeld.nl --email jij@voorbeeld.nl
```

Of vanuit een checkout: `sudo installer/install.sh`. Alle opties: `installer/install.sh --help`.
Meldingen zijn in het Engels, of in het Nederlands met `--lang nl` (of een `nl_*`-locale).

Vereisten: Debian 13, amd64 of arm64, 2 GB RAM (4 GB met ClamAV), 20 GB schijf, een
publiek IPv4-adres en een A-record voor de hostnaam (voor Let's Encrypt).

## Wat wordt er geïnstalleerd

| Onderdeel | Versie / bron | Toelichting |
| --- | --- | --- |
| Nginx | Debian 13 | FastCGI-paginacache, HTTP/2, HTTP/3 (QUIC), Brotli + gzip, alleen TLS 1.2/1.3, onbekende hosts krijgen geen antwoord |
| PHP-FPM | 8.3, 8.4, 8.5 (Sury) | Naast elkaar; elke website eigen gebruiker, pool en socket, OPcache + JIT |
| MariaDB | 11.8 LTS (Debian) | Alleen localhost, root alleen via unix-socket, buffer pool afgestemd op RAM |
| Valkey | 8 (Debian) | Redis-compatibel, alleen localhost, **ACL's met wachtwoorden** (klant-PHP draait op dezelfde server) |
| Postfix | Debian | Relayserver optioneel (gecontroleerde TLS + SASL), submission 587 en 465 |
| Dovecot | 2.4 (Debian) | IMAPS 993, ManageSieve 4190, Sieve, LMTP; gewone IMAP 143 alleen op loopback |
| Rspamd | upstream | Spamfilter met greylisting, Bayes in Valkey, DKIM/ARC-ondertekening, DMARC |
| ClamAV | Debian | Mailscan via Rspamd, dagelijkse scan van websites met php-malware-finder YARA-regels |
| CrowdSec | upstream | Herkent aanvallen op SSH/Nginx/WordPress/Postfix/Dovecot, blokkeert in nftables, community-blocklist |
| nftables | Debian | Standaard alles dicht, alleen hostingpoorten open, rem op SSH-brute-force |
| Unbound | Debian | Lokale validerende resolver (nodig voor DNS-blocklists) |
| Certbot | Debian | Let's Encrypt voor de hostnaam en elke website, automatisch verlengd |

Open poorten: SSH, 80, 443 (TCP + UDP voor HTTP/3), 8443 (panel), 25, 465, 587, 993, 4190.

Verder: automatische beveiligingsupdates, kerneltuning (BBR, fq, SYN-cookies, hardening),
SSH-beveiliging (alleen sleutels, alleen als er een sleutel is), begrensde journald,
Engelse en Nederlandse locales, Composer.

## Websites: `whp-site`

```bash
whp-site add voorbeeld.nl                       # PHP 8.4, www-alias, paginacache, Let's Encrypt
whp-site add winkel.voorbeeld.nl --php 8.3 --profile wordpress
whp-site add app.voorbeeld.nl --profile laravel  # code in app/, geserveerd vanuit app/public
whp-site php voorbeeld.nl 8.5                    # PHP-versie wisselen
whp-site cache voorbeeld.nl off
whp-site quota voorbeeld.nl 5120                 # harde limiet van 5 GB
whp-site ssl voorbeeld.nl                        # Let's Encrypt nu opnieuw proberen
whp-site list --json
whp-site remove voorbeeld.nl
```

Elke website staat in `/var/www/vhosts/<domein>/`, draait als eigen Linux-gebruiker en
is afgeschermd met `open_basedir`. Let's Encrypt wordt automatisch aangevraagd; staat DNS
nog niet goed, dan blijft de site op HTTP en probeert een timer het elk uur opnieuw.

**Paginacache**: anonieme GET-verzoeken worden 10 minuten gecachet. Ingelogde
WordPress/WooCommerce-gebruikers, winkelwagens, beheerpagina's, POST-verzoeken en
querystrings nooit. De header `X-Cache` toont `HIT`, `MISS` of `BYPASS`.

**Laravel**: profiel `laravel` zet de docroot op `app/public`, zet de paginacache uit en
staat `proc_open` toe. Composer is geïnstalleerd.

## Schijfquota

Harde limieten per website vereisen projectquota op het bestandssysteem van `/var/www`.
Een gemount root-bestandssysteem kan daar niet live naar omgezet worden, dus de
aanbevolen opzet is een aparte dataschijf:

```bash
installer/install.sh --quota-device /dev/sdb   # LEGE schijf: wordt XFS met prjquota, gemount op /var/www
```

De installer formatteert nooit een schijf met partities of een bestandssysteem. Zonder
projectquota wordt de limiet opgeslagen en gerapporteerd maar niet afgedwongen (de
installer waarschuwt; met `--require-quota` stopt hij).

## Overige tools

| Commando | Wat |
| --- | --- |
| `whp-status [--json]` | Status van alle beheerde diensten |
| `whp-cert <domein> [namen]` | Let's Encrypt-certificaat aanvragen |
| `whp-malware-scan <domein>\|--all` | ClamAV + YARA-scan, JSON-rapport in `/var/lib/webhosting-panel/malware/` |

Bestanden en conventies: zie [installer.md](installer.md#files) en
[architecture/server-layout.md](architecture/server-layout.md).
