<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Console;

use Illuminate\Console\Command;
use Rhis\DnsMail\MailExtras\ImportRunner;

/** Follows running IMAP imports and starts waiting ones. Scheduled every minute. */
final class MailImportsCommand extends Command
{
    protected $signature = 'dns-mail:imports';

    protected $description = 'Follow running mail imports (imapsync) and start waiting ones';

    public function handle(ImportRunner $runner): int
    {
        $result = $runner->tick();
        $this->line("Refreshed {$result['refreshed']}, started {$result['started']}.");

        return self::SUCCESS;
    }
}
