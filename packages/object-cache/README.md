# rhis/object-cache

Valkey object cache per website. Switched on per website on the domain page
(Hosting & DNS tab → Object cache), by the customer or an admin. Admins see all
caches under **Additional services → Object cache**.

## How it works

- Every website gets **its own Valkey server** (`whp-objcache-<site user>.service`),
  never a database on the shared Valkey of the panel. It runs as the site's Linux
  user in the subscription's resource jail, on a unix socket only
  (`/run/whp-objcache/<site user>/valkey.sock`, folder 0700), so no other website
  can reach it. No TCP port, `PrivateNetwork=yes`, nothing written to disk.
- **Memory per package**: service plan → *Object cache memory*. *Server default*
  (NULL) uses `WHP_OBJECT_CACHE_DEFAULT_MB` (64 MB), `0` switches the feature off
  for that plan. Valkey evicts the oldest entries (`allkeys-lru`) when it is full;
  systemd `MemoryMax` sits a little above it.
- **WordPress / WooCommerce**: with *Set up WordPress automatically* the agent
  installs and activates the Redis Object Cache plugin (works with Valkey) in every
  WordPress on the website, writes the `WP_REDIS_*` constants (socket, own database
  and prefix per install) and enables its `object-cache.php` drop-in. A drop-in of
  another plugin (W3 Total Cache, LiteSpeed ...) is left alone and reported.
  Switching off removes the drop-in and constants first, then stops Valkey.
- **Flush**: empties the whole cache of the website (`FLUSHALL`).
- PHP may read, write and flush, but not reconfigure or stop the server
  (`-@admin` ACL).
- Laravel: the card shows the `.env` lines (`REDIS_HOST=<socket>`, `REDIS_PORT=0`).

The cache follows its website: stopped when the website is suspended, started
again afterwards, removed before the website is removed, and restarted with the
new memory when the package (or the package of the subscription) changes.

## Pieces

| Where | What |
| --- | --- |
| `agent/whp_agent/actions/objcache.py` | `objcache.apply`, `.status`, `.list`, `.flush`, `.suspend`, `.remove` |
| `installer/modules/35-valkey.sh` | config and state folders |
| `installer/bin/whp-site` | `remove` also removes the site's cache |
| `src/ObjectCacheManager.php` | panel side, `Jobs/ApplyObjectCache` runs in the queue |

Settings (`panel.env`): `WHP_OBJECT_CACHE_CUSTOMER_ACCESS` (default true),
`WHP_OBJECT_CACHE_DEFAULT_MB` (default 64).
