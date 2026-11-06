<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Rhis\DnsMail\Models\MailLogMessage;

/** Outgoing volume per customer and per sender, to spot spammers. */
final class MailLogReport
{
    /** Rspamd marks mail as spam from this score (see installer actions.conf). */
    public const SPAM_SCORE = 6;

    public function __construct(private readonly MailLogSettings $settings)
    {
    }

    /**
     * Customers that sent mail out since $since, busiest first.
     *
     * @return Collection<int, object{customer: ?Customer, customer_id: ?int, messages: int, recipients: int, bounced: int, rejected: int, deferred: int, spam: int, last_hour: int, bounce_percent: int, flags: array<int, string>}>
     */
    public function customers(Carbon $since, int $limit = 10): Collection
    {
        $hour = now()->subHour();
        $rows = MailLogMessage::query()
            ->where('direction', 'outgoing')->where('logged_at', '>=', $since)
            ->groupBy('customer_id')
            ->select('customer_id')
            ->selectRaw('COUNT(*) AS messages')
            ->selectRaw('SUM(recipient_count) AS recipients')
            ->selectRaw("SUM(CASE WHEN status IN ('bounced', 'expired') THEN 1 ELSE 0 END) AS bounced")
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected")
            ->selectRaw("SUM(CASE WHEN status IN ('deferred', 'mixed') THEN 1 ELSE 0 END) AS deferred")
            ->selectRaw('SUM(CASE WHEN spam_score >= ? THEN 1 ELSE 0 END) AS spam', [self::SPAM_SCORE])
            ->selectRaw('SUM(CASE WHEN logged_at >= ? THEN 1 ELSE 0 END) AS last_hour', [$hour])
            ->orderByDesc('messages')->limit($limit)->get();
        $customers = Customer::whereIn('id', $rows->pluck('customer_id')->filter())->get()->keyBy('id');

        return $rows->map(fn ($r) => $this->decorate($r, $customers->get($r->customer_id)));
    }

    /**
     * Logins and website users that sent the most since $since.
     *
     * @return Collection<int, object{sender: string, kind: string, customer_id: ?int, messages: int, recipients: int, failed: int}>
     */
    public function senders(Carbon $since, int $limit = 10): Collection
    {
        $who = DB::raw("COALESCE(sasl_username, system_user, sender, '') AS who");

        return MailLogMessage::query()
            ->where('direction', 'outgoing')->where('logged_at', '>=', $since)
            ->groupBy('who', 'origin', 'customer_id')
            ->select([$who, 'origin', 'customer_id'])
            ->selectRaw('COUNT(*) AS messages')
            ->selectRaw('SUM(recipient_count) AS recipients')
            ->selectRaw("SUM(CASE WHEN status IN ('bounced', 'expired', 'rejected') THEN 1 ELSE 0 END) AS failed")
            ->orderByDesc('messages')->limit($limit)->get()
            ->map(fn ($r) => (object) [
                'sender' => (string) $r->who, 'kind' => (string) $r->origin, 'customer_id' => $r->customer_id,
                'messages' => (int) $r->messages, 'recipients' => (int) $r->recipients, 'failed' => (int) $r->failed,
            ]);
    }

    /** @return array{messages: int, outgoing: int, incoming: int, rejected: int, spam: int, flagged: int} */
    public function totals(Carbon $since): array
    {
        $base = MailLogMessage::where('logged_at', '>=', $since);

        return [
            'messages' => (clone $base)->count(),
            'outgoing' => (clone $base)->where('direction', 'outgoing')->count(),
            'incoming' => (clone $base)->where('direction', 'incoming')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
            'spam' => (clone $base)->where('spam_score', '>=', self::SPAM_SCORE)->count(),
            'flagged' => $this->customers($since, 500)->filter(fn ($c) => $c->flags !== [])->count(),
        ];
    }

    private function decorate(object $r, ?Customer $customer): object
    {
        $messages = (int) $r->messages;
        $failed = (int) $r->bounced + (int) $r->rejected;
        $percent = $messages > 0 ? (int) round($failed / $messages * 100) : 0;
        $flags = [];
        if ((int) $r->last_hour >= $this->settings->alertPerHour()) {
            $flags[] = 'volume';
        }
        if ($messages >= 20 && $percent >= $this->settings->alertBouncePercent()) {
            $flags[] = 'bounces';
        }
        if ((int) $r->spam > 0 && (int) $r->spam >= max(5, (int) ceil($messages * 0.1))) {
            $flags[] = 'spam';
        }

        return (object) [
            'customer' => $customer, 'customer_id' => $r->customer_id, 'messages' => $messages,
            'recipients' => (int) $r->recipients, 'bounced' => (int) $r->bounced, 'rejected' => (int) $r->rejected,
            'deferred' => (int) $r->deferred, 'spam' => (int) $r->spam, 'last_hour' => (int) $r->last_hour,
            'bounce_percent' => $percent, 'flags' => $flags,
        ];
    }
}
