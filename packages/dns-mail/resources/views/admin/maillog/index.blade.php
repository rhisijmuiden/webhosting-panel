@php
    use Rhis\DnsMail\Models\MailLogMessage;
    $dirLabels = ['outgoing' => __('Outgoing'), 'incoming' => __('Incoming'), 'internal' => __('Internal'), 'unknown' => __('Unknown')];
    $originLabels = ['authenticated' => __('SMTP login'), 'website' => __('Website (PHP)'), 'system' => __('Server'), 'inbound' => __('From outside'), 'bounce' => __('Bounce'), 'unknown' => __('Unknown')];
    $statusLabels = ['queued' => __('Queued'), 'sent' => __('Delivered'), 'deferred' => __('Deferred'), 'bounced' => __('Bounced'), 'rejected' => __('Refused'), 'expired' => __('Expired'), 'mixed' => __('Partly delivered')];
    $periodLabels = ['1h' => __('Last hour'), '24h' => __('Last 24 hours'), '7d' => __('Last 7 days'), '30d' => __('Last 30 days'), 'all' => __('Everything')];
    $flagLabels = ['volume' => __('High volume'), 'bounces' => __('Many bounces'), 'spam' => __('Spam')];
    $maxTop = max(1, (int) $top->max('messages'));
    $flagged = $top->filter(fn ($c) => $c->flags !== []);
    $filterUrl = fn (array $extra) => route('admin.mail.log.index', array_filter(array_merge(request()->except('page'), $extra), fn ($v) => $v !== null && $v !== ''));
@endphp
<x-layouts.admin :title="__('Mail log')">
    <div class="page-head">
        <div><h1>{{ __('Mail log') }}</h1><p class="sub">{{ __('Every message the mail server sent, received or refused, per customer, domain and mailbox.') }}</p></div>
        <div class="actions">
            <x-ui.button icon="external" :href="route('admin.mail.log.export', request()->except('page'))">{{ __('Export CSV') }}</x-ui.button>
        </div>
    </div>

    @if ($flagged->isNotEmpty())
        <div class="alert alert-warn" role="status"><x-icon name="shield" />
            <div><strong>{{ trans_choice(':count customer sends unusual mail|:count customers send unusual mail', $flagged->count()) }}</strong>
                <p class="small">@foreach ($flagged as $c){{ $c->customer?->displayName() ?? __('No customer') }} ({{ collect($c->flags)->map(fn ($f) => $flagLabels[$f])->implode(', ') }})@if (! $loop->last) · @endif @endforeach</p></div>
        </div>
    @endif

    <section class="grid grid-stats" aria-label="{{ __('Totals') }}">
        <div class="card stat"><span class="label">{{ __('Messages') }}</span><strong class="value">{{ number_format($totals['messages']) }}</strong><span class="hint">{{ $periodLabels[$f['from'] || $f['until'] ? '' : $f['period']] ?? __('Selected period') }}</span></div>
        <div class="card stat"><span class="label">{{ __('Outgoing') }}</span><strong class="value">{{ number_format($totals['outgoing']) }}</strong><span class="hint">{{ __('To other servers') }}</span></div>
        <div class="card stat"><span class="label">{{ __('Incoming') }}</span><strong class="value">{{ number_format($totals['incoming']) }}</strong><span class="hint">{{ __('For customers on this server') }}</span></div>
        <div class="card stat"><span class="label">{{ __('Refused or spam') }}</span><strong class="value">{{ number_format($totals['rejected'] + $totals['spam']) }}</strong><span class="hint">{{ __(':rejected refused · :spam spam', ['rejected' => $totals['rejected'], 'spam' => $totals['spam']]) }}</span></div>
    </section>

    <div class="grid grid-2">
        <x-ui.card :title="__('Outgoing mail per customer')" flush>
            @if ($top->isEmpty())
                <x-ui.empty icon="send">{{ __('No outgoing mail in this period.') }}</x-ui.empty>
            @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('Messages') }}</th><th class="num">{{ __('Last hour') }}</th><th class="num">{{ __('Failed') }}</th></x-slot:head>
                @foreach ($top as $c)
                    <tr>
                        <td>@if ($c->customer)<a class="strong" href="{{ $filterUrl(['customer' => $c->customer_id, 'direction' => 'outgoing']) }}">{{ $c->customer->displayName() }}</a>@else<a class="muted" href="{{ $filterUrl(['direction' => 'outgoing']) }}">{{ __('No customer') }}</a>@endif
                            @if ($c->flags)<div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">@foreach ($c->flags as $flag)<x-ui.pill status="bad">{{ $flagLabels[$flag] }}</x-ui.pill>@endforeach</div>@endif</td>
                        <td style="min-width:140px"><div style="display:flex;align-items:center;gap:8px"><div class="rank-bar" style="width:{{ round($c->messages / $maxTop * 100) }}%" aria-hidden="true"></div><span class="small">{{ number_format($c->messages) }}</span></div><div class="small muted">{{ trans_choice(':count recipient|:count recipients', $c->recipients) }}</div></td>
                        <td class="num">{{ number_format($c->last_hour) }}</td>
                        <td class="num">{{ $c->bounced + $c->rejected }}<div class="small muted">{{ $c->bounce_percent }}%</div></td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>
        <x-ui.card :title="__('Busiest senders')" flush>
            @if ($senders->isEmpty())
                <x-ui.empty icon="user">{{ __('No outgoing mail in this period.') }}</x-ui.empty>
            @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Sender') }}</th><th>{{ __('How') }}</th><th class="num">{{ __('Messages') }}</th><th class="num">{{ __('Failed') }}</th></x-slot:head>
                @foreach ($senders as $s)
                    <tr>
                        <td><a class="strong" href="{{ $filterUrl(['mailbox' => $s->sender, 'direction' => 'outgoing']) }}">{{ $s->sender ?: '<>' }}</a></td>
                        <td><span class="small">{{ $originLabels[$s->kind] ?? $s->kind }}</span></td>
                        <td class="num">{{ number_format($s->messages) }}</td>
                        <td class="num">{{ $s->failed }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Filter')">
        <form method="GET" class="form" role="search">
            <div class="form-grid">
                <x-ui.field name="q" :label="__('Search')" :value="$f['q'] ?? ''" :placeholder="__('Address, subject, queue ID, message ID or IP')" />
                <x-ui.field name="customer" type="select" :label="__('Customer')">
                    <option value="">{{ __('All customers') }}</option>
                    @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(($f['customer'] ?? null) == $c->id)>{{ $c->displayName() }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="domain" :label="__('Domain')" :value="$f['domain'] ?? ''" placeholder="klant.nl" />
                <x-ui.field name="mailbox" :label="__('Mailbox, login or website user')" :value="$f['mailbox'] ?? ''" placeholder="info@klant.nl" />
                <x-ui.field name="direction" type="select" :label="__('Direction')">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($dirLabels as $k => $l)<option value="{{ $k }}" @selected(($f['direction'] ?? null) === $k)>{{ $l }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="origin" type="select" :label="__('Sent through')">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($originLabels as $k => $l)<option value="{{ $k }}" @selected(($f['origin'] ?? null) === $k)>{{ $l }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="status" type="select" :label="__('Status')">
                    <option value="">{{ __('All') }}</option>
                    <option value="failed" @selected(($f['status'] ?? null) === 'failed')>{{ __('Not delivered') }}</option>
                    @foreach ($statusLabels as $k => $l)<option value="{{ $k }}" @selected(($f['status'] ?? null) === $k)>{{ $l }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="period" type="select" :label="__('Period')">
                    @foreach ($periodLabels as $k => $l)<option value="{{ $k }}" @selected($f['period'] === $k)>{{ $l }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="from" type="date" :label="__('From date')" :value="$f['from']?->toDateString()" />
                <x-ui.field name="until" type="date" :label="__('Until date')" :value="$f['until']?->toDateString()" />
            </div>
            <div class="row" style="margin-top:16px;gap:12px;flex-wrap:wrap;align-items:center">
                <label class="check"><input type="checkbox" name="spam" value="1" @checked(! empty($f['spam']))> {{ __('Only spam (score 6 or higher)') }}</label>
                <span style="flex:1"></span>
                <x-ui.button :href="route('admin.mail.log.index')" variant="ghost">{{ __('Reset') }}</x-ui.button>
                <button type="submit" class="btn btn-primary"><x-icon name="search" />{{ __('Apply') }}</button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card flush>
        @if ($messages->isEmpty())
            <x-ui.empty icon="mail">{{ __('No messages match these filters. The log is read from the mail server every minute.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Time') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Subject') }}</th><th class="num">{{ __('Spam score') }}</th><th>{{ __('Status') }}</th></x-slot:head>
            @foreach ($messages as $m)
                <tr>
                    <td style="white-space:nowrap"><a class="strong" href="{{ route('admin.mail.log.show', $m) }}">{{ $m->logged_at->timezone(config('app.timezone'))->format('d-m H:i:s') }}</a>
                        <div class="small muted">{{ $dirLabels[$m->direction] ?? $m->direction }}</div></td>
                    <td>{{ $m->sender === '' ? '<>' : ($m->sender ?? '–') }}
                        <div class="small muted">{{ $originLabels[$m->origin] ?? $m->origin }}@if ($m->sasl_username && $m->sasl_username !== $m->sender) · {{ $m->sasl_username }}@elseif ($m->system_user) · {{ $m->system_user }}@endif @if ($m->origin === 'inbound' && $m->client_ip) · {{ $m->client_ip }}@endif</div></td>
                    <td>{{ $m->recipients->first()?->recipient ?? '–' }}@if ($m->recipients_count > 1)<div class="small muted">{{ __('+ :count more', ['count' => $m->recipients_count - 1]) }}</div>@endif</td>
                    <td>@if ($m->customer)<a href="{{ $filterUrl(['customer' => $m->customer_id]) }}">{{ $m->customer->displayName() }}</a>@else<span class="muted">–</span>@endif
                        @if ($m->mailDomain)<div class="small muted">{{ $m->mailDomain->domain }}</div>@endif</td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $m->subject ?? '' }}@if ($m->reject_reason)<div class="small muted" title="{{ $m->reject_reason }}">{{ \Illuminate\Support\Str::limit($m->reject_reason, 60) }}</div>@endif</td>
                    <td class="num">@if ($m->spam_score !== null)<span @class(['strong' => $m->spam_score >= 6])>{{ number_format($m->spam_score, 1) }}</span>@else<span class="muted">–</span>@endif</td>
                    <td><x-ui.pill :status="MailLogMessage::statusTone($m->status)">{{ $statusLabels[$m->status] ?? $m->status }}</x-ui.pill></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    {{ $messages->links('partials.pagination') }}

    <details class="card">
        <summary class="strong">{{ __('Mail log settings') }}</summary>
        <form method="POST" action="{{ route('admin.mail.log.settings') }}" class="form" style="margin-top:16px">
            @csrf @method('PUT')
            <div class="form-grid">
                <x-ui.field name="retention_days" type="number" min="1" max="365" :label="__('Keep entries (days)')" :value="$settings->retentionDays()" :help="__('Older entries are deleted every night.')" />
                <x-ui.field name="alert_per_hour" type="number" min="1" :label="__('Flag a customer from (messages per hour)')" :value="$settings->alertPerHour()" :help="__('Outgoing messages in the last hour.')" />
                <x-ui.field name="alert_bounce_percent" type="number" min="1" max="100" :label="__('Flag from bounce rate (%)')" :value="$settings->alertBouncePercent()" :help="__('Bounced or refused outgoing mail, with at least 20 messages.')" />
            </div>
            <div style="margin-top:16px"><button type="submit" class="btn btn-primary">{{ __('Save') }}</button></div>
        </form>
    </details>
</x-layouts.admin>
