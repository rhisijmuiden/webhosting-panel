<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ServerNotice extends Mailable
{
    public function __construct(
        public string $level,
        public string $heading,
        public array $lines,
        public ?string $intro,
        public string $server,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = match ($this->level) {
            'problem' => '[!] ',
            'warning' => '[i] ',
            default => '',
        };

        return new Envelope(subject: $prefix.$this->heading.' ('.$this->server.')');
    }

    public function content(): Content
    {
        return new Content(markdown: 'server-admin::mail.notice');
    }
}
