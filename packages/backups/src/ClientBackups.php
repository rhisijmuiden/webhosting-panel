<?php

declare(strict_types=1);

namespace Rhis\Backups;

use App\Models\ActivityLog;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Rhis\Backups\Models\ClientBackup;
use Rhis\Backups\Models\ClientBackupTarget;

/** Starts customer backups and restores on whp-agent and keeps their records up to date. */
final class ClientBackups
{
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function __construct(private readonly AgentClient $agent) {}

    /** The databases of a website as the agent wants them: [{name, user}]. */
    public static function databases(Website $website): array
    {
        if (! class_exists(SiteDatabase::class)) {
            return [];
        }

        return SiteDatabase::where('website_id', $website->id)->orderBy('name')->get()->map(function (SiteDatabase $db) {
            $user = collect($db->users ?? [])->map(fn ($u) => is_array($u) ? ($u['user'] ?? null) : $u)->filter()->first();

            return array_filter(['name' => $db->name, 'user' => is_string($user) ? $user : null]);
        })->values()->all();
    }

    /** @throws AgentException */
    public function backup(ClientBackupTarget $target, Website $website, string $kind, string $trigger = 'manual', ?array $parts = null): ClientBackup
    {
        $result = $this->agent->call('client_backup.run', [
            'domain' => $website->domain, 'target' => $target->agentTarget(), 'target_id' => $target->id,
            'kind' => $kind, 'parts' => $parts ?? $target->parts(), 'databases' => self::databases($website),
            'keep_full' => $target->keep_full,
        ], 60);

        return $this->record($website, $target, 'backup', $result['job'], $trigger, $kind);
    }

    /** @throws AgentException */
    public function restore(ClientBackupTarget $target, Website $website, string $file, array $parts, bool $safety): ClientBackup
    {
        $params = [
            'domain' => $website->domain, 'target' => $target->agentTarget(), 'file' => $file,
            'parts' => $parts, 'databases' => self::databases($website),
        ];
        if ($safety) {
            $params += ['safety_target' => $target->agentTarget(), 'safety_target_id' => $target->id];
        }
        $result = $this->agent->call('client_backup.restore', $params, 60);
        $record = $this->record($website, $target, 'restore', $result['job']);
        $record->update(['file' => $file]);

        return $record;
    }

    /** @throws AgentException */
    public function restoreUpload(string $upload, Website $website, array $parts, ?ClientBackupTarget $safety, string $name): ClientBackup
    {
        $params = ['domain' => $website->domain, 'upload' => $upload, 'parts' => $parts, 'databases' => self::databases($website)];
        if ($safety) {
            $params += ['safety_target' => $safety->agentTarget(), 'safety_target_id' => $safety->id];
        }
        $result = $this->agent->call('client_backup.restore_upload', $params, 60);
        $record = $this->record($website, $safety, 'restore_upload', $result['job']);
        $record->update(['file' => mb_substr($name, 0, 250)]);

        return $record;
    }

    private function record(Website $website, ?ClientBackupTarget $target, string $action, string $job, string $trigger = 'manual', ?string $kind = null): ClientBackup
    {
        $record = ClientBackup::create([
            'customer_id' => $website->customer_id, 'website_id' => $website->id, 'client_backup_target_id' => $target?->id,
            'domain' => $website->domain, 'action' => $action, 'kind' => $kind, 'trigger' => $trigger, 'status' => 'running',
            'job_id' => $job, 'user' => auth()->user()?->name, 'started_at' => now(),
        ]);
        ActivityLog::record("client_backup.{$action}", $website->domain, array_filter([
            'location' => $target?->name, 'kind' => $kind, 'trigger' => $trigger,
        ]), $website->customer_id);

        return $record;
    }

    /** Read the agent job of a running record and store the outcome. */
    public function sync(ClientBackup $record): ClientBackup
    {
        if ($record->status !== 'running' || ! $record->job_id) {
            return $record;
        }
        try {
            $job = $this->agent->call('job.status', ['job' => $record->job_id], 10);
        } catch (AgentException $e) {
            if ($e->getCode() === 404 || str_contains(strtolower($e->getMessage()), 'not found')) {
                $record->update(['status' => 'failed', 'message' => __('The job was lost (agent restarted).'), 'finished_at' => now()]);
            }

            return $record;
        }
        if (($job['status'] ?? 'running') === 'running') {
            return $record;
        }
        $result = $job['result'] ?? [];
        $record->update([
            'status' => $job['status'] === 'succeeded' ? 'succeeded' : 'failed',
            'kind' => $result['kind'] ?? $record->kind,
            'file' => $result['file'] ?? $record->file,
            'size' => $result['size'] ?? $record->size,
            'message' => $job['error']['message'] ?? ($result['safety_backup'] ?? null
                ? __('Backup of the state before the restore: :file', ['file' => $result['safety_backup']]) : null),
            'finished_at' => isset($job['finished_at']) ? Carbon::createFromTimestamp((int) $job['finished_at']) : now(),
        ]);
        if ($record->action === 'backup' && $record->target) {
            $record->target->update(['last_error' => $record->status === 'failed' ? $record->message : null]);
        }

        return $record;
    }

    /**
     * The kind of backup a target's schedule wants for website at $now, or null when nothing is due.
     * Daily: incremental, full on full_day. Weekly: full on full_day.
     */
    public function due(ClientBackupTarget $target, Website $website, Carbon $now): ?string
    {
        if ($target->schedule === 'off') {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $target->schedule_time ?: '03:00') + [1 => 0]);
        $slot = $now->copy()->setTime($h, $m);
        if ($now->lt($slot)) {
            $slot->subDay();
        }
        $fullDay = $slot->format('D') === $target->full_day;
        if ($target->schedule === 'weekly' && ! $fullDay) {
            return null;
        }
        // Only the slot of the last 6 hours is caught up (server down at night), not older ones.
        if ($now->diffInMinutes($slot, true) > 360) {
            return null;
        }
        $done = ClientBackup::where('client_backup_target_id', $target->id)->where('website_id', $website->id)
            ->where('trigger', 'schedule')->where('started_at', '>=', $slot)->exists();
        if ($done) {
            return null;
        }

        return $fullDay ? 'full' : 'incremental';
    }
}
