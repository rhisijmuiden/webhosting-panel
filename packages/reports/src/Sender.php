<?php

declare(strict_types=1);

namespace Rhis\Reports;

use App\Models\ActivityLog;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Rhis\Reports\Mail\MonthlyReportMail;
use Rhis\Reports\Models\Preference;
use Rhis\Reports\Models\Send;
use Throwable;

/** Sends the monthly report to every customer who wants it, once per month. */
final class Sender
{
    public function __construct(private readonly Options $options, private readonly ReportBuilder $builder) {}

    /** Is it time for the scheduled run? From the configured day and time on, until every report of last month is out. */
    public function due(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        [$h, $m] = array_map('intval', explode(':', $this->options->time()));

        return $this->options->enabled() && $now->gte($now->startOfMonth()->setDay($this->options->day())->setTime($h, $m));
    }

    /** Customers that should get the report (active, with a website, not opted out). */
    public function recipients()
    {
        return Customer::where('status', '!=', 'suspended')->whereHas('websites')->orderBy('id')->get()
            ->filter(fn (Customer $c) => Preference::for($c)->recipients() !== []);
    }

    /** @return array{sent: int, failed: int, skipped: int} */
    public function run(CarbonImmutable $month, bool $force = false, ?int $customerId = null): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        // A test copy an admin sent to themselves does not count.
        $done = Send::where('period', $month->format('Y-m'))->where('status', 'sent')->whereIn('kind', ['schedule', 'manual'])->pluck('customer_id')->flip();
        // A mail that keeps failing is retried on the next runs, at most three times per month.
        $failed = Send::where('period', $month->format('Y-m'))->where('status', 'failed')->where('kind', 'schedule')
            ->selectRaw('customer_id, count(*) as n')->groupBy('customer_id')->pluck('n', 'customer_id');
        foreach ($this->recipients() as $customer) {
            if ($customerId !== null && $customer->id !== $customerId) {
                continue;
            }
            if (! $force && (isset($done[$customer->id]) || ($failed[$customer->id] ?? 0) >= 3)) {
                $out['skipped']++;

                continue;
            }
            $out[$this->send($customer, $month, 'schedule') ? 'sent' : 'failed']++;
        }

        return $out;
    }

    /** Send one report now. False when nobody can receive it or sending failed (logged as failed). */
    public function send(Customer $customer, CarbonImmutable $month, string $kind = 'manual', ?array $to = null): bool
    {
        $to ??= Preference::for($customer)->recipients();
        if ($to === []) {
            return false;
        }
        $locale = $customer->locale ?: config('app.locale');
        $row = ['customer_id' => $customer->id, 'period' => $month->format('Y-m'), 'kind' => $kind,
            'recipients' => $to, 'locale' => $locale, 'sent_at' => now()];
        try {
            $report = $this->builder->build($customer, $month);
            $mail = Mail::to($to)->locale($locale);
            if ($bcc = $this->options->bcc()) {
                $mail->bcc($bcc);
            }
            $mail->send(new MonthlyReportMail($customer, $report, $this->options->supportEmail()));
        } catch (Throwable $e) {
            report($e);
            Send::create($row + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            return false;
        }
        Send::create($row + ['status' => 'sent', 'summary' => ReportBuilder::summary($report)]);
        ActivityLog::record('report.sent', $customer->displayName(), ['period' => $row['period'], 'kind' => $kind, 'to' => $to], $customer->id);

        return true;
    }
}
