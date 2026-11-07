<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Integrations;

use Illuminate\Support\Number;
use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Field;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\DbCleanup\Cleaner;
use Rhis\WordPress\DbCleanup\DbCleanup;
use Rhis\WordPress\Models\WordPressInstall;

/** Clean up the database with a snapshot first and automatic restore (wp.dbclean_run job). */
final class DatabaseCleanup extends BaseAction
{
    public function __construct(private readonly Cleaner $cleaner) {}

    public function key(): string
    {
        return 'db-cleanup';
    }

    public function label(): string
    {
        return __('Clean up the database');
    }

    public function description(): string
    {
        return __('Remove revisions, drafts, spam, trash and expired data, and optimize the tables. A snapshot is taken first and a site that breaks is restored.');
    }

    public function group(): string
    {
        return 'maintenance';
    }

    public function icon(): string
    {
        return 'database';
    }

    public function order(): int
    {
        return 30;
    }

    public function concurrency(): int
    {
        return 2;
    }

    public function timeout(): int
    {
        return 2 * 3600;
    }

    public function fields(): array
    {
        $fields = [];
        foreach (Cleaner::labels() as $item => [$title, $help]) {
            $fields[] = Field::checkbox($item, $title, in_array($item, Cleaner::DEFAULT_ITEMS, true), $help);
        }
        $fields[] = Field::select('keep_revisions', __('Keep the newest revisions per post'),
            collect(Cleaner::KEEP_REVISIONS)->mapWithKeys(fn ($n) => [$n => $n === 0 ? __('none') : (string) $n])->all(), 0);

        return $fields;
    }

    public function rules(): array
    {
        return array_fill_keys(Cleaner::ITEMS, ['boolean']) + ['keep_revisions' => ['required', 'integer', 'in:'.implode(',', Cleaner::KEEP_REVISIONS)]];
    }

    private function items(array $o): array
    {
        return array_values(array_filter(Cleaner::ITEMS, fn ($i) => (bool) ($o[$i] ?? false)));
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        if (! $this->items($o)) {
            return __('Nothing was chosen to clean up.');
        }

        return DbCleanup::where('wordpress_install_id', $install->id)->where('status', 'running')->exists()
            ? __('A cleanup is already running on this site.') : null;
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        $cleanup = $this->cleaner->start($install, $this->items($o), (int) ($o['keep_revisions'] ?? 0), auth()->id() ?? null);

        return Step::wait((string) $cleanup->job_id, __('Cleaning up…'));
    }

    public function finish(WordPressInstall $install, array $o, array $state): Step
    {
        $cleanup = DbCleanup::where('job_id', $state['id'] ?? '')->first();
        if ($cleanup === null) {
            return parent::finish($install, $o, $state);
        }
        $this->cleaner->sync($cleanup);
        $cleanup->refresh();
        $lines = array_filter([
            trans_choice(':count row removed|:count rows removed', $cleanup->removedTotal()),
            $cleanup->freed() !== null ? __(':size freed', ['size' => Number::fileSize($cleanup->freed())]) : null,
        ]);

        return match ($cleanup->status) {
            'succeeded' => Step::ok(__('Cleaned up.'), $lines),
            'rolled_back' => Step::failed(__('The site broke after the cleanup and the database was restored.'), $lines),
            'running' => Step::failed(__('Still running; see the database cleanup page of this site.')),
            default => Step::failed((string) ($cleanup->error ?? __('The cleanup failed.'))),
        };
    }

    public function describe(array $o): string
    {
        return implode(', ', array_map(fn ($i) => mb_strtolower(Cleaner::labels()[$i][0]), $this->items($o)));
    }
}
