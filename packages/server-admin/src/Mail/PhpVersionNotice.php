<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin\Mail;

use App\Models\Customer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/** To a customer: a PHP version of their websites loses security support and moves to a newer version. */
class PhpVersionNotice extends Mailable
{
    public function __construct(
        public Customer $customer,
        public array $domains,
        public string $version,
        public ?string $target,
        public Carbon $at,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your websites move to a newer PHP version'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'server-admin::mail.php-notice');
    }
}
