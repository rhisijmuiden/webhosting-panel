<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\DatabaseManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RemoveWebsite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Website $website, public bool $keepFiles = false) {}

    public function handle(AgentClient $agent): void
    {
        try {
            $agent->call('site.remove', ['domain' => $this->website->domain, 'keep_files' => $this->keepFiles], 540);
            // Its databases go with it, unless the files (and so the data) are kept.
            if (! $this->keepFiles) {
                app(DatabaseManager::class)->dropAllFor($this->website);
            }
            $this->website->delete();
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                $this->website->delete();

                return;
            }
            $this->website->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
        }
    }
}
