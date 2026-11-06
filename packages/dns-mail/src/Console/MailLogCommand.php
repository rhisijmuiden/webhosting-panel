<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Console;

use Illuminate\Console\Command;
use Rhis\DnsMail\Services\MailLogSettings;
use Rhis\DnsMail\Services\MailLogCollector;
use Throwable;

/** Every minute: read new Postfix/Rspamd log events into the mail log. */
class MailLogCommand extends Command
{
    protected $signature = 'dns-mail:maillog {--prune : Only delete entries older than the retention period}';

    protected $description = 'Collect the Postfix mail log and Rspamd scan results for the admin mail log';

    public function handle(MailLogCollector $collector, MailLogSettings $settings): int
    {
        if ($this->option('prune')) {
            $this->info($collector->prune($settings->retentionDays()) . ' message(s) deleted');

            return self::SUCCESS;
        }
        try {
            $r = $collector->collect();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$r['events']} event(s), {$r['messages']} message(s), {$r['spam']} spam score(s)");

        return self::SUCCESS;
    }
}
