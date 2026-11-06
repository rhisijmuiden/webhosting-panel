<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Console;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Services\Webhooks;
use Rhis\CrmApi\Support\Present;

/** Sends usage.limit_reached and backup.failed webhooks (scheduled hourly). */
final class WatchCommand extends Command
{
    protected $signature = 'crm-api:watch';

    protected $description = 'Send webhooks for subscriptions over a limit and for failed backups';

    public function handle(Webhooks $webhooks, AgentClient $agent): int
    {
        foreach (Subscription::with(['package', 'website'])->whereIn('status', ['active', 'suspended'])->get() as $s) {
            $usage = Present::usage($s);
            foreach (['disk_mb'] as $limit) {
                $over = $usage[$limit]['limit'] !== null && $usage[$limit]['used'] >= $usage[$limit]['limit'];
                $key = "crm-api:over:{$s->id}:{$limit}";
                if ($over && ! Cache::has($key)) {
                    $webhooks->dispatch('usage.limit_reached', ['usage' => $usage, 'limit' => $limit]);
                    Cache::put($key, true, now()->addDays(7));
                } elseif (! $over) {
                    Cache::forget($key);
                }
            }
        }

        try {
            $last = $agent->call('backup.status')['last_run'] ?? null;
        } catch (AgentException) {
            $last = null;
        }
        if ($last && Cache::get('crm-api:backup-run') !== $last['started']) {
            Cache::forever('crm-api:backup-run', $last['started']);
            $domains = Subscription::whereIn('status', ['active', 'suspended'])->pluck('id', 'primary_domain');
            foreach ($last['targets'] ?? [] as $target) {
                foreach ($target['items'] ?? [] as $item) {
                    $domain = preg_replace('/^(site|mail):/', '', (string) $item['scope']);
                    if (($item['status'] ?? '') === 'failed' && isset($domains[$domain])) {
                        $webhooks->dispatch('backup.failed', [
                            'subscription_id' => $domains[$domain], 'scope' => $item['scope'],
                            'target' => $target['target'], 'message' => $item['reason'] ?? null,
                        ]);
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
