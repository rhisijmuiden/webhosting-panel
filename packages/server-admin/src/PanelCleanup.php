<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Rhis\ServerAdmin\Models\ServerTask;
use Rhis\ServerAdmin\Models\ServerTaskRun;

/** Old rows and files of the panel itself. */
final class PanelCleanup
{
    public function run(ServerTask $task, ServerTaskRun $run): array
    {
        $done = [];
        $activity = (int) $task->option('activity_days', 365);
        if ($activity > 0) {
            $done['activity'] = ActivityLog::where('created_at', '<', now()->subDays($activity))->delete();
        }
        $runs = (int) $task->option('runs_days', 90);
        if ($runs > 0) {
            $done['runs'] = ServerTaskRun::where('status', '!=', 'running')->where('started_at', '<', now()->subDays($runs))->delete();
        }
        $failed = (int) $task->option('failed_jobs_days', 30);
        if ($failed > 0 && Schema::hasTable('failed_jobs')) {
            $done['failed_jobs'] = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays($failed))->delete();
        }
        $logDays = (int) $task->option('log_days', 14);
        $done['log_files'] = 0;
        if ($logDays > 0 && is_dir(storage_path('logs'))) {
            foreach (File::files(storage_path('logs')) as $file) {
                if ($file->getExtension() === 'log' && $file->getMTime() < now()->subDays($logDays)->getTimestamp() && $file->getFilename() !== 'laravel.log') {
                    File::delete($file->getPathname());
                    $done['log_files']++;
                }
            }
        }
        foreach ($done as $k => $n) {
            $run->appendLog("$k: $n");
        }

        return ['succeeded', __(':n old records and files removed', ['n' => array_sum($done)]), $done];
    }
}
