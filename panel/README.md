# Panel

Laravel 12 admin and client panel of the Webhosting Panel, in the RHIS house style.

- Admin: dashboard, statistics, customers, packages, websites, services, CrowdSec,
  firewall, mail server (SMTP), updates (production/test channel), activity log.
- Client: overview, websites (PHP version, page cache).
- Login with TOTP two-factor authentication, required for admins.
- English by default, Dutch included; each user picks their own language.
  Add a language by dropping `lang/<code>.json` (and optionally `lang/<code>/validation.php`).
- Light and dark theme, works on phones.

No Node.js build: CSS and JS are plain files in `public/assets/` (Alpine.js and
qrcode-generator are vendored there), so a server update only needs Composer.

## Run it locally

```bash
cp .env.example .env
# WHP_AGENT_DRIVER=fake gives demo data without a server agent
sed -i 's/WHP_AGENT_DRIVER=socket/WHP_AGENT_DRIVER=fake/; s/WHP_REQUIRE_ADMIN_2FA=true/WHP_REQUIRE_ADMIN_2FA=false/' .env
composer install
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed       # admin@example.test / password
php artisan serve
```

## Tests

```bash
php artisan test          # panel
cd ../agent && python3 -m unittest discover -s tests   # agent
```

## On the server

The installer runs `deploy/install.sh`, which installs Composer packages, runs
migrations and enables `whp-queue.service` and `whp-scheduler.timer`. Create the
first admin with `php artisan whp:admin you@example.com`. Updates run from the
Info dialog or the Updates page through `agent/scripts/update.sh`.
