<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class CustomerWelcome extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public Customer $customer, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your hosting account at :app', ['app' => \App\Support\Brand::provider()]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'crm-api::mail.welcome');
    }
}
