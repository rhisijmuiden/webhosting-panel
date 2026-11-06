<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Rhis\Monitoring\Mail\AlertMail;
use Rhis\Monitoring\Mail\CustomerAlertMail;
use Rhis\Monitoring\Models\Channel;
use Rhis\Monitoring\Models\Contact;
use Rhis\Monitoring\Models\Incident;
use Throwable;

/** Sends monitoring events to the alert channels (mail, Teams) and to customers who asked for it. */
final class Alerts
{
    public function __construct(private readonly Options $options) {}

    /** @param list<array{type: string, incident: Incident}> $events */
    public function send(array $events, bool $paused = false): void
    {
        if (! $events) {
            return;
        }
        $channels = Channel::where('enabled', true)->get();
        foreach ($channels as $channel) {
            $mine = array_values(array_filter($events, fn ($e) => $this->wants($channel, $e)));
            if ($mine) {
                $this->deliver($channel, $mine);
            }
        }

        foreach ($events as $e) {
            $i = $e['incident'];
            if ($e['type'] === 'reminder') {
                $i->update(['notified_at' => now(), 'reminders' => $i->reminders + 1]);
            } elseif (in_array($e['type'], ['problem', 'worse'], true)) {
                $i->update(['notified_at' => now()]);
            }
        }

        if ($this->options->get('customer_alerts')) {
            $this->customers($events);
        }
    }

    private function wants(Channel $channel, array $e): bool
    {
        if ($e['type'] === 'recovery' && ! $channel->recoveries) {
            return false;
        }

        return $channel->wants($e['incident']->severity);
    }

    /** Sends to one channel; true when it went out. */
    public function deliver(Channel $channel, array $events): bool
    {
        try {
            if ($channel->type === 'teams') {
                $this->inLocale($channel->locale, function () use ($channel, $events) {
                    $items = array_map(fn ($e) => $this->item($e), $events);
                    Teams::post((string) $channel->target, Teams::card(
                        $this->subject($events), $this->intro($events), $items, route('admin.monitoring.index'), __('Open monitoring'),
                    ));
                });
            } else {
                foreach ($this->mailRecipients($channel) as [$address, $locale]) {
                    Mail::to($address)->locale($locale)->send(new AlertMail($events, $this->server()));
                }
            }
            $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();

            return true;
        } catch (Throwable $e) {
            report($e);
            $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return false;
        }
    }

    /** A test message, so the administrator sees what an alert looks like. */
    public function test(Channel $channel): bool
    {
        $fake = new Incident([
            'key' => 'test', 'kind' => 'website', 'title' => 'example.nl', 'severity' => 'critical',
            'detail' => 'HTTP 502 (test)', 'started_at' => now()->subMinutes(10),
        ]);

        return $this->deliver($channel, [['type' => 'problem', 'incident' => $fake, 'test' => true]]);
    }

    /** @return list<array{0: string, 1: string}> [address, locale] */
    private function mailRecipients(Channel $channel): array
    {
        $addresses = $channel->addresses();
        if (! $addresses) {
            return User::where('role', User::ROLE_ADMIN)->where('is_active', true)->get(['email', 'locale'])
                ->filter(fn (User $u) => filter_var($u->email, FILTER_VALIDATE_EMAIL))
                ->map(fn (User $u) => [$u->email, $u->locale ?: $channel->locale])->values()->all();
        }
        $locales = User::whereIn('email', $addresses)->pluck('locale', 'email');

        return array_map(fn ($a) => [$a, $locales[$a] ?? $channel->locale], $addresses);
    }

    /** Customers whose website went down or came back, in their own language. */
    private function customers(array $events): void
    {
        $byCustomer = [];
        foreach ($events as $e) {
            $i = $e['incident'];
            if ($i->kind === 'website' && $i->customer_id && in_array($e['type'], ['problem', 'recovery'], true)) {
                $byCustomer[$i->customer_id][] = $e;
            }
        }
        foreach ($byCustomer as $customerId => $list) {
            $customer = Customer::find($customerId);
            $contact = $customer ? Contact::where('customer_id', $customerId)->first() : null;
            if (! $customer || ! $contact) {
                continue;
            }
            $contact->setRelation('customer', $customer);
            foreach ($contact->recipients() as $address) {
                try {
                    Mail::to($address)->locale($customer->locale ?: config('app.locale'))->send(new CustomerAlertMail($customer, $list));
                } catch (Throwable $ex) {
                    report($ex);
                }
            }
        }
    }

    // ------------------------------------------------------------ texts (used by the mails and Teams)

    public static function badge(array $e): string
    {
        return match ($e['type']) {
            'recovery' => __('Solved'),
            'reminder' => __('Still open'),
            'worse' => __('Worse'),
            default => Severity::label($e['incident']->severity),
        };
    }

    /** @return array{type: string, severity: string, badge: string, headline: string, detail: ?string, when: string, advice: ?string} */
    public static function item(array $e): array
    {
        $i = $e['incident'];
        $when = $e['type'] === 'recovery'
            ? __('Down for :duration, solved :time', ['duration' => $i->duration(), 'time' => $i->resolved_at?->timezone(config('app.timezone'))->isoFormat('D MMM HH:mm')])
            : __('Since :time (:duration)', ['time' => $i->started_at->timezone(config('app.timezone'))->isoFormat('D MMM HH:mm'), 'duration' => $i->duration()]);

        return [
            'type' => $e['type'],
            'severity' => $i->severity,
            'badge' => self::badge($e),
            'headline' => Describe::incident($i),
            'detail' => $i->detail,
            'when' => $when,
            'advice' => $e['type'] === 'recovery' ? null : Describe::advice($i->kind),
        ];
    }

    public static function subject(array $events): string
    {
        $problems = array_filter($events, fn ($e) => $e['type'] !== 'recovery');
        if (count($events) === 1) {
            $e = $events[0];

            return ($e['type'] === 'recovery' ? __('Solved').': ' : ($e['type'] === 'reminder' ? __('Still open').': ' : '')).Describe::incident($e['incident']);
        }
        if (! $problems) {
            return trans_choice(':count problem solved|:count problems solved', count($events));
        }

        return trans_choice(':count problem on the server|:count problems on the server', count($problems))
            .(count($problems) < count($events) ? ', '.trans_choice(':count solved|:count solved', count($events) - count($problems)) : '');
    }

    public static function intro(array $events): string
    {
        return collect($events)->every(fn ($e) => $e['type'] === 'recovery')
            ? __('Everything below works again.')
            : __('Monitoring of :server found the following.', ['server' => self::server()]);
    }

    public static function server(): string
    {
        return gethostname() ?: (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    private function inLocale(string $locale, callable $fn): void
    {
        $old = App::getLocale();
        App::setLocale($locale ?: $old);
        try {
            $fn();
        } finally {
            App::setLocale($old);
        }
    }
}
