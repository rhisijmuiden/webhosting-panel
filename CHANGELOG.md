# Changelog

All notable changes to Dekwerk. Versions follow [Semantic Versioning](https://semver.org/).
The `main` branch is the production channel, `test` is the test channel.

## [1.0.0] - 2026-11-06

First production release. Everything below was built and tested on the test channel since 0.1.0.

### Server and installer
- Debian 13 server installer: Nginx with page cache, PHP 8.3-8.5, MariaDB, Valkey, Postfix, Dovecot, CrowdSec, nftables, hard disk quotas per site, automatic Let's Encrypt and Laravel support (#7, #8, #9).
- Panel updates provision the server: `install.sh --upgrade` plus numbered server migrations (#36).
- Updater keeps code readable for Nginx; fixed `whp_traffic` log format after updates and rollback of a failed site add (#28, #29).
- The release channel picked in the panel survives updates (#54).
- Server management: OS updates, reboot, maintenance window, critical CVEs, PHP end of life and scheduled tasks (#45).
- Monitoring and alerts: uptime per website, certificates, disks, services, Teams/mail notifications and status pages (#52).

### Websites and apps
- One-click app installer (WordPress, Joomla, Nextcloud) with a shared WP-CLI runner, and an app catalog with 6 new apps and update status (#10, #15, #42).
- WordPress toolkit for admins and customers, WP-CLI installed when missing (#12, #33).
- Git deployments from GitHub, GitLab, Bitbucket and Gitea (#18).
- Domain aliases, convertible into a website via the CRM (#21).
- Client file manager, code editor and web terminal (#22).
- Client subdomains and a website clone tool (#44).
- Node.js and Python apps next to PHP (#51).
- Package limits, resource jail per customer, PHP fine-tuning and extensions (#17).
- Subscriptions: a hosting package per website (#43).

### Migration
- Plesk to panel server migration with status page, live log and saved sources (#11, #27, #30, #32, #37).

### Security
- Configurable login protection, WordPress protection per site and allowlists (#13).
- Trusted IP addresses: never blocked, no two-factor code for admins (#31).
- Security alerts for customers and a security overview with customer risk (#16).

### DNS and mail
- DNS wizard to move customer domains to Cloudflare, customer DNS editing and own-DNS checks (#14).
- Cloudflare account API tokens accepted, Global API Key refused, clearer errors (#35).
- Admin mail log per customer with spammer signals (#19).
- Mail extras: autoresponder, catch-all and IMAP import (#50).

### Backups
- Customer backups to their own FTP, FTPS, SFTP and OneDrive locations, and an admin backup browser (#23, #48).
- Automatic restore tests for files and databases, isolated, with alerts (#53).

### Panel and UI
- New UI: dark menu, modern top bar, bento dashboard, Security center and client site cards (#20, #24, #25, #26).
- Client domain page with tabs, tools, quick links and subscription (#38).
- Admin Tools & settings overview, grouped sidebar, domain and service plan lists (#39).
- Phone-friendly panel: tab bar, tables as cards, Quick view (#40).
- Statistics: long-term graphs, visitors per site and customer, drag-to-zoom (#41).
- Monthly hosting report mail for customers (#47).
- Team members per customer and a client activity log (#49).

### CI
- One full install per PR, stale runs cancelled, apt cache; panel tests on every panel PR (#34, #46).

## [0.1.0]

Initial version: admin and client panel, server agent and statistics (#3), Cloudflare DNS sync, mail routing and SnappyMail webmail (#2), domains and mail screens (#4), spam quarantine, sender lists, DANE and BIMI (#6), backups and CRM API (#1, #5).

[1.0.0]: https://github.com/rhisijmuiden/webhosting-panel/releases/tag/v1.0.0
