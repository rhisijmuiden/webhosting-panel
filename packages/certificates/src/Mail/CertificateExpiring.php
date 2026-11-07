<?php

declare(strict_types=1);

namespace Rhis\Certificates\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Rhis\Certificates\Models\SiteCertificate;

class CertificateExpiring extends Mailable
{
    /** @param list<SiteCertificate> $certificates */
    public function __construct(public array $certificates, public bool $admin) {}

    public function envelope(): Envelope
    {
        $expired = collect($this->certificates)->contains(fn (SiteCertificate $c) => $c->isExpired());
        $first = $this->certificates[0]->website?->domain ?? '';
        $more = count($this->certificates) - 1;
        $subject = $expired
            ? __('SSL certificate expired: :domain', ['domain' => $first])
            : __('SSL certificate expires soon: :domain', ['domain' => $first]);

        return new Envelope(subject: $more > 0 ? $subject.' '.__('(+:count more)', ['count' => $more]) : $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'certificates::mail.expiring');
    }
}
