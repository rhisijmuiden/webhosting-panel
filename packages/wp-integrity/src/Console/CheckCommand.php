<?php

declare(strict_types=1);

namespace Rhis\WpIntegrity\Console;

use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WpIntegrity\Integrity;

/** Nightly: compare every WordPress install with the WordPress.org checksums. */
final class CheckCommand extends Command
{
    protected $signature = 'wp-integrity:check {--domain= : Only this website}';

    protected $description = 'Check WordPress core and plugin files against the WordPress.org checksums';

    public function handle(Integrity $integrity): int
    {
        $installs = WordPressInstall::with('website')
            ->when($this->option('domain'), fn ($q, $d) => $q->whereHas('website', fn ($w) => $w->where('domain', $d)))->get();
        $changed = 0;
        foreach ($installs as $install) {
            if (! $install->website) {
                continue;
            }
            try {
                $report = $integrity->check($install);
                if ($report->status === 'changed') {
                    $changed++;
                    $this->warn("{$install->label()}: ".trans_choice(':count changed file|:count changed files', $report->changed_files, ['count' => $report->changed_files]));
                }
            } catch (AgentException $e) {
                $this->error("{$install->label()}: {$e->getMessage()}");
            }
        }
        $this->info("Checked {$installs->count()} WordPress installs, {$changed} with changed files.");

        return self::SUCCESS;
    }
}
