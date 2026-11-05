<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Services\Agent\AgentClient;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\Mailbox;

/**
 * Sends the complete mail state to the agent. The agent rewrites its files
 * from the full list, so calling this after every change is safe.
 */
final class MailPusher
{
    public function __construct(private readonly AgentClient $agent, private readonly DnsMailConfig $config)
    {
    }

    public function push(): array
    {
        $routing = $this->agent->call('mail.set_routing', $this->routingParams(), 60);
        $boxes = $this->agent->call('mail.mailboxes_sync', $this->mailboxParams(), 60);

        return ['routing' => $routing, 'mailboxes' => $boxes];
    }

    public function routingParams(): array
    {
        return [
            'mail_host' => $this->config->mailHost(),
            'brand' => (string) config('dns-mail.server.brand', 'RHIS'),
            'domains' => MailDomain::query()->orderBy('domain')->get()->map(fn (MailDomain $d) => array_filter([
                'domain' => $d->domain,
                'provider' => $d->provider,
                'mta_sts_mode' => $d->mta_sts_mode ?: null,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    public function mailboxParams(): array
    {
        $local = MailDomain::query()->where('provider', 'rhis')->pluck('domain', 'id');

        return [
            'mailboxes' => Mailbox::query()->whereIn('mail_domain_id', $local->keys())->get()
                ->map(fn (Mailbox $m) => [
                    'email' => $m->local_part . '@' . $local[$m->mail_domain_id],
                    'password_hash' => $m->password_hash,
                    'quota_mb' => $m->quota_mb,
                    'enabled' => $m->enabled,
                    'send_as' => array_values((array) $m->send_as),
                ])->values()->all(),
            'aliases' => MailAlias::query()->whereIn('mail_domain_id', $local->keys())->get()
                ->map(fn (MailAlias $a) => [
                    'address' => $a->local_part . '@' . $local[$a->mail_domain_id],
                    'targets' => array_values($a->targets),
                ])->values()->all(),
        ];
    }

    /** Refresh mailbox usage from Dovecot quota. */
    public function refreshUsage(): void
    {
        $usage = $this->agent->call('mail.mailbox_usage', [], 120)['usage'] ?? [];
        Mailbox::with('domain')->get()->each(function (Mailbox $m) use ($usage) {
            if (isset($usage[$m->address()])) {
                $m->update(['used_kb' => (int) $usage[$m->address()]['used_kb']]);
            }
        });
    }
}
