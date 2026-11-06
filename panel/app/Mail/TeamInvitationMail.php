<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use App\Support\TeamAreas;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TeamInvitationMail extends Mailable
{
    public function __construct(public TeamInvitation $invitation, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':name invites you to manage their hosting', ['name' => $this->inviterName()]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.team-invitation', with: [
            'inviter' => $this->inviterName(),
            'url' => route('team.invitation.show', $this->token),
            'areas' => collect(TeamAreas::all())->only($this->invitation->areas)->map(fn ($a) => __($a['label']))->join(', '),
            'websites' => $this->invitation->all_websites
                ? __('All websites')
                : \App\Models\Website::withoutGlobalScopes()->whereIn('id', $this->invitation->website_ids)->orderBy('domain')->pluck('domain')->join(', '),
        ]);
    }

    private function inviterName(): string
    {
        $customer = $this->invitation->customer;

        return $this->invitation->inviter?->name
            ? $this->invitation->inviter->name.' ('.$customer->displayName().')'
            : $customer->displayName();
    }
}
