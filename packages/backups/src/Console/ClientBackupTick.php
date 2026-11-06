<?php

declare(strict_types=1);

namespace Rhis\Backups\Console;

use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Rhis\Backups\ClientBackups;
use Rhis\Backups\Models\ClientBackup;
use Rhis\Backups\Models\ClientBackupTarget;

/** Every few minutes: follow running customer jobs, start scheduled backups, clean old uploads. */
final class ClientBackupTick extends Command
{
    protected $signature = 'backups:client-tick';

    protected $description = 'Start scheduled customer backups and follow running ones';

    public function handle(ClientBackups $backups): int
    {
        foreach (ClientBackup::where('status', 'running')->get() as $record) {
            $backups->sync($record);
            if ($record->status === 'running' && $record->started_at?->lt(now()->subDays(2))) {
                $record->update(['status' => 'failed', 'message' => 'Timed out', 'finished_at' => now()]);
            }
        }

        $running = ClientBackup::where('status', 'running')->where('trigger', 'schedule')->count();
        $max = (int) config('backups.client_parallel', 3);
        foreach (ClientBackupTarget::where('schedule', '!=', 'off')->with('customer')->get() as $target) {
            if ($target->customer?->status === 'suspended') {
                continue;
            }
            foreach ($target->websites() as $website) {
                if ($running >= $max) {
                    break 2;
                }
                if (ClientBackup::where('website_id', $website->id)->where('status', 'running')->exists()) {
                    continue;
                }
                $kind = $backups->due($target, $website, now());
                if ($kind === null) {
                    continue;
                }
                try {
                    $backups->backup($target, $website, $kind, 'schedule');
                    $running++;
                } catch (AgentException $e) {
                    $target->update(['last_error' => $e->getMessage()]);
                    $this->warn("{$website->domain} -> {$target->name}: {$e->getMessage()}");
                }
            }
        }

        // Uploads that were never restored are removed after a day.
        $dir = (string) config('backups.upload_dir');
        if ($dir !== '' && is_dir($dir)) {
            foreach (File::files($dir) as $file) {
                if ($file->getMTime() < time() - 86400 && preg_match('/^[0-9a-f-]{36}\.zip$/', $file->getFilename())) {
                    @unlink($file->getPathname());
                }
            }
        }

        return self::SUCCESS;
    }
}
