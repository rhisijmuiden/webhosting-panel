# rhis/certificates

SSL certificates per website, for administrators (`/admin/certificates`) and
customers (`/certificates`, team area "Security"). See `docs/certificates.md`.

- **Let's Encrypt** (default): HTTP-01 by `whp-site`, renewed by certbot.
- **Wildcard**: `<domain>` + `*.<domain>` through a DNS-01 challenge at
  Cloudflare (`installer/bin/whp-cert-dns`, certbot-dns-cloudflare). Uses the
  Cloudflare token of the DNS & mail settings. A subdomain website uses the
  wildcard of its parent website when that covers it.
- **Own certificate**: PEM (certificate, key, CA bundle, optional key password)
  or PFX/PKCS#12. The panel checks key/certificate match, expiry, RSA key size,
  coverage of the domain and aliases, and orders the chain (root left out).
  A CSR with its key can be made in the panel; the key is stored encrypted and
  used when the signed certificate is uploaded.
- **Expiry warnings**: `certificates:check` runs daily (`certificates.check_at`),
  reads every certificate from the server (`ssl.list`) and mails admins (all
  websites) and customers (their own certificates) at 30/14/7/3/1 days, once per
  step. Let's Encrypt certificates only warn at 10/5/2/1 days (renewal failing).

Agent actions: `ssl.info`, `ssl.list`, `ssl.custom`, `ssl.wildcard`,
`ssl.letsencrypt`, `ssl.cloudflare` (agent/whp_agent/actions/certificates.py).
