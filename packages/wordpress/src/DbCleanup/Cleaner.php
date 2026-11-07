<?php

declare(strict_types=1);

namespace Rhis\WordPress\DbCleanup;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Rhis\WordPress\Models\WordPressInstall;

/** Preview and run database cleanups through the agent (wp.dbclean_*). */
final class Cleaner
{
    /** Everything the agent can clean, in the order the screens show it. */
    public const ITEMS = ['revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments',
        'expired_transients', 'all_transients', 'orphaned_meta', 'optimize'];

    /** Ticked by default: safe on every site. All transients is a choice. */
    public const DEFAULT_ITEMS = ['revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments',
        'expired_transients', 'orphaned_meta', 'optimize'];

    public const KEEP_REVISIONS = [0, 1, 2, 3, 5, 10, 20];

    public function __construct(private readonly AgentClient $agent) {}

    /** Name and explanation of every item, translated. */
    public static function labels(): array
    {
        return [
            'revisions' => [__('Post revisions'), __('Older saved versions of posts and pages.')],
            'auto_drafts' => [__('Auto drafts'), __('Drafts WordPress saved by itself and nobody used.')],
            'trashed_posts' => [__('Posts in the trash'), __('Posts and pages in the trash, with their media files.')],
            'spam_comments' => [__('Spam comments'), __('Comments marked as spam.')],
            'trashed_comments' => [__('Comments in the trash'), __('Comments someone moved to the trash.')],
            'expired_transients' => [__('Expired transients'), __('Temporary data of plugins that has expired.')],
            'all_transients' => [__('All transients'), __('Also temporary data that is still valid. Plugins build it again, the site can be a bit slower for a moment.')],
            'orphaned_meta' => [__('Orphaned data'), __('Extra fields of posts and comments that no longer exist.')],
            'optimize' => [__('Optimize tables'), __('Rebuild the tables so the database gives free space back.')],
        ];
    }

    /** What a cleanup would remove (nothing is changed). */
    public function preview(WordPressInstall $install, int $keepRevisions = 0): array
    {
        return $this->agent->call('wp.dbclean_preview', $install->target() + ['keep_revisions' => $keepRevisions], 300);
    }

    public function start(WordPressInstall $install, array $items, int $keepRevisions, ?int $userId): DbCleanup
    {
        $items = array_values(array_intersect(self::ITEMS, $items));
        $job = $this->agent->call('wp.dbclean_run', $install->target() + ['items' => $items, 'keep_revisions' => $keepRevisions], 30)['job'];

        return DbCleanup::create([
            'wordpress_install_id' => $install->id, 'customer_id' => $install->website->customer_id, 'user_id' => $userId,
            'status' => 'running', 'job_id' => $job, 'items' => $items, 'keep_revisions' => $keepRevisions,
        ]);
    }

    /** Ask the agent how a running cleanup is doing; store the outcome once it finished. Returns the job state. */
    public function sync(DbCleanup $cleanup): ?array
    {
        if (! $cleanup->job_id) {
            return null;
        }
        try {
            $state = $this->agent->call('job.status', ['job' => $cleanup->job_id], 10);
        } catch (AgentException $e) {
            if ($cleanup->isRunning() && $e->errorCode === 'not_found') {
                $cleanup->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            }

            return null;
        }
        $status = $state['status'] ?? 'running';
        if ($cleanup->isRunning() && $status !== 'running') {
            $result = $status === 'succeeded' ? ($state['result'] ?? []) : ($state['error']['details'] ?? []);
            $cleanup->update([
                'status' => $status === 'succeeded' ? 'succeeded' : (($result['rolled_back'] ?? false) ? 'rolled_back' : 'failed'),
                'result' => $result ?: null,
                'error' => $status === 'succeeded' ? null : ($state['error']['message'] ?? __('The cleanup failed.')),
                'size_before' => $result['db_size_before'] ?? null,
                'size_after' => $result['db_size_after'] ?? null,
                'snapshot' => $result['snapshot'] ?? null,
                'finished_at' => now(),
            ]);
        }

        return $state;
    }

    /** Store finished cleanups nobody looked at (scheduled). */
    public function collect(): int
    {
        $done = 0;
        foreach (DbCleanup::where('status', 'running')->get() as $cleanup) {
            $this->sync($cleanup);
            $done += $cleanup->isRunning() ? 0 : 1;
        }

        return $done;
    }
}
