<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Mail;

use App\Models\Customer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Collection;
use Rhis\Vulnerabilities\Models\Finding;

/** The RHIS branded mail that tells a customer what was found on their websites and how to fix it. */
class SecurityAlertMail extends Mailable
{
    public function __construct(
        public Customer $customer,
        public Collection $findings,
        public bool $reminder = false,
        public ?string $supportEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        $sites = $this->findings->map(fn (Finding $f) => $f->website->domain)->unique()->values();
        $site = $sites->count() === 1 ? $sites->first() : trans_choice(':count website|:count websites', $sites->count());
        $subject = $this->worst() === 'critical' || $this->hasMalware()
            ? __('Urgent: security problem on :site', ['site' => $site])
            : __('Action needed: security update for :site', ['site' => $site]);

        return new Envelope(subject: $this->reminder ? __('Reminder').': '.$subject : $subject);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Auto-Response-Suppress' => 'All', 'Auto-Submitted' => 'auto-generated']);
    }

    public function content(): Content
    {
        $groups = $this->findings->groupBy(fn (Finding $f) => $f->siteLabel());

        return new Content(
            view: 'vulnerabilities::mail.alert',
            text: 'vulnerabilities::mail.alert-text',
            with: [
                'groups' => $groups,
                'worst' => $this->worst(),
                'malware' => $this->hasMalware(),
                'count' => $this->findings->count(),
                'panelUrl' => $this->panelUrl(),
                'settingsUrl' => route('client.vulns.index').'#notifications',
                'fixUrl' => fn (Finding $f) => $this->fixUrl($f),
                'copyrights' => $this->findings->pluck('copyright')->filter()->unique()->values(),
                'logo' => dirname(__DIR__, 2).'/resources/mail/logo-wit.png',
            ],
        );
    }

    public function worst(): string
    {
        return $this->findings->where('kind', 'vulnerability')->pluck('severity')
            ->sortBy(fn ($s) => Finding::rank($s))->first() ?? 'critical';
    }

    public function hasMalware(): bool
    {
        return $this->findings->contains(fn (Finding $f) => $f->isMalware());
    }

    private function panelUrl(): string
    {
        return route('client.vulns.index');
    }

    private function fixUrl(Finding $f): string
    {
        if ($f->isIntegrity() && ($url = $f->clientFixUrl())) {
            return $url;
        }
        if (! $f->isMalware() && $f->install && config('wordpress.customer_toolkit') && \Route::has('client.wordpress.show')) {
            return route('client.wordpress.show', $f->install);
        }

        return route('client.vulns.index');
    }
}
