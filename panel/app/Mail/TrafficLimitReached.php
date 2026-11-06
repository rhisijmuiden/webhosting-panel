<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TrafficLimitReached extends Mailable
{
    public function __construct(public Customer $customer, public int $usedBytes, public bool $suspended) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Data traffic limit reached: :customer', ['customer' => $this->customer->displayName()]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.traffic-limit');
    }
}
