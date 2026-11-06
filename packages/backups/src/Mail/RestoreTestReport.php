<?php

declare(strict_types=1);

namespace Rhis\Backups\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Rhis\Backups\Models\RestoreTest;

/** Result of an automatic restore test, to the administrators. */
class RestoreTestReport extends Mailable
{
    public function __construct(public RestoreTest $run, public bool $recovered, public string $server) {}

    public function envelope(): Envelope
    {
        $subject = match (true) {
            $this->recovered => __('Restore test passed again'),
            $this->run->status === 'ok' => __('Restore test passed'),
            $this->run->status === 'warning' => __('Restore test passed with warnings'),
            $this->run->status === 'error' => __('Restore test could not run'),
            default => __('Restore test failed: backups cannot be fully restored'),
        };
        $prefix = $this->run->isProblem() ? '[!] ' : '';

        return new Envelope(subject: $prefix.$subject.' ('.$this->server.')');
    }

    public function content(): Content
    {
        return new Content(markdown: 'backups::mail.restore-test');
    }
}
