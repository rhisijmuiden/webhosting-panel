@php use Rhis\Monitoring\Describe; use Rhis\Monitoring\Severity; use Rhis\Monitoring\Uptime; @endphp
<x-layouts.admin :title="$website->domain">
    @include('monitoring::partials.styles')
    <div class="page-head">
        <div><h1>{{ $website->domain }}</h1>
            <p class="sub">{{ $website->customer?->displayName() }} · <a href="{{ route('admin.websites.show', $website) }}">{{ __('Open the website') }}</a></p></div>
        <div class="actions"><a class="btn" href="https://{{ $website->domain }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Visit') }}</a></div>
    </div>
    @include('monitoring::partials.tabs')

    @php $u30 = Uptime::percent(array_slice($days, -30)); $u90 = Uptime::percent($days); $ms = $check?->value['ms'] ?? null; @endphp
    <section class="mo-hero">
        <div class="mo-tile {{ $check?->status === 'critical' ? 'mo-bad' : 'mo-ok' }}"><span class="k">{{ __('Status') }}</span>
            <span class="v">{{ $check ? ($check->status === 'critical' ? __('Down') : __('Online')) : '–' }}</span>
            <span class="h">{{ $check?->since ? __('since :time', ['time' => $check->since->diffForHumans()]) : __('Not checked yet') }}</span></div>
        <div class="mo-tile mo-info"><span class="k">{{ __('Response') }}</span><span class="v">{{ $ms !== null ? $ms.' ms' : '–' }}</span>
            <span class="h">{{ $check && ($check->value['code'] ?? 0) ? 'HTTP '.$check->value['code'] : '' }}</span></div>
        <div class="mo-tile {{ $u30 !== null && $u30 < 99.9 ? 'mo-warn' : 'mo-ok' }}"><span class="k">{{ __('Uptime 30 days') }}</span>
            <span class="v">@include('monitoring::partials.pct', ['value' => $u30])</span><span class="h">{{ __('90 days') }}: {{ $u90 !== null ? rtrim(rtrim(number_format($u90, 2), '0'), '.').'%' : '–' }}</span></div>
        <div class="mo-tile {{ $ssl ? 'mo-'.Severity::pill($ssl->status) : 'mo-info' }}"><span class="k">{{ __('Certificate') }}</span>
            <span class="v">{{ $ssl && ($ssl->value['days_left'] ?? null) !== null ? trans_choice(':count day|:count days', $ssl->value['days_left']) : '–' }}</span>
            <span class="h">{{ $ssl ? (($ssl->value['issuer'] ?? '') ?: '').($ssl->detail ? ' · '.$ssl->detail : '') : __('No HTTPS') }}</span></div>
    </section>

    <x-ui.card :title="__('Response time, last 24 hours')">
        @if ($samples->count() < 2)
            <p class="muted small">{{ __('Not enough checks yet.') }}</p>
        @else
            @php
                $max = max(200, (int) $samples->max('ms'));
                $t0 = $samples->first()->checked_at->getTimestamp(); $span = max(1, $samples->last()->checked_at->getTimestamp() - $t0);
                $pts = $samples->map(fn ($s) => [round(($s->checked_at->getTimestamp() - $t0) * 1000 / $span, 1), round(120 - (($s->ms ?? 0) * 110 / $max), 1), $s->ok]);
                $line = $pts->map(fn ($p) => $p[0].','.$p[1])->implode(' ');
            @endphp
            <svg class="mo-spark" viewBox="0 0 1000 120" preserveAspectRatio="none" role="img" aria-label="{{ __('Response time, last 24 hours') }}: {{ __('max :n ms', ['n' => $max]) }}">
                @foreach ($pts->where(2, false) as $p)<rect class="down" x="{{ max(0, $p[0] - 4) }}" y="0" width="8" height="120"></rect>@endforeach
                <polygon class="area" points="0,120 {{ $line }} 1000,120"></polygon>
                <polyline class="line" points="{{ $line }}"></polyline>
            </svg>
            <div class="mo-axis"><span>{{ $samples->first()->checked_at->timezone(config('app.timezone'))->isoFormat('HH:mm') }}</span><span>{{ __('max :n ms', ['n' => $max]) }} · {{ __('average :n ms', ['n' => (int) $samples->avg('ms')]) }}</span><span>{{ $samples->last()->checked_at->timezone(config('app.timezone'))->isoFormat('HH:mm') }}</span></div>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Uptime, last 90 days')">
        @include('monitoring::partials.bars', ['days' => $days])
        <div class="mo-axis"><span>{{ __('90 days ago') }}</span><span>{{ __('Today') }}</span></div>
    </x-ui.card>

    <x-ui.card :title="__('Incidents')" flush>
        @if ($incidents->isEmpty())
            <x-ui.empty icon="check">{{ __('No incidents. This website has been fine.') }}</x-ui.empty>
        @else
            <ul class="mo-incidents">
                @foreach ($incidents as $i)
                    <li><span class="mo-dot {{ $i->resolved_at ? '' : $i->severity }}" aria-hidden="true"></span><strong>{{ Describe::incident($i) }}</strong>
                        @if ($i->resolved_at)<x-ui.pill status="ok">{{ __('Solved') }}</x-ui.pill>@else<x-ui.pill :status="Severity::pill($i->severity)">{{ __('Open') }}</x-ui.pill>@endif
                        <span class="meta">{{ $i->started_at->timezone(config('app.timezone'))->isoFormat('D MMM YYYY HH:mm') }} · {{ $i->duration() }}@if ($i->detail) · <span class="mono">{{ $i->detail }}</span>@endif</span></li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</x-layouts.admin>
