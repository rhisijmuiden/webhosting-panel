<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Rhis\Monitoring\Alerts;

/** Monitoring alert for the administrators. */
class AlertMail extends Mailable
{
    /** @param list<array{type: string, incident: \Rhis\Monitoring\Models\Incident}> $events */
    public function __construct(public array $events, public string $server) {}

    public function envelope(): Envelope
    {
        $bad = collect($this->events)->contains(fn ($e) => $e['type'] !== 'recovery' && $e['incident']->severity === 'critical');

        return new Envelope(subject: ($bad ? '[!] ' : '').Alerts::subject($this->events).' ('.$this->server.')');
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Auto-Response-Suppress' => 'All', 'Auto-Submitted' => 'auto-generated']);
    }

    public function content(): Content
    {
        $items = array_map(fn ($e) => Alerts::item($e), $this->events);

        return new Content(
            view: 'monitoring::mail.alert',
            text: 'monitoring::mail.alert-text',
            with: [
                'eyebrow' => __('Monitoring'),
                'heading' => Alerts::subject($this->events),
                'intro' => Alerts::intro($this->events),
                'greeting' => null,
                'items' => $items,
                'button' => __('Open monitoring'),
                'url' => route('admin.monitoring.index'),
                'footer' => __('Sent by the monitoring of :server. Change the alert channels under Monitoring > Alerts.', ['server' => $this->server]),
                'footerUrl' => route('admin.monitoring.alerts'),
                'footerLink' => __('Alert settings'),
                'logo' => dirname(__DIR__, 2).'/resources/mail/rhis-logo-wit.png',
            ],
        );
    }
}
