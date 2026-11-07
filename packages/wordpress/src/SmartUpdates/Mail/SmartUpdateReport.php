<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Rhis\WordPress\SmartUpdates\Models\UpdateRun;

/** What the smart update of a WordPress site did (to the customer), or a problem (to the admins). */
class SmartUpdateReport extends Mailable
{
    public function __construct(public UpdateRun $run, public string $area = 'client') {}

    public function envelope(): Envelope
    {
        $site = $this->run->install?->label();

        return new Envelope(subject: match ($this->run->status) {
            'updated' => __('WordPress updated: :site', ['site' => $site]),
            'rolled_back' => __('WordPress update rolled back: :site', ['site' => $site]),
            'notified' => __('WordPress updates waiting: :site', ['site' => $site]),
            default => __('WordPress update failed: :site', ['site' => $site]),
        });
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Auto-Response-Suppress' => 'All', 'Auto-Submitted' => 'auto-generated']);
    }

    public function content(): Content
    {
        $route = $this->area === 'admin' ? 'admin.wordpress.smart.runs.show' : 'client.wordpress.smart.runs.show';

        return new Content(markdown: 'wordpress::smart-updates.mail.report', with: [
            'install' => $this->run->install,
            'changes' => $this->run->changes(),
            'url' => route($route, $this->run),
            'settingsUrl' => route($this->area === 'admin' ? 'admin.wordpress.smart.install' : 'client.wordpress.smart.install', $this->run->wordpress_install_id),
        ]);
    }
}
