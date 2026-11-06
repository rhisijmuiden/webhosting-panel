<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Console;

use Illuminate\Console\Command;
use Rhis\Vulnerabilities\Notifier;

final class NotifyCommand extends Command
{
    protected $signature = 'whp:vuln:notify';

    protected $description = 'Mail customers about new security problems and send reminders';

    public function handle(Notifier $notifier): int
    {
        $this->info($notifier->run().' customers mailed.');

        return self::SUCCESS;
    }
}
