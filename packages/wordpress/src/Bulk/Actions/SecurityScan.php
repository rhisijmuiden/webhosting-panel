<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Actions;

use App\Services\Agent\AgentException;
use Rhis\Vulnerabilities\Scanner;
use Rhis\WordPress\Bulk\BaseAction;
use Rhis\WordPress\Bulk\Step;
use Rhis\WordPress\Models\WordPressInstall;

/**
 * Scan each website for malware (ClamAV, malware.scan job) and match its plugins against the
 * vulnerability database; findings go to the Security center (packages/vulnerabilities).
 */
final class SecurityScan extends BaseAction
{
    public function __construct(private readonly Scanner $scanner) {}

    public function key(): string
    {
        return 'security-scan';
    }

    public function label(): string
    {
        return __('Security scan');
    }

    public function description(): string
    {
        return __('Scan for malware and known vulnerable plugins now, instead of waiting for the night. Findings show up in the Security center.');
    }

    public function group(): string
    {
        return 'security';
    }

    public function icon(): string
    {
        return 'shield';
    }

    public function order(): int
    {
        return 10;
    }

    public function perWebsite(): bool
    {
        return true;
    }

    public function concurrency(): int
    {
        return 2;
    }

    public function timeout(): int
    {
        return 2 * 3600;
    }

    public function start(WordPressInstall $install, array $options): Step
    {
        try {
            $out = $this->agent()->call('malware.scan', ['domain' => $install->website->domain], 30);

            return Step::wait((string) $out['job'], __('Scanning for malware…'));
        } catch (AgentException $e) {
            if ($e->errorCode !== 'not_found') {
                throw $e;
            }

            // No ClamAV on this server: still check for vulnerable plugins.
            return $this->collect($install, [__('Malware scan skipped: :reason', ['reason' => $e->getMessage()])]);
        }
    }

    public function finish(WordPressInstall $install, array $options, array $state): Step
    {
        if (($state['status'] ?? '') !== 'succeeded') {
            return Step::failed((string) ($state['error']['message'] ?? __('The malware scan failed.')));
        }

        return $this->collect($install, [trans_choice(':count infected file|:count infected files', (int) ($state['result']['infected'] ?? 0))]);
    }

    private function collect(WordPressInstall $install, array $lines): Step
    {
        $r = $this->scanner->scanWebsite($install->website);
        $lines[] = trans_choice(':count new finding|:count new findings', $r['found']);
        $lines[] = trans_choice(':count finding resolved|:count findings resolved', $r['resolved']);
        array_push($lines, ...array_slice($r['errors'], 0, 5));

        return $r['found'] > 0
            ? Step::attention(trans_choice(':count new security problem found.|:count new security problems found.', $r['found']), $lines)
            : Step::ok(__('No new problems found.'), $lines);
    }
}
