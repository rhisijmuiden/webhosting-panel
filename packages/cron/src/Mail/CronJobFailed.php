<?php

declare(strict_types=1);

namespace Rhis\Cron\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Rhis\Cron\Models\CronJob;
use Rhis\Cron\Models\CronRun;

/** Tells the customer that a cron job of their website failed. */
class CronJobFailed extends Mailable
{
    public function __construct(public CronJob $job, public CronRun $run) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Cron job failed: :name on :domain', ['name' => $this->job->name, 'domain' => $this->job->website?->domain]));
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Auto-Response-Suppress' => 'All', 'Auto-Submitted' => 'auto-generated']);
    }

    public function content(): Content
    {
        $output = trim((string) $this->run->output);
        $lines = $output === '' ? [] : explode("\n", $output);

        return new Content(markdown: 'cron::mail.failed', with: [
            'output' => implode("\n", array_slice($lines, -30)),
            'url' => route('client.cron.show', $this->job),
            'remindHours' => (int) config('cron.remind_hours', 24),
        ]);
    }
}
