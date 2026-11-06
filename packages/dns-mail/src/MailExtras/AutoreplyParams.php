<?php

declare(strict_types=1);

namespace Rhis\DnsMail\MailExtras;

use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailAutoreply;
use Rhis\DnsMail\Models\MailDomain;

/**
 * Params for agent mail.autoreply_sync: every switched-on autoreply of a
 * mailbox on a locally hosted domain, with the addresses it answers for
 * (its own, "send as", forwardings to it and the same name on alias domains).
 */
final class AutoreplyParams
{
    public static function build(): array
    {
        $today = now()->startOfDay();
        $replies = MailAutoreply::query()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->with('mailbox.domain')->get()
            ->filter(fn (MailAutoreply $r) => $r->mailbox?->domain?->provider === 'rhis');
        if ($replies->isEmpty()) {
            return ['autoreplies' => []];
        }
        $domainIds = $replies->pluck('mailbox.mail_domain_id')->unique()->all();
        $aliases = MailAlias::query()->whereIn('mail_domain_id', $domainIds)->where('local_part', '!=', '')->get()->groupBy('mail_domain_id');
        $aliasDomains = MailDomain::query()->where('provider', 'rhis')->whereIn('alias_of_id', $domainIds)->get()->groupBy('alias_of_id');

        return ['autoreplies' => $replies->map(function (MailAutoreply $r) use ($aliases, $aliasDomains) {
            $box = $r->mailbox;
            $domain = $box->domain;
            $email = $box->local_part . '@' . $domain->domain;
            $addresses = [...(array) $box->send_as];
            foreach ($aliases[$domain->id] ?? [] as $alias) {
                if (in_array($email, (array) $alias->targets, true)) {
                    $addresses[] = $alias->local_part . '@' . $domain->domain;
                }
            }
            foreach ($aliasDomains[$domain->id] ?? [] as $aliasDomain) {
                $addresses[] = $box->local_part . '@' . $aliasDomain->domain;
            }

            return array_filter([
                'email' => $email,
                'subject' => $r->subject,
                'body' => $r->body,
                'start' => $r->starts_on?->toDateString(),
                'end' => $r->ends_on?->toDateString(),
                'days' => max(1, min(30, $r->interval_days)),
                'addresses' => array_values(array_unique(array_map('strtolower', array_diff($addresses, [$email])))),
                'from_name' => $box->display_name ?: null,
            ], fn ($v) => $v !== null);
        })->sortBy('email')->values()->all()];
    }
}
