# WordPress bulk actions

Admins run one action on many WordPress installs at once: **WordPress → Bulk actions**
(`/admin/wordpress/bulk`). Pick an action and its options, pick sites (search, customer,
WordPress version, plugin or theme installed / with update / missing, WP-cron mode,
quick filters for outdated, core update, last update failed, not checked), choose how
many run at the same time and after how many failures the run pauses, and follow every
site live. Finished runs can be retried for the failed sites.

Code: `packages/wordpress/src/Bulk/` (inside the WordPress package, registered by
`WordPressServiceProvider::register()`), routes `routes/bulk-admin.php`, views
`resources/views/bulk/`, translations `lang/bulk/nl.json`, script
`panel/public/assets/wp-bulk.js`, tables `wp_bulk_runs` + `wp_bulk_run_items`.

## How a run works

1. `Runner::start()` stores the run and one item per install (one per *website* when the
   action says `perWebsite()`), and dispatches `AdvanceBulkRun` on the queue (`whp-queue`).
2. `Runner::advance()` (under a lock per run) follows items waiting on an agent job
   (`job.status`), then starts pending items until `concurrency` items are busy. For each
   item it calls `skipReason()` → `start()` → (when `start()` returned `Step::wait($job)`)
   later `finish()`. An exception fails only that site.
3. When `stop_after_failures` sites failed, the run is **paused**; the admin resumes or
   cancels. Cancel stops pending sites; busy sites finish.
4. `AdvanceBulkRun` works for ~10 minutes, then queues a fresh copy of itself.
   `whp:wp-bulk:tick` (every minute) picks up runs whose worker went away.

## Adding an action from another package

Register it in your service provider's `register()` (or `boot()`):

```php
use Rhis\WordPress\Bulk\BulkActions;

if (class_exists(BulkActions::class)) {
    BulkActions::register(MyHardeningAction::class); // resolved from the container
}
```

Either extend `Rhis\WordPress\Bulk\BaseAction`:

```php
final class ApplyHardening extends BaseAction
{
    public function __construct(private readonly Hardening $hardening) {}

    public function key(): string { return 'hardening-apply'; }          // stable, kebab-case
    public function label(): string { return __('Apply hardening'); }
    public function description(): string { return __('...'); }
    public function group(): string { return 'security'; }              // updates | security | plugins | maintenance | your own
    public function icon(): string { return 'shield'; }

    public function fields(): array
    {
        return [
            Field::checkbox('file_edit', __('Turn off the file editor'), true),
            Field::select('mode', __('Mode'), ['apply' => __('Apply'), 'revert' => __('Undo')]),
        ];
    }

    public function rules(): array
    {
        return ['file_edit' => ['boolean'], 'mode' => ['required', 'in:apply,revert']];
    }

    public function skipReason(WordPressInstall $install, array $options): ?string
    {
        return $this->hardening->isApplied($install) ? __('Already hardened.') : null;
    }

    public function start(WordPressInstall $install, array $options): Step
    {
        // Quick work: do it and return Step::ok()/failed()/attention()/skipped().
        // Long work: start an agent job and return Step::wait($jobId); finish() gets the job.status answer.
        return $this->job($install, 'wp.harden', ['file_edit' => $options['file_edit']]);
    }
}
```

or, for a few lines, a `CallbackAction`:

```php
BulkActions::register(new CallbackAction(
    key: 'maintenance-on', label: fn () => __('Maintenance mode on'), group: 'maintenance',
    run: fn (WordPressInstall $i, array $o) => app(AgentClient::class)->call('wp.maintenance', $i->target() + ['enabled' => true]),
));
```

A closure may return a `Step`, or an agent answer: `['job' => …]` is followed as a job,
anything else counts as success and its scalar values are shown as details.

### Contract

| Method | Meaning |
| --- | --- |
| `fields()` | `Field::checkbox/select/text/number/note`. Values arrive in `$options` (checkbox = bool, number = int). |
| `rules()` | Laravel rules per option name; errors show next to the field. |
| `dangerous()` | The admin must type the confirmation word (`confirm` / `bevestig`). Use for delete/revert. |
| `perWebsite()` | Run once per website (website-wide work: caches, malware scan). |
| `concurrency()` | Default number of sites at the same time (admin can change 1–10). |
| `timeout()` | Seconds before a waiting site is marked failed (default 3600). |
| `skipReason()` | Null = run it; a string = skipped with that reason (no agent call). |
| `start()` / `finish()` | Return a `Step`: `ok`, `failed`, `attention` (done, but look at it), `skipped`, `wait($job)`. `$details` = list of lines or label ⇒ value. |
| `describe()` | The chosen options in a few words for the run history. |

Labels and messages are translated with `__()`; put the Dutch texts in your own package's
JSON file. Deep link to a preselected action: `/admin/wordpress/bulk?action=<key>`
(`&filter=outdated|core|failed|unchecked`, `&has=plugin:<slug>`, `&slugs=<slug>&type=plugin`
for `update-plugins`).

## Built-in actions

| Key | Group | What |
| --- | --- | --- |
| `update` | updates | Core/plugins/themes with snapshot + rollback (`Toolkit::update`, `wp.update`). Rolled back = failed. |
| `update-plugins` | updates | Only the named plugin/theme slugs where installed and outdated. |
| `check` | updates | `wp.status` again. |
| `security-scan` | security | `malware.scan` job + vulnerability match (`Rhis\Vulnerabilities\Scanner`), per website. Only when that package is installed. |
| `wp-cron` | maintenance | Server timer or WordPress (`wp.cron_set`). |
| `flush-object-cache` | maintenance | `objcache.flush`, per website, only sites with an object cache. Only when that package is installed. |
