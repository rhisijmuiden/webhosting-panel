<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your DNS now runs on RHIS Cloudflare": sent once when the zone becomes active. */
class DnsLiveNotification extends Notification
{
    /** @param list<string> $nameServers */
    public function __construct(public readonly string $domain, public readonly array $nameServers, public readonly int $mailDomainId)
    {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('client.mail.domains.show', [$this->mailDomainId, 'tab' => 'dns']);

        return (new MailMessage())
            ->subject(__('DNS for :domain is now live at RHIS', ['domain' => $this->domain]))
            ->greeting(__('Good news!'))
            ->line(__('The nameservers of :domain now point to the RHIS Cloudflare network. From now on we keep the DNS records for your website and mail up to date for you.', ['domain' => $this->domain]))
            ->line(__('Nameservers: :ns', ['ns' => implode(', ', $this->nameServers)]))
            ->line(__('You can see and change your records in the client portal.'))
            ->action(__('Open DNS records'), $url)
            ->line(__('Some providers keep the old answers in their cache for a few more hours. You do not need to do anything for that.'));
    }
}
