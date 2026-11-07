<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Integrations;

use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WpIntegrity\Integrity;
use Rhis\WpIntegrity\Models\IntegrityReport;

/** Compare core and plugin files with wordpress.org now (packages/wp-integrity, wpint.check job). */
final class IntegrityCheck extends BaseAction
{
    public function __construct(private readonly Integrity $integrity) {}

    public function key(): string
    {
        return 'integrity-check';
    }

    public function label(): string
    {
        return __('File integrity check');
    }

    public function description(): string
    {
        return __('Compare WordPress core and plugin files with the originals from wordpress.org now. Changed files show up in the Security center.');
    }

    public function group(): string
    {
        return 'security';
    }

    public function icon(): string
    {
        return 'search';
    }

    public function order(): int
    {
        return 20;
    }

    public function concurrency(): int
    {
        return 2;
    }

    public function skipReason(WordPressInstall $install, array $o): ?string
    {
        return $this->integrity->for($install)->isRunning() ? __('A check is already running on this site.') : null;
    }

    public function start(WordPressInstall $install, array $o): Step
    {
        $report = $this->integrity->start($install);

        return Step::wait((string) $report->job_id, __('Checking files…'));
    }

    public function finish(WordPressInstall $install, array $o, array $state): Step
    {
        $report = IntegrityReport::where('wordpress_install_id', $install->id)->first();
        if ($report && $report->job_id === ($state['id'] ?? null)) {
            $report->setRelation('install', $install);
            $this->integrity->poll($report); // stores the report and publishes findings
            $report->refresh();
        }
        if (($state['status'] ?? '') !== 'succeeded') {
            return Step::failed((string) ($state['error']['message'] ?? __('The check failed.')));
        }
        $lines = $report ? array_filter([
            trans_choice(':count changed file|:count changed files', (int) $report->changed_files),
            $report->malware_files ? trans_choice(':count file with malware|:count files with malware', (int) $report->malware_files) : null,
            $report->unverifiable ? trans_choice(':count part cannot be verified|:count parts cannot be verified', (int) $report->unverifiable) : null,
        ]) : [];

        return match ($report?->status) {
            'ok' => Step::ok(__('All files match the originals.'), $lines),
            'changed' => Step::attention(__('Changed files found.'), $lines),
            'error' => Step::failed(__('The check could not run.'), $lines),
            default => Step::ok(__('Checked.'), $lines),
        };
    }
}
