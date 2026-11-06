<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Services\Agent\AgentClient;
use Rhis\DnsMail\MailExtras\AutoreplyParams;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\MailSenderRule;
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
        $spam = $this->agent->call('mail.spam_policy_sync', $this->spamParams(), 60);
        // After the mailboxes: the agent writes autoreplies into their homes.
        $autoreply = $this->agent->call('mail.autoreply_sync', AutoreplyParams::build(), 60);

        return ['routing' => $routing, 'mailboxes' => $boxes, 'spam' => $spam, 'autoreply' => $autoreply];
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
        $mailboxes = Mailbox::query()->whereIn('mail_domain_id', $local->keys())->get();
        $aliases = MailAlias::query()->whereIn('mail_domain_id', $local->keys())->get();
        $aliasDomains = $this->aliasDomains($local->keys()->all());

        return [
            'mailboxes' => $mailboxes->map(fn (Mailbox $m) => [
                'email' => $m->local_part . '@' . $local[$m->mail_domain_id],
                'password_hash' => $m->password_hash,
                'quota_mb' => $m->quota_mb,
                'enabled' => $m->enabled,
                // A mailbox may also send as its address on the domain aliases.
                'send_as' => array_values(array_unique([
                    ...(array) $m->send_as,
                    ...array_map(fn (string $alias) => $m->local_part . '@' . $alias, $aliasDomains[$m->mail_domain_id] ?? []),
                ])),
            ])->values()->all(),
            'aliases' => [
                ...$aliases->map(fn (MailAlias $a) => [
                    'address' => $a->local_part . '@' . $local[$a->mail_domain_id],
                    'targets' => array_values($a->targets),
                ])->values()->all(),
                ...$this->domainAliasEntries($local, $mailboxes, $aliases, $aliasDomains),
            ],
        ];
    }

    /**
     * Local alias domains per main mail domain id.
     *
     * @param list<int> $localIds
     * @return array<int, list<string>>
     */
    private function aliasDomains(array $localIds): array
    {
        return MailDomain::query()->where('provider', 'rhis')->whereIn('alias_of_id', $localIds)->orderBy('domain')->get()
            ->groupBy('alias_of_id')->map(fn ($group) => $group->pluck('domain')->all())->all();
    }

    /**
     * user@alias -> user@main for every mailbox and alias of the main domain.
     * Addresses the alias domain has itself (own mailbox or alias) win.
     */
    private function domainAliasEntries($local, $mailboxes, $aliases, array $aliasDomains): array
    {
        $taken = [];
        foreach ($mailboxes as $m) {
            $taken[$m->local_part . '@' . $local[$m->mail_domain_id]] = true;
        }
        foreach ($aliases as $a) {
            $taken[$a->local_part . '@' . $local[$a->mail_domain_id]] = true;
        }
        $entries = [];
        foreach ($aliasDomains as $mainId => $domains) {
            $main = $local[$mainId] ?? null;
            if ($main === null) {
                continue;
            }
            $sources = [
                ...$mailboxes->where('mail_domain_id', $mainId)->map(fn (Mailbox $m) => [$m->local_part, [$m->local_part . '@' . $main]])->all(),
                ...$aliases->where('mail_domain_id', $mainId)->map(fn (MailAlias $a) => [$a->local_part, array_values($a->targets)])->all(),
            ];
            foreach ($domains as $domain) {
                foreach ($sources as [$localPart, $targets]) {
                    $address = $localPart . '@' . $domain;
                    if (!isset($taken[$address])) {
                        $taken[$address] = true;
                        $entries[] = ['address' => $address, 'targets' => $targets];
                    }
                }
            }
        }

        return $entries;
    }

    public function spamParams(): array
    {
        $rules = MailSenderRule::query()->get()->groupBy('mail_domain_id');

        return [
            'default_quarantine_score' => $this->config->defaultQuarantineScore(),
            'domains' => MailDomain::query()->where('provider', 'rhis')->orderBy('domain')->get()
                ->map(fn (MailDomain $d) => [
                    'domain' => $d->domain,
                    'quarantine_score' => $d->spam_quarantine_score,
                    'junk' => $d->spam_junk,
                    'allow' => ($rules[$d->id] ?? collect())->where('type', 'allow')->pluck('value')->values()->all(),
                    'block' => ($rules[$d->id] ?? collect())->where('type', 'block')->pluck('value')->values()->all(),
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
