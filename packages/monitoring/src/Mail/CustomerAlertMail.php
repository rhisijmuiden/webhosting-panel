<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Mail;

use App\Models\Customer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Rhis\Monitoring\Alerts;

/** Tells a customer that their website does not answer, or works again. */
class CustomerAlertMail extends Mailable
{
    /** @param list<array{type: string, incident: \Rhis\Monitoring\Models\Incident}> $events */
    public function __construct(public Customer $customer, public array $events) {}

    private function allSolved(): bool
    {
        return collect($this->events)->every(fn ($e) => $e['type'] === 'recovery');
    }

    private function sites(): string
    {
        return collect($this->events)->map(fn ($e) => $e['incident']->title)->unique()->implode(', ');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->allSolved()
            ? __(':site works again', ['site' => $this->sites()])
            : __(':site is not reachable', ['site' => $this->sites()]));
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Auto-Response-Suppress' => 'All', 'Auto-Submitted' => 'auto-generated']);
    }

    public function content(): Content
    {
        $items = array_map(function ($e) {
            $item = Alerts::item($e);
            $item['headline'] = $e['type'] === 'recovery'
                ? __(':site works again', ['site' => $e['incident']->title])
                : __(':site is not reachable', ['site' => $e['incident']->title]);
            $item['advice'] = null;

            return $item;
        }, $this->events);

        return new Content(
            view: 'monitoring::mail.alert',
            text: 'monitoring::mail.alert-text',
            with: [
                'eyebrow' => __('Website status'),
                'heading' => $this->allSolved() ? __('Your website works again') : __('Your website is not reachable'),
                'greeting' => __('Hello :name,', ['name' => $this->customer->name]),
                'intro' => $this->allSolved()
                    ? __('Good news: the problem is over and your website answers normally again.')
                    : __('We check your websites every few minutes. Right now the website below does not answer. We have been alerted too and are looking into it. You do not need to do anything, unless you changed something on your website just now.'),
                'items' => $items,
                'button' => __('View the status'),
                'url' => route('client.monitoring.index'),
                'footer' => __('You receive this mail because you asked for it in the control panel.'),
                'footerUrl' => route('client.monitoring.index').'#alerts',
                'footerLink' => __('Change who receives these mails'),
                'logo' => dirname(__DIR__, 2).'/resources/mail/rhis-logo-wit.png',
            ],
        );
    }
}
