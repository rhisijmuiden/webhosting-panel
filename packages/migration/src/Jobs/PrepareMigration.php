<?php

declare(strict_types=1);

namespace Rhis\Migration\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Services\Migrator;

/** Creates the customer, website and mailboxes, then hands the copying to the agent. */
class PrepareMigration implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public MigrationItem $item) {}

    public function handle(Migrator $migrator): void
    {
        $migrator->prepare($this->item->fresh());
    }

    public function failed(?\Throwable $e): void
    {
        $item = $this->item->fresh();
        $item?->log($e?->getMessage() ?? 'Job failed', [], 'error');
        $item?->update(['status' => 'failed', 'error' => $e?->getMessage() ?? 'Job failed']);
    }
}
