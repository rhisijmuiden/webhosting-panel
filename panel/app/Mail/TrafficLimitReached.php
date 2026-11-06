<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TrafficLimitReached extends Mailable
{
    public function __construct(public Subscription $subscription, public int $usedBytes, public bool $suspended) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Data traffic limit reached: :domain', ['domain' => $this->subscription->website?->domain]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.traffic-limit');
    }
}
