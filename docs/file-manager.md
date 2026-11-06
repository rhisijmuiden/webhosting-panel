# File manager, code editor and web terminal

Customers manage the files of their own websites from the client panel
(**File manager** in the menu, `/files`). Admins can open any website from
`/admin/files`. The code lives in `packages/files` (panel), `agent/whp_agent/actions/files.py`,
`agent/whp_agent/fileworker.py` and `agent/whp_agent/actions/terminal.py` (agent).

## What a customer can do

- Browse the website root (`/var/www/vhosts/<domain>`: `public/` or `app/public`, `logs/`, `tmp/` …), sort, search by name, show or hide dotfiles.
- Upload files and whole folders, by button or drag and drop. Uploads go in chunks of 8 MB, so PHP's upload limits do not matter (default maximum 4 GB per file, `WHP_FILES_MAX_UPLOAD_MB`).
- Download files, and folders as a zip.
- New folder, new file, rename, copy, cut and paste, move, delete, permissions (chmod with a rwx grid).
- Compress to `.zip` or `.tar.gz`, unpack `.zip`, `.tar`, `.tar.gz`, `.tgz`, `.tar.bz2`, `.tar.xz` (as background jobs with progress).
- Edit text files in a code editor (CodeMirror 6): syntax highlighting for PHP, HTML, CSS, JavaScript/TypeScript, JSON, SQL, XML, YAML, Markdown, Python, shell, Nginx/INI/.env/.htaccess; search and replace, multiple cursors, bracket matching, code folding, word wrap, Ctrl+S. Saving checks that nobody changed the file in the meantime.
- Optional web terminal (see below).

## Security

- The panel (user `whp`) never touches customer files. It asks whp-agent, and the agent
  starts `fileworker.py` **as the website's own Linux user** (no extra groups, nice + idle
  I/O). The kernel enforces ownership, permissions and the disk quota.
- The worker resolves every path inside the site root: `..`, absolute paths and symlinks
  that point outside are refused. A symlink itself can be deleted, never followed out.
- `logs`, `tmp`, `sessions` and the document root folder cannot be deleted, renamed or moved.
- chmod refuses setuid/setgid/sticky bits and world writable modes.
- Unpacking skips absolute paths, `..` (zip slip), links and device files, and stops at
  20 GB or 200,000 entries (zip bombs).
- File contents travel through `/var/lib/webhosting-panel/files-spool` (owner `whp`,
  0700, cleaned by systemd-tmpfiles after a day). The agent opens spool files without
  following symlinks and only accepts plain files owned by `whp`, and hands the open
  file descriptor to the worker; the site user never sees the spool folder.
- Customers only see their own websites, background jobs are bound to the user who
  started them, and edits, uploads, deletes, unpacking and terminal sessions are in the
  activity log.

## Web terminal

Shown only when the customer's hosting package has **SSH access** (`allow_ssh`) and the
server has `ttyd` and `bubblewrap` (installed by the installer, `installer/modules/10-base.sh`).
Turn it off for everyone with `WHP_WEB_TERMINAL=false` in `panel.env`.

- `terminal.start` runs ttyd as the site user in a transient unit `whp-term-<token>`
  (sites slice, 1 GB memory, 256 tasks, maximum lifetime `WHP_WEB_TERMINAL_MINUTES`,
  default 60), one client only.
- ttyd starts bash inside a bubblewrap sandbox: `/usr` and a few files from `/etc` are
  read-only, only the site root is writable, `/tmp` is private, and other websites,
  `/home`, `/root`, `/etc/webhosting-panel` and `/var/lib/webhosting-panel` do not exist
  inside. Network stays on (Composer, Git); PHP, Composer, WP-CLI, Git and the MariaDB
  client work.
- Nginx on :8443 proxies `/terminal/<token>/` to `/run/whp-terminal/<token>/ttyd.sock`
  after an `auth_request` to the panel (`/terminal/auth`): only the logged-in user who
  started the session (and passed two-factor) gets in.

Servers installed before the file manager need the installer once more
(`installer/install.sh -y`) for ttyd, bubblewrap and the Nginx terminal proxy. The file
manager itself works straight after a panel update.

## Rebuilding the editor

The editor is a committed bundle (`panel/public/assets/editor/codemirror.js`), so the
server needs no Node.js. To change it: `cd panel/resources/editor && npm ci && npm run build`.
