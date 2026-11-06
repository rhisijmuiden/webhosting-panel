<?php

declare(strict_types=1);

namespace Rhis\Reports\Console;

use Illuminate\Console\Command;
use Rhis\Reports\ReportBuilder;
use Rhis\Reports\Sender;

class SendCommand extends Command
{
    protected $signature = 'whp:reports:send
        {--month= : The month the report is about (YYYY-MM), default last month}
        {--customer= : Only this customer id}
        {--force : Also send when this month was already sent, and outside the configured day}';

    protected $description = 'Send the monthly hosting report to customers (runs hourly; sends from the configured day on, once per month)';

    public function handle(Sender $sender): int
    {
        $month = $this->option('month');
        if ($month !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month)) {
            $this->error('Use --month=YYYY-MM');

            return self::INVALID;
        }
        $force = (bool) $this->option('force');
        if (! $force && $month === null && ! $sender->due()) {
            $this->line('Not due yet (or monthly reports are switched off).');

            return self::SUCCESS;
        }
        $period = $month ? ReportBuilder::month((string) $month) : ReportBuilder::previousMonth();
        $result = $sender->run($period, $force, $this->option('customer') ? (int) $this->option('customer') : null);
        $this->info(sprintf('Report %s: %d sent, %d failed, %d already sent.', $period->format('Y-m'), $result['sent'], $result['failed'], $result['skipped']));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
