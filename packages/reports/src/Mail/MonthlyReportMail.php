<?php

declare(strict_types=1);

namespace Rhis\Reports\Mail;

use App\Models\Customer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** The RHIS branded monthly report: what the hosting did for the customer last month. */
class MonthlyReportMail extends Mailable
{
    public function __construct(
        public Customer $customer,
        public array $report,
        public ?string $supportEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your hosting in :month', ['month' => $this->monthName()]));
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Auto-Response-Suppress' => 'All',
            'Auto-Submitted' => 'auto-generated',
            'List-Unsubscribe' => '<'.route('client.reports.index').'#delivery>',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'reports::mail.report',
            text: 'reports::mail.report-text',
            with: self::viewData($this->customer, $this->report, $this->supportEmail, $this->monthName()) + [
                'logo' => dirname(__DIR__, 2).'/resources/mail/logo-wit.png',
            ],
        );
    }

    public function monthName(): string
    {
        return $this->report['from']->locale($this->locale ?? app()->getLocale())->translatedFormat('F Y');
    }

    /** Shared with the web version in the client panel. */
    public static function viewData(Customer $customer, array $report, ?string $supportEmail, string $monthName): array
    {
        return [
            'customer' => $customer,
            'r' => $report,
            'monthName' => $monthName,
            'supportEmail' => $supportEmail,
            'panelUrl' => route('client.dashboard'),
            'reportUrl' => route('client.reports.index', ['month' => $report['period']]),
            'settingsUrl' => route('client.reports.index').'#delivery',
            'securityUrl' => \Route::has('client.vulns.index') ? route('client.vulns.index') : route('client.dashboard'),
            'wordpressUrl' => \Route::has('client.wordpress.index') ? route('client.wordpress.index') : route('client.dashboard'),
            'backupsUrl' => \Route::has('client.backups.index') ? route('client.backups.index') : route('client.dashboard'),
        ];
    }
}
