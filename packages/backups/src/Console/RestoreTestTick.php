<?php

declare(strict_types=1);

namespace Rhis\Backups\Console;

use Illuminate\Console\Command;
use Rhis\Backups\RestoreTests\RestoreTester;

/** Every few minutes: follow the running restore test and start a scheduled one when it is due. */
final class RestoreTestTick extends Command
{
    protected $signature = 'backups:restore-test-tick';

    protected $description = 'Start scheduled backup restore tests and follow running ones';

    public function handle(RestoreTester $tester): int
    {
        $run = $tester->tick();
        if ($run) {
            $this->info("Restore test #{$run->id}: {$run->status}");
        }

        return self::SUCCESS;
    }
}
