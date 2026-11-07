<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Rhis\WordPress\Bulk\Jobs\AdvanceBulkRun;
use Rhis\WordPress\Bulk\Models\BulkRun;
use Rhis\WordPress\Bulk\Models\BulkRunItem;
use Rhis\WordPress\Models\WordPressInstall;
use Throwable;

/**
 * Starts bulk runs and moves them forward: a few sites at a time (concurrency),
 * following agent jobs, stopping when too many sites fail.
 *
 * advance() is safe to call from anywhere and at any time: a lock per run makes sure
 * only one worker moves a run at once. The queue job calls it in a loop, the
 * scheduler picks up runs whose worker died.
 */
final class Runner
{
    public function __construct(private readonly AgentClient $agent) {}

    /** @param  Collection<int, WordPressInstall>  $installs */
    public function start(BulkAction $action, array $options, Collection $installs, int $concurrency, int $stopAfterFailures, ?User $user = null): BulkRun
    {
        $installs = $installs->loadMissing('website.customer')->sortBy(fn (WordPressInstall $i) => $i->label())->values();
        if ($action->perWebsite()) {
            $installs = $installs->unique('website_id')->values();
        }
        $run = BulkRun::create([
            'action' => $action->key(), 'options' => $options, 'summary' => mb_substr($action->describe($options), 0, 500) ?: null,
            'status' => 'running', 'concurrency' => max(1, min(20, $concurrency)), 'stop_after_failures' => max(0, $stopAfterFailures),
            'total' => $installs->count(), 'user_id' => $user?->id, 'user_name' => $user?->name, 'started_at' => now(),
        ]);
        foreach ($installs as $n => $install) {
            BulkRunItem::create([
                'run_id' => $run->id, 'wordpress_install_id' => $install->id, 'website_id' => $install->website_id,
                'label' => $action->perWebsite() ? $install->website->domain : $install->label(),
                'customer' => $install->website->customer?->displayName(), 'position' => $n,
            ]);
        }
        ActivityLog::record('wordpress.bulk', $action->label(), [
            'run' => $run->id, 'action' => $action->key(), 'sites' => $installs->count(), 'options' => $options,
        ]);
        AdvanceBulkRun::dispatch($run->id);

        return $run;
    }

    /** Move a run forward once. Returns true while there is still work to do. */
    public function advance(BulkRun $run): bool
    {
        $lock = Cache::lock("wp-bulk-run:{$run->id}", 1800);
        if (! $lock->get()) {
            return true;
        }
        $locale = app()->getLocale();
        try {
            $run->refresh();
            // Messages are stored as text: write them in the language of the admin who started the run.
            if ($run->user) {
                app()->setLocale($run->user->preferredLocale());
            }
            $action = $run->definition();
            if ($action === null) {
                $this->stop($run, 'cancelled', __('This action is no longer available.'));

                return false;
            }
            $this->followRunning($run, $action);
            if ($run->status === 'running') {
                $this->startPending($run, $action);
            }

            return $this->settle($run);
        } finally {
            app()->setLocale($locale);
            $lock->release();
        }
    }

    /** Is there an agent job we are waiting on? (The worker then sleeps a little between rounds.) */
    public function isWaiting(BulkRun $run): bool
    {
        return $run->items()->reorder()->where('status', 'running')->whereNotNull('agent_job')->exists();
    }

    public function cancel(BulkRun $run): void
    {
        $run->items()->reorder()->where('status', 'pending')->update(['status' => 'cancelled', 'finished_at' => now(), 'message' => __('Cancelled before it started.')]);
        if (in_array($run->status, ['running', 'paused'], true)) {
            $run->update(['status' => 'cancelled', 'stop_reason' => __('Cancelled by :user.', ['user' => auth()->user()?->name ?? __('an admin')])]);
        }
        $this->settle($run->refresh());
    }

    public function resume(BulkRun $run): void
    {
        if ($run->status !== 'paused') {
            return;
        }
        // Count failures again from here, so the run does not stop at once.
        $failed = $run->items()->reorder()->where('status', 'failed')->count();
        $run->update(['status' => 'running', 'stop_reason' => null,
            'stop_after_failures' => $run->stop_after_failures ? $failed + $run->stop_after_failures : 0]);
        AdvanceBulkRun::dispatch($run->id);
    }

    // ------------------------------------------------------------------

    private function followRunning(BulkRun $run, BulkAction $action): void
    {
        foreach ($run->items()->where('status', 'running')->get() as $item) {
            $install = $this->install($item);
            if ($item->agent_job === null || $install === null) {
                // A sync step that died half-way (worker killed): we cannot know, so say so.
                $this->record($item, Step::failed(__('The worker stopped while this site was busy. Check the site and run it again.')));

                continue;
            }
            try {
                $state = $this->agent->call('job.status', ['job' => $item->agent_job], 10);
            } catch (AgentException $e) {
                if ($e->errorCode === 'not_found') {
                    $this->record($item, Step::failed(__('The agent job is gone (the agent restarted?).')));
                } elseif ($item->started_at && $item->started_at->diffInSeconds(now(), true) > $action->timeout()) {
                    $this->record($item, Step::failed(__('Took longer than :n minutes.', ['n' => intdiv($action->timeout(), 60)])));
                }

                continue;
            }
            if (($state['status'] ?? 'running') === 'running') {
                if ($item->started_at && $item->started_at->diffInSeconds(now(), true) > $action->timeout()) {
                    $this->record($item, Step::failed(__('Took longer than :n minutes.', ['n' => intdiv($action->timeout(), 60)])));
                }

                continue;
            }
            $this->record($item, $this->guard(fn () => $action->finish($install, $run->options ?? [], $state)));
        }
    }

    private function startPending(BulkRun $run, BulkAction $action): void
    {
        while ($run->status === 'running') {
            $busy = $run->items()->reorder()->where('status', 'running')->count();
            if ($busy >= $run->concurrency) {
                return;
            }
            $item = $run->items()->where('status', 'pending')->first();
            if ($item === null) {
                return;
            }
            $install = $this->install($item);
            if ($install === null) {
                $this->record($item, Step::skipped(__('This WordPress install is gone.')));

                continue;
            }
            $reason = $this->guard(fn () => $action->skipReason($install, $run->options ?? []));
            if ($reason instanceof Step) {
                $this->record($item, $reason);
            } elseif ($reason !== null) {
                $this->record($item, Step::skipped($reason));

                continue;
            } else {
                $item->update(['status' => 'running', 'started_at' => now()]);
                $began = microtime(true);
                $this->record($item, $this->guard(fn () => $action->start($install, $run->options ?? [])));
                $this->checkFailures($run);
                // Slow work done here (not in an agent job): end this round so other queued work gets a turn.
                if (microtime(true) - $began > 2) {
                    return;
                }

                continue;
            }
            $this->checkFailures($run);
        }
    }

    /** Run action code; an exception becomes a failed step instead of stopping the whole run. */
    private function guard(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (AgentException $e) {
            return Step::failed($e->getMessage());
        } catch (Throwable $e) {
            Log::error('WordPress bulk action failed', ['exception' => $e]);

            return Step::failed(__('Unexpected error: :message', ['message' => $e->getMessage()]));
        }
    }

    private function record(BulkRunItem $item, Step $step): void
    {
        $item->fill([
            'status' => $step->status, 'message' => $step->message !== null ? mb_substr($step->message, 0, 2000) : $item->message,
            'details' => $step->details ?: $item->details,
        ]);
        if ($step->status === Step::WAIT) {
            $item->agent_job = $step->job;
            $item->started_at ??= now();
        } else {
            $item->started_at ??= now();
            $item->finished_at = now();
        }
        $item->save();
    }

    private function checkFailures(BulkRun $run): void
    {
        if ($run->stop_after_failures < 1) {
            return;
        }
        $failed = $run->items()->reorder()->where('status', 'failed')->count();
        if ($failed >= $run->stop_after_failures) {
            $run->update(['status' => 'paused', 'stop_reason' => trans_choice(
                'Paused after :count failed site. Check the results, then resume or cancel.|Paused after :count failed sites. Check the results, then resume or cancel.', $failed)]);
        }
    }

    /** Finish the run when nothing is left. Returns true while there is still work. */
    private function settle(BulkRun $run): bool
    {
        $c = $run->counts();
        if ($c['running'] > 0) {
            return true;
        }
        if ($c['pending'] > 0) {
            return $run->status === 'running';
        }
        if ($run->finished_at === null) {
            $run->update(['status' => $run->status === 'cancelled' ? 'cancelled' : 'finished', 'finished_at' => now()]);
        }

        return false;
    }

    private function stop(BulkRun $run, string $status, string $reason): void
    {
        $run->items()->reorder()->where('status', 'pending')->update(['status' => 'cancelled', 'finished_at' => now()]);
        $run->update(['status' => $status, 'stop_reason' => $reason]);
        $this->settle($run);
    }

    private function install(BulkRunItem $item): ?WordPressInstall
    {
        return $item->wordpress_install_id ? WordPressInstall::with('website.customer')->find($item->wordpress_install_id) : null;
    }
}
