<?php

declare(strict_types=1);

namespace Rhis\WordPress\SearchReplace;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Rhis\WordPress\Models\WordPressInstall;

/** Search & replace and change domain, through the agent (wp.sr_*). */
final class SearchReplacer
{
    public function __construct(private readonly AgentClient $agent) {}

    /** Current address, wp-config constants and the hosts this website serves. */
    public function addresses(WordPressInstall $install): array
    {
        return $this->agent->call('wp.sr_addresses', $install->target(), 180);
    }

    /** Start the dry run. $params: mode=text (search, replace) or mode=domain (host, scheme), tables, skip_guid. */
    public function preview(WordPressInstall $install, array $params, ?int $userId): SearchReplaceRun
    {
        $params = $this->clean($params);
        $job = $this->agent->call('wp.sr_preview', $install->target() + $params, 30)['job'];

        return SearchReplaceRun::create([
            'wordpress_install_id' => $install->id, 'user_id' => $userId, 'mode' => $params['mode'],
            'params' => $params, 'status' => 'previewing', 'preview_job' => $job,
        ]);
    }

    /** Make the change for real, with exactly the parameters of the dry run. */
    public function apply(SearchReplaceRun $run, bool $snapshot = true): SearchReplaceRun
    {
        $job = $this->agent->call('wp.sr_apply', $run->install->target() + $run->params + ['snapshot' => $snapshot], 30)['job'];
        $run->update(['status' => 'applying', 'apply_job' => $job, 'error' => null]);

        return $run;
    }

    /** Ask the agent how a running job is doing and store the outcome when it is finished. */
    public function sync(SearchReplaceRun $run): ?array
    {
        $job = $run->status === 'previewing' ? $run->preview_job : ($run->status === 'applying' ? $run->apply_job : null);
        if (! $job) {
            return null;
        }
        try {
            $state = $this->agent->call('job.status', ['job' => $job], 10);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                $run->update(['status' => $run->status === 'previewing' ? 'preview_failed' : 'failed', 'error' => $e->getMessage()]);
            }

            return null;
        }
        $status = $state['status'] ?? 'running';
        if ($status === 'running') {
            return $state;
        }
        $result = $status === 'succeeded' ? ($state['result'] ?? []) : ($state['error']['details'] ?? []);
        $error = $status === 'succeeded' ? null : (string) ($state['error']['message'] ?? __('Unknown error'));
        if ($run->status === 'previewing') {
            $run->update(['status' => $error ? 'preview_failed' : 'previewed', 'preview' => $result ?: null,
                'error' => $error, 'previewed_at' => now()]);
        } else {
            $final = $error ? (($result['rolled_back'] ?? false) ? 'rolled_back' : 'failed') : 'applied';
            $run->update(['status' => $final, 'result' => $result ?: null, 'error' => $error, 'applied_at' => now()]);
        }

        return $state;
    }

    /** Only the keys the agent knows, with the right types. */
    private function clean(array $p): array
    {
        $out = ['mode' => $p['mode'] === 'domain' ? 'domain' : 'text',
            'tables' => ($p['tables'] ?? 'wordpress') === 'all' ? 'all' : 'wordpress',
            'skip_guid' => (bool) ($p['skip_guid'] ?? true)];
        if ($out['mode'] === 'text') {
            $out['search'] = (string) $p['search'];
            $out['replace'] = (string) ($p['replace'] ?? '');
        } else {
            $out['host'] = strtolower((string) $p['host']);
            $out['scheme'] = ($p['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        }

        return $out;
    }
}
