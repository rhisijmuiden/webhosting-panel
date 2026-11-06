# Git deployments

Customers (and admins) link a GitHub, GitLab, Bitbucket, Gitea/Forgejo or any
other git repository to a website. A push to the chosen branch calls the
panel's webhook, the panel asks the agent to deploy, and the agent fetches,
checks out, runs the build steps and switches the website over.

Screens: `packages/git` (`Rhis\Git\`), admin `/admin/git`, client `/git`, a
card on every website page. Agent: `agent/whp_agent/actions/git.py`.

## How a repository is stored on the server

```
/var/www/vhosts/<domain>/git/<uuid>/
    repo.git/          bare clone (fetched, never a work tree)
    deploy_key(.pub)   ed25519 deploy key, one per repository
    known_hosts        host keys of the git server (accepted on first use)
    releases/<rel>/    zero-downtime mode: one folder per deployment
    shared/            zero-downtime mode: files kept between releases
/var/lib/webhosting-panel/git/<domain>/<uuid>.json   last deployment (root owned)
/var/lib/webhosting-panel/git/<domain>/<uuid>.lock   one deployment at a time
```

Everything inside the website folder is done as the website's own Linux user
(`systemd-run` in `whp-sites.slice`, like WP-CLI): git, ssh-keygen, composer,
npm, artisan, custom commands and every mkdir/ln/mv/rm. The agent never writes
files of a website as root. git runs with `core.hooksPath=/dev/null`,
`GIT_ALLOW_PROTOCOL=https:ssh` and `BatchMode=yes`; only `https://`, `ssh://`
and `user@host:path` addresses are accepted.

## Deploy modes

| Mode | What happens |
| --- | --- |
| `releases` (default, zero downtime) | Checkout into `releases/<YYYYmmddHHMMSS>-<sha7>/`, shared files linked in, build steps run there. Only when everything succeeded, the deploy folder (a symlink) is switched with one `rename()` and the site's PHP-FPM pool is reloaded gracefully. A failed build is removed and visitors keep the previous release. Older releases are kept for rollback (`keep`, default 5). On the first deployment an existing real folder is moved to `<folder>.before-git-<time>`. |
| `direct` | `git checkout --force` straight into the folder (like Plesk). Files that left the repository are removed, files that were never in it (uploads) stay. |

Nginx uses `$realpath_root`, so the switch is picked up by the next request.

Shared paths (`releases` mode): one per line, folders end with `/`. When a
shared path does not exist yet, the version from the first release is kept (or
an empty file/folder is made). Defaults: Laravel `storage/`, `.env`; WordPress
`wp-content/uploads/`, `wp-config.php`.

## Build steps

`composer` (`composer install --no-dev --optimize-autoloader`, if
`composer.json` exists), `npm` (`npm ci` or `npm install`, then `npm run build`
if `package.json` has a build script), `artisan_migrate`
(`php artisan migrate --force`), `artisan_optimize`, and an optional custom
Bash command (`WHP_GIT_CUSTOMER_COMMANDS=false` turns these off for customers).
Steps that do not apply to the repository are skipped. A step that fails stops
the deployment. Each step may take 30 minutes.

## Webhook

`POST https://<panel>:8443/git/webhook/<uuid>` (no login, throttled). Accepted proof:

| Provider | Header |
| --- | --- |
| GitHub, Bitbucket | `X-Hub-Signature-256: sha256=<HMAC-SHA256(body, secret)>` |
| Gitea, Forgejo, Gogs | `X-Gitea-Signature: <hex HMAC>` (or the GitHub header) |
| GitLab | `X-Gitlab-Token: <secret>` |
| Anything else | `X-Webhook-Token: <secret>` or `?token=<secret>` |

Ping events answer `pong`. Pushes to other branches, tags and branch deletions
are ignored. With automatic deploy off, a push only records the newest commit
(the panel shows "New commits"). A push while a deployment runs queues one
more deployment; further pushes merge into that queued one.

## Agent actions

| Action | What |
| --- | --- |
| `git.key` | `{domain, id, rotate?}` → `{public_key, fingerprint}` |
| `git.check` | `{domain, id, url}` → `{branches: [{name, commit}], default}` (git ls-remote) |
| `git.fetch` | `{domain, id, url, branch}` → `{latest, deployed}` |
| `git.deploy` | Job: `{domain, id, url, branch, path, mode, keep, shared[], steps[{type, command?}], allow_commands, commit?}`. Result `{commit, release, steps, seconds}` |
| `git.status` | `{domain, id, path}` → `{deployed, current_release, releases[], has_key}` |
| `git.rollback` | `{domain, id, path, release}` |
| `git.remove` | `{domain, id, path, shared[]}`: in `releases` mode the live release is copied into a normal folder first, then `git/<id>/` is removed |
| `git.tools` | Which of git, composer, npm and node are installed |

The panel runs `whp:git:collect` every minute to finish deployments nobody is
watching and to start queued ones. The installer installs `git`,
`openssh-client`, `composer`, `nodejs` and `npm`.
