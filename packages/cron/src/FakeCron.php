<?php

declare(strict_types=1);

namespace Rhis\Cron;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;

/** Answers for cron.* when the panel runs without a real agent (WHP_AGENT_DRIVER=fake). */
final class FakeCron
{
    public static function register(FakeAgentClient $agent): void
    {
        $status = function (string $id) {
            $job = \Rhis\Cron\Models\CronJob::withoutGlobalScopes()->where('uid', $id)->first();
            $next = $job ? (Schedule::nextRuns($job->schedule, 1)[0] ?? null) : null;

            return ['id' => $id, 'running' => false, 'running_since' => null, 'scheduled' => (bool) $job?->enabled,
                'next_run' => $next?->timestamp, 'last_trigger' => null];
        };
        $agent->respond('cron.apply', fn ($p) => ['id' => $p['id'], 'unit' => "whp-cron-{$p['id']}.timer", 'calendar' => ['*-*-* *:*:00'], 'status' => $status($p['id'])]);
        $agent->respond('cron.list', fn ($p) => ['jobs' => collect($p['ids'] ?? [])->mapWithKeys(fn ($id) => [$id => $status($id)])->all()]);
        $agent->respond('cron.run', function ($p) use ($status) {
            // A fake run that finished right away, so the screens have something to show.
            $runs = Cache::get('fake-agent.cron.runs', []);
            $now = microtime(true);
            $runs[] = ['id' => $p['id'], 'invocation' => bin2hex(random_bytes(16)), 'started' => (int) $now - 2, 'finished' => $now,
                'duration_ms' => 1840, 'status' => 'success', 'exit_status' => 0, 'signal' => null, 'truncated' => false,
                'output' => "Running scheduled command: php artisan queue:prune-batches\nDone: 3 batches pruned.\n"];
            Cache::put('fake-agent.cron.runs', array_slice($runs, -200), 3600);

            return ['started' => true] + $status($p['id']);
        });
        $agent->respond('cron.stop', fn ($p) => $status($p['id']));
        $agent->respond('cron.runs', fn ($p) => ['now' => microtime(true), 'runs' => array_values(array_filter(
            Cache::get('fake-agent.cron.runs', []),
            fn ($r) => $r['finished'] > ($p['since'] ?? 0) && (! isset($p['ids']) || in_array($r['id'], $p['ids'], true)),
        ))]);
        $agent->respond('cron.suspend', fn ($p) => ['jobs' => []] + $p);
        $agent->respond('cron.remove', fn ($p) => ['id' => $p['id'], 'removed' => true]);
    }
}
