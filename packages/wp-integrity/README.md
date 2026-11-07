# rhis/wp-integrity

WordPress integrity check for admins and customers.

- Every night (`wp-integrity:check`, 04:45, after the malware scan and the
  WordPress scan) the agent runs `wp core verify-checksums` and
  `wp plugin verify-checksums --all` as the site user and reports files that are
  changed, missing or should not be there. Themes and plugins that are not on
  WordPress.org have no checksums; they are listed as "cannot be verified".
- Hits of the nightly malware scan (`whp-malware-scan`) inside the install are
  linked to the changed files; malware elsewhere (uploads, themes) is listed apart.
- "Restore" downloads the original core or plugin zip from downloads.wordpress.org
  as the site user (`agent/whp_agent/wp_integrity_worker.py`), puts the original
  files back and moves files that should not be there to
  `<site root>/quarantine/wordpress-integrity/<time>/` (outside the docroot, 0700).
  Afterwards the files are checked again and, when malware was involved, the site
  is scanned for malware again.
- Changes can be marked as intended (stored by path + md5, so a later change counts
  again). Malware and missing files cannot be marked.
- Changed parts become findings (`kind = integrity`) in the Security center and the
  customer's security alerts (rhis/vulnerabilities), so they also reach the
  security alert mail. Core changes are `high`, plugin changes `medium`, anything
  with malware `critical`.

Screens: admin `/admin/wordpress-integrity` (menu "File integrity" under Server
management), client `/wordpress-integrity` (linked from the WordPress page and the
security alerts). Agent actions: `wpint.check`, `wpint.report`, `wpint.restore`.

Settings (panel.env): `WHP_WP_INTEGRITY_CUSTOMER`, `WHP_WP_INTEGRITY_AT`,
`WHP_WP_INTEGRITY_FINDINGS`.
