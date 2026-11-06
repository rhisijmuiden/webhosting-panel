<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http\Controllers\Admin;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Rhis\DnsMail\Models\MailLogMessage;
use Rhis\DnsMail\Services\MailLogReport;
use Rhis\DnsMail\Services\MailLogSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin: every message the server handled, per customer, domain and mailbox. */
final class MailLogController extends Controller
{
    public const PERIODS = ['1h' => 1, '24h' => 24, '7d' => 168, '30d' => 720];

    public function index(Request $request, MailLogReport $report, MailLogSettings $settings): View
    {
        $f = $this->filters($request);
        $messages = $this->query($f)->with(['customer', 'mailDomain', 'website'])
            ->withCount('recipients')
            ->with(['recipients' => fn ($q) => $q->limit(3)])
            ->latest('logged_at')->latest('id')
            ->paginate(50)->withQueryString();

        $since = $f['from'] ?? now()->subHours(self::PERIODS[$f['period']] ?? 24);

        return view('dns-mail::admin.maillog.index', [
            'f' => $f,
            'messages' => $messages,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'company']),
            'totals' => $report->totals($since),
            'top' => $report->customers($since),
            'senders' => $report->senders($since),
            'settings' => $settings,
            'periods' => array_keys(self::PERIODS),
        ]);
    }

    public function show(MailLogMessage $message): View
    {
        $message->load(['recipients', 'customer', 'mailDomain', 'mailbox.domain', 'website']);
        $related = $message->message_id
            ? MailLogMessage::where('message_id', $message->message_id)->whereKeyNot($message->id)->latest('logged_at')->limit(10)->get()
            : collect();

        return view('dns-mail::admin.maillog.show', ['m' => $message, 'related' => $related]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($this->filters($request))->with(['customer', 'recipients'])->latest('logged_at');
        $name = 'maillog-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time', 'queue_id', 'direction', 'origin', 'status', 'customer', 'sender', 'login', 'website_user', 'client_ip', 'recipients', 'subject', 'spam_score', 'size', 'message_id', 'reason']);
            $query->limit(50000)->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $m) {
                    fputcsv($out, [
                        $m->logged_at?->toIso8601String(), $m->queue_id, $m->direction, $m->origin, $m->status,
                        $m->customer?->displayName(), $m->sender, $m->sasl_username, $m->system_user, $m->client_ip,
                        $m->recipients->pluck('recipient')->implode(' '), $m->subject, $m->spam_score, $m->size,
                        $m->message_id, $m->reject_reason,
                    ]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function settings(Request $request, MailLogSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'alert_per_hour' => ['required', 'integer', 'min:1', 'max:100000'],
            'alert_bounce_percent' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $settings->save((int) $data['retention_days'], (int) $data['alert_per_hour'], (int) $data['alert_bounce_percent']);

        return back()->with('status', __('Mail log settings saved.'));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'integer'],
            'domain' => ['nullable', 'string', 'max:253'],
            'mailbox' => ['nullable', 'string', 'max:320'],
            'direction' => ['nullable', 'in:' . implode(',', MailLogMessage::DIRECTIONS)],
            'status' => ['nullable', 'in:' . implode(',', MailLogMessage::STATUSES) . ',failed'],
            'origin' => ['nullable', 'in:' . implode(',', MailLogMessage::ORIGINS)],
            'period' => ['nullable', 'in:' . implode(',', array_keys(self::PERIODS)) . ',all'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'spam' => ['nullable', 'boolean'],
        ]);
        $v['period'] ??= '24h';
        $v['from'] = isset($v['from']) ? Carbon::parse($v['from'])->startOfDay() : null;
        $v['until'] = isset($v['until']) ? Carbon::parse($v['until'])->endOfDay() : null;

        return $v;
    }

    private function query(array $f): Builder
    {
        $q = MailLogMessage::query();
        if ($f['from'] || $f['until']) {
            $q->when($f['from'], fn ($q, $d) => $q->where('logged_at', '>=', $d))
                ->when($f['until'], fn ($q, $d) => $q->where('logged_at', '<=', $d));
        } elseif (isset(self::PERIODS[$f['period']])) {
            $q->where('logged_at', '>=', now()->subHours(self::PERIODS[$f['period']]));
        }
        if (!empty($f['customer'])) {
            $q->where('customer_id', (int) $f['customer']);
        }
        if (!empty($f['domain'])) {
            $domain = mb_strtolower(trim($f['domain']));
            $q->where(fn ($w) => $w->whereHas('mailDomain', fn ($d) => $d->where('domain', $domain))
                ->orWhere('sender', 'like', '%@' . $this->like($domain))
                ->orWhereHas('recipients', fn ($r) => $r->where('recipient', 'like', '%@' . $this->like($domain))));
        }
        if (!empty($f['mailbox'])) {
            $box = mb_strtolower(trim($f['mailbox']));
            $q->where(fn ($w) => $w->where('sasl_username', $box)->orWhere('sender', $box)->orWhere('system_user', $box)
                ->orWhereHas('recipients', fn ($r) => $r->where('recipient', $box)));
        }
        foreach (['direction', 'origin'] as $key) {
            if (!empty($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (!empty($f['status'])) {
            $f['status'] === 'failed'
                ? $q->whereIn('status', ['bounced', 'rejected', 'expired'])
                : $q->where('status', $f['status']);
        }
        if (!empty($f['spam'])) {
            $q->where('spam_score', '>=', MailLogReport::SPAM_SCORE);
        }
        if (!empty($f['q'])) {
            $term = '%' . $this->like(trim($f['q'])) . '%';
            $q->where(fn ($w) => $w->where('sender', 'like', $term)->orWhere('subject', 'like', $term)
                ->orWhere('queue_id', 'like', $term)->orWhere('message_id', 'like', $term)
                ->orWhere('client_ip', 'like', $term)->orWhere('sasl_username', 'like', $term)
                ->orWhereHas('recipients', fn ($r) => $r->where('recipient', 'like', $term)));
        }

        return $q;
    }

    private function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
