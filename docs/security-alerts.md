# Security alerts and security overview

Package `packages/vulnerabilities` (`Rhis\Vulnerabilities\`) and agent module `agent/whp_agent/actions/sitescan.py`.

## What it does

* Every night (`whp:vuln:scan`, 05:15, after the WordPress scan at 04:15) the versions of WordPress core,
  plugins and themes that the WordPress toolkit stored are matched against a vulnerability database.
  The ClamAV / php-malware-finder report of each site (`whp-malware-scan`, 03:30) is read too.
* Each hit becomes a row in `security_findings` (severity, CVSS, CVE, fixed version, advice). A finding that is
  no longer seen (the customer updated) is closed automatically; right after a WordPress toolkit update the
  install is checked again straight away.
* Each customer gets **one** RHIS branded mail (HTML + text, in the customer's language) with every problem,
  how to fix it and a button to the one-click update in the client panel. Reminder after 7 days, at most 3
  (configurable). Nothing is ever updated automatically.
* Admin: **Server > Security overview** (`/admin/security-overview`): server score, open problems by severity,
  customers most at risk, all problems with filters, mail preview, send now, ignore.
* Customer: **Security alerts** (`/security-alerts`): their problems with the fix, and who receives the mails.

## Vulnerability sources

| Source | Key | How |
| --- | --- | --- |
| WPVulnerability.net (default) | none | `GET https://www.wpvulnerability.net/{plugin,theme}/<slug>/` and `/core/<version>/`, cached 12 h |
| Wordfence Intelligence (recommended) | free API key, wordfence.com > Account > Integrations | v3 production feed (~160 MB) downloaded nightly at 03:45 (`whp:vuln:sync`), stream-parsed into `/var/lib/webhosting-panel/vulndb/wordfence.sqlite`; matching is local |

Wordfence records carry copyright notices (Defiant, MITRE); they are printed in the mail footer as the licence requires.

## Agent actions

`vuln.match`, `vuln.status`, `vuln.sync` (job), `malware.report`, `malware.scan` (job), `posture.get`
(pending apt updates, reboot needed, sshd settings, CrowdSec/nftables/ClamAV state, signature age, last malware scan).

## Risk score

Per open finding: critical 40, high 20, medium 8, low 2 (×1.25 when there is no fix yet), malware 50, plus 1 per
outdated part without a known hole. Capped at 100.
