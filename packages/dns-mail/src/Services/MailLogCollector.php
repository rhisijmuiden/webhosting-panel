<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Rhis\DnsMail\Models\Mailbox;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\MailLogMessage;
use Rhis\DnsMail\Models\MailLogRecipient;
use Throwable;

/**
 * Reads new Postfix events from the agent (mail.log_read) once a minute,
 * merges them per queue id into mail_log_messages / mail_log_recipients,
 * adds Rspamd's verdict (mail.spam_history) and ties every message to a
 * customer: SMTP login -> mailbox, sendmail uid -> website, recipient or
 * sender domain -> mail domain.
 */
final class MailLogCollector
{
    public const CURSOR_KEY = 'dnsmail.maillog_cursor';

    private const MAX_ROUNDS = 10;

    private const FINAL = ['sent', 'bounced', 'rejected', 'expired'];

    /** @var array<string, MailLogMessage> messages touched in this run, by queue id */
    private array $byQueue = [];

    /** @var array<int, MailLogMessage> */
    private array $touched = [];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Settings $settings,
    ) {
    }

    /** @return array{events: int, messages: int, spam: int} */
    public function collect(): array
    {
        $this->byQueue = $this->touched = [];
        $events = 0;
        $cursor = $this->settings->get(self::CURSOR_KEY);
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $params = $cursor ? ['cursor' => $cursor] : ['since_hours' => 1];
            $result = $this->agent->call('mail.log_read', $params + ['limit' => 5000], 120);
            foreach ($result['events'] ?? [] as $event) {
                if (is_array($event)) {
                    $this->apply($event);
                    $events++;
                }
            }
            $this->finish();
            if (($result['cursor'] ?? null) && $result['cursor'] !== $cursor) {
                $cursor = $result['cursor'];
                $this->settings->set(self::CURSOR_KEY, $cursor);
            }
            if (empty($result['more'])) {
                break;
            }
        }
        $messages = count($this->touched);
        $spam = $this->collectSpam();

        return ['events' => $events, 'messages' => $messages, 'spam' => $spam];
    }

    /** Delete log rows older than the retention period. */
    public function prune(int $days): int
    {
        return MailLogMessage::where('logged_at', '<', now()->subDays(max(1, $days)))->delete();
    }

    public function apply(array $e): void
    {
        $time = isset($e['time']) && is_numeric($e['time']) ? Carbon::createFromTimestamp((float) $e['time']) : now();
        $kind = (string) ($e['kind'] ?? '');
        $qid = isset($e['qid']) && is_string($e['qid']) && $e['qid'] !== '' ? $e['qid'] : null;

        if ($qid === null) {
            if ($kind === 'reject') {
                $this->noQueueReject($e, $time);
            }

            return;
        }
        $m = $this->message($qid, $time);

        switch ($kind) {
            case 'client':
                $m->service = $this->str($e['service'] ?? null, 16);
                $m->client_ip = $this->str($e['client_ip'] ?? null, 64);
                $m->client_host = $this->str($e['client_host'] ?? null, 255);
                $m->sasl_username = $this->lower($e['sasl_username'] ?? null);
                $m->origin = $m->sasl_username ? 'authenticated' : ($this->isLoopback($m->client_ip) ? 'system' : 'inbound');
                break;
            case 'pickup':
                $m->service = 'pickup';
                $m->system_user = $this->str($e['user'] ?? null, 64) ?? (isset($e['uid']) ? 'uid ' . (int) $e['uid'] : null);
                $m->sender ??= $this->lower($e['from'] ?? null);
                $m->origin = $m->origin === 'bounce' ? 'bounce' : 'system';
                break;
            case 'message_id':
                $m->message_id = $this->str($e['message_id'] ?? null, 255);
                break;
            case 'queued':
                $m->sender = $this->lower($e['from'] ?? null) ?? '';
                if (isset($e['size'])) {
                    $m->size = (int) $e['size'];
                }
                if (isset($e['nrcpt'])) {
                    $m->recipient_count = (int) $e['nrcpt'];
                }
                break;
            case 'expired':
                $m->status = 'expired';
                $m->finished_at = $time;
                break;
            case 'reject':
                $m->status = 'rejected';
                $m->reject_reason = $this->str($e['reason'] ?? null, 500);
                $m->sender ??= $this->lower($e['from'] ?? null);
                $m->client_ip ??= $this->str($e['client_ip'] ?? null, 64);
                $m->finished_at = $time;
                if ($to = $this->lower($e['to'] ?? null)) {
                    $this->save($m);
                    $this->recipient($m, $to, ['status' => 'rejected', 'dsn' => $this->str($e['dsn'] ?? null, 16), 'response' => $m->reject_reason], $time);
                }
                break;
            case 'bounce':
                if (is_string($e['child_qid'] ?? null)) {
                    $child = $this->message($e['child_qid'], $time);
                    $child->origin = 'bounce';
                    $child->service = 'bounce';
                }
                break;
            case 'delivery':
                if ($to = $this->lower($e['to'] ?? null)) {
                    $this->save($m);
                    $this->recipient($m, $to, [
                        'orig_recipient' => $this->lower($e['orig_to'] ?? null),
                        'status' => in_array($e['status'] ?? '', ['sent', 'deferred', 'bounced', 'expired'], true) ? $e['status'] : 'deferred',
                        'local' => (bool) ($e['local'] ?? false),
                        'relay' => $this->str($e['relay'] ?? null, 255),
                        'dsn' => $this->str($e['dsn'] ?? null, 16),
                        'delay' => isset($e['delay']) && is_numeric($e['delay']) ? (float) $e['delay'] : null,
                        'response' => $this->str($e['response'] ?? null, 500),
                    ], $time);
                }
                break;
            case 'removed':
                $m->finished_at = $time;
                break;
        }
    }

    /** Status, direction and owner of every message touched in this run; then save. */
    public function finish(): void
    {
        foreach ($this->touched as $m) {
            $recipients = $m->exists ? $m->recipients()->get() : collect();
            if ($recipients->isNotEmpty() && !in_array($m->status, ['expired'], true)) {
                $statuses = $recipients->pluck('status')->unique()->values()->all();
                $m->status = count($statuses) === 1 ? $statuses[0] : (in_array('deferred', $statuses, true) ? 'deferred' : 'mixed');
            }
            $m->recipient_count = max($m->recipient_count, $recipients->count());
            $m->direction = $this->direction($m, $recipients->pluck('local')->all(), $recipients->pluck('recipient')->all());
            if ($m->customer_id === null) {
                $this->attribute($m, $recipients->pluck('recipient')->all());
            }
            $this->save($m);
        }
        $this->touched = [];
    }

    private function collectSpam(): int
    {
        try {
            $rows = $this->agent->call('mail.spam_history', [], 60)['rows'] ?? [];
        } catch (Throwable $e) {
            return 0; // Rspamd down or history off: the log still works without scores.
        }
        $applied = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['score'])) {
                continue;
            }
            $query = MailLogMessage::whereNull('spam_score')->where('logged_at', '>=', now()->subDays(2));
            if (!empty($row['qid'])) {
                $query->where('queue_id', $row['qid']);
            } elseif (!empty($row['message_id'])) {
                $query->where('message_id', $row['message_id']);
            } else {
                continue;
            }
            $m = $query->latest('logged_at')->first();
            if ($m === null) {
                continue;
            }
            $m->spam_score = (float) $row['score'];
            $m->spam_action = $this->str($row['action'] ?? null, 32);
            $m->spam_symbols = array_slice(array_values(array_filter((array) ($row['symbols'] ?? []), 'is_array')), 0, 12);
            $m->subject ??= $this->str($row['subject'] ?? null, 255);
            $m->save();
            $applied++;
        }

        return $applied;
    }

    private function message(string $qid, Carbon $time): MailLogMessage
    {
        $qid = substr($qid, 0, 20);
        $m = $this->byQueue[$qid] ??= MailLogMessage::where('queue_id', $qid)
            ->where('logged_at', '>=', $time->copy()->subDays(7))
            ->latest('logged_at')->first()
            ?? new MailLogMessage(['queue_id' => $qid, 'logged_at' => $time]);
        $this->touched[spl_object_id($m)] = $m;

        return $m;
    }

    private function noQueueReject(array $e, Carbon $time): void
    {
        $m = new MailLogMessage([
            'logged_at' => $time,
            'finished_at' => $time,
            'status' => 'rejected',
            'service' => $this->str($e['service'] ?? null, 16),
            'origin' => 'inbound',
            'sender' => $this->lower($e['from'] ?? null),
            'client_ip' => $this->str($e['client_ip'] ?? null, 64),
            'client_host' => $this->str($e['client_host'] ?? null, 255),
            'reject_reason' => $this->str(trim(($e['code'] ?? '') . ' ' . ($e['dsn'] ?? '') . ' ' . ($e['reason'] ?? '')), 500),
            'recipient_count' => 1,
        ]);
        if (in_array($m->service, ['submission', 'submissions'], true)) {
            $m->origin = 'authenticated';
        }
        $m->save();
        if ($to = $this->lower($e['to'] ?? null)) {
            $this->recipient($m, $to, ['status' => 'rejected', 'dsn' => $this->str($e['dsn'] ?? null, 16), 'response' => $m->reject_reason], $time);
        }
        $this->touched[spl_object_id($m)] = $m;
        $this->finish();
    }

    private function recipient(MailLogMessage $m, string $to, array $data, Carbon $time): void
    {
        $r = MailLogRecipient::firstOrNew(['mail_log_message_id' => $m->id, 'recipient' => $to]);
        if ($r->exists) {
            $data['attempts'] = $r->attempts + 1;
            $data['orig_recipient'] ??= $r->orig_recipient;
        }
        $r->fill(array_filter($data, fn ($v) => $v !== null) + ['logged_at' => $time])->save();
    }

    /** @param array<int, bool> $local */
    private function direction(MailLogMessage $m, array $local, array $recipients): string
    {
        $remote = in_array(false, array_map('boolval', $local), true);
        if ($local === [] && $recipients !== []) {
            $remote = !$this->anyLocal($recipients);
        }
        if ($m->origin === 'inbound') {
            return 'incoming';
        }
        if ($recipients === []) {
            return $m->direction;
        }

        return $remote ? 'outgoing' : 'internal';
    }

    private function anyLocal(array $recipients): bool
    {
        $domains = array_unique(array_map(fn ($r) => strtolower(substr(strrchr($r, '@') ?: '', 1)), $recipients));

        return MailDomain::whereIn('domain', $domains)->exists();
    }

    private function attribute(MailLogMessage $m, array $recipients): void
    {
        if ($m->sasl_username && str_contains($m->sasl_username, '@')) {
            [$local, $domain] = explode('@', $m->sasl_username, 2);
            $box = Mailbox::where('local_part', $local)
                ->whereHas('domain', fn ($q) => $q->where('domain', $domain))->with('domain')->first();
            if ($box) {
                $m->mailbox_id = $box->id;
                $this->fromDomain($m, $box->domain);

                return;
            }
        }
        if ($m->system_user) {
            $site = Website::where('system_user', $m->system_user)->first();
            if ($site) {
                $m->website_id = $site->id;
                $m->customer_id = $site->customer_id;
                $m->origin = $m->origin === 'bounce' ? 'bounce' : 'website';
                $domain = $this->domainOf($m->sender) ?? MailDomain::where('website_id', $site->id)->first();
                if ($domain) {
                    $m->mail_domain_id = $domain->id;
                }

                return;
            }
        }
        // Incoming: the customer who receives it. Otherwise: the sender's domain.
        $candidates = $m->origin === 'inbound' ? [...$recipients, $m->sender] : [$m->sender, ...$recipients];
        foreach ($candidates as $address) {
            if ($domain = $this->domainOf($address)) {
                $this->fromDomain($m, $domain);

                return;
            }
        }
    }

    private function fromDomain(MailLogMessage $m, MailDomain $domain): void
    {
        $m->mail_domain_id = $domain->id;
        $m->customer_id = $domain->customer_id;
        $m->website_id ??= $domain->website_id;
    }

    private function domainOf(?string $address): ?MailDomain
    {
        if (!$address || !str_contains($address, '@')) {
            return null;
        }
        $domain = strtolower(substr(strrchr($address, '@'), 1));
        // Also match a subdomain sender (news.klant.nl) to klant.nl.
        $names = [];
        $parts = explode('.', $domain);
        while (count($parts) >= 2) {
            $names[] = implode('.', $parts);
            array_shift($parts);
        }

        return $names === [] ? null : MailDomain::whereIn('domain', $names)->get()
            ->sortByDesc(fn ($d) => strlen($d->domain))->first();
    }

    private function save(MailLogMessage $m): void
    {
        if ($m->isDirty() || !$m->exists) {
            $m->save();
        }
    }

    private function isLoopback(?string $ip): bool
    {
        return $ip !== null && (str_starts_with($ip, '127.') || $ip === '::1');
    }

    private function str(mixed $value, int $max): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function lower(mixed $value): ?string
    {
        $v = $this->str($value, 320);

        return $v === null ? null : mb_strtolower($v);
    }
}
