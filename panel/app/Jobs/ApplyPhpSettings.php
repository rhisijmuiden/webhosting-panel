<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use App\Services\PhpSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** After a change of the server defaults or maximums: write the PHP settings of every website again. */
class ApplyPhpSettings implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(PhpSettings $php): void
    {
        Website::with('customer.package')->where('status', 'active')->orderBy('id')->each(function (Website $website) use ($php) {
            try {
                $php->apply($website);
            } catch (AgentException $e) {
                ActivityLog::record('website.php_settings_failed', $website->domain, ['error' => $e->getMessage()], $website->customer_id);
            }
        });
    }
}
