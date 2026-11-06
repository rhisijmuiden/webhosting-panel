@php use Rhis\Monitoring\Describe; use Rhis\Monitoring\Severity; use Illuminate\Support\Number; @endphp
<x-layouts.admin :title="__('Monitoring')">
    @include('monitoring::partials.styles')
    <div class="page-head">
        <div><h1>{{ __('Monitoring') }}</h1>
            <p class="sub">{{ __('Every :n minutes we check every website, its certificate, the disks and the services, and alert you when something breaks.', ['n' => $o->get('interval')]) }}</p></div>
        <div class="actions">
            <a class="btn" href="{{ route('status.public') }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Status page') }}</a>
            <form method="POST" action="{{ route('admin.monitoring.run') }}">@csrf<x-ui.button icon="refresh" variant="dark">{{ __('Check now') }}</x-ui.button></form>
        </div>
    </div>
    @include('monitoring::partials.tabs')

    @unless ($o->get('enabled'))
        <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('Monitoring is switched off. Switch it on under Settings.') }}</div>
    @endunless
    @if ($paused)
        <div class="alert alert-info" role="status"><x-icon name="info" />{{ __('Server maintenance is running: problems are recorded, alerts wait until maintenance is done.') }}</div>
    @endif
    @if ($agent && $agent->isProblem())
        <div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __('The server agent does not answer: :error', ['error' => $agent->detail]) }}</div>
    @endif

    <section class="mo-hero" aria-label="{{ __('Status') }}">
        <div class="mo-tile {{ $down ? 'mo-bad' : 'mo-ok' }}">
            <span class="k">{{ __('Websites online') }}</span>
            <span class="v">{{ $total - $down }} / {{ $total }}</span>
            <span class="h">{{ $down ? trans_choice(':count website does not answer|:count websites do not answer', $down) : __('All websites answer') }}</span>
        </div>
        <div class="mo-tile {{ $open->where('severity', 'critical')->count() ? 'mo-bad' : ($open->count() ? 'mo-warn' : 'mo-ok') }}">
            <span class="k">{{ __('Open problems') }}</span>
            <span class="v">{{ $open->count() }}</span>
            <span class="h">{{ $open->count() ? __(':n critical', ['n' => $open->where('severity', 'critical')->count()]) : __('Nothing to do') }}</span>
        </div>
        <div class="mo-tile {{ $sslSoon ? 'mo-warn' : 'mo-ok' }}">
            <span class="k">{{ __('Certificates') }}</span>
            <span class="v">{{ $sslSoon }}</span>
            <span class="h">{{ $sslSoon ? __('expire soon or are not valid') : __('All valid for more than :n days', ['n' => $o->get('ssl_warning_days')]) }}</span>
        </div>
        @php $fullest = $disks->sortByDesc(fn ($d) => $d->value['pct'] ?? 0)->first(); @endphp
        <div class="mo-tile {{ $fullest ? 'mo-'.Severity::pill($fullest->status) : 'mo-info' }}">
            <span class="k">{{ __('Fullest disk') }}</span>
            <span class="v">{{ $fullest ? round($fullest->value['pct'] ?? 0).'%' : '–' }}</span>
            <span class="h">{{ $fullest ? $fullest->label.' · '.__(':free free', ['free' => Number::fileSize($fullest->value['free'] ?? 0, 1)]) : __('Not checked yet') }}</span>
        </div>
    </section>

    @if ($open->isNotEmpty())
        <x-ui.card :title="__('Open problems')" flush>
            <ul class="mo-incidents">
                @foreach ($open as $i)
                    <li>
                        <span class="mo-dot {{ $i->severity }}" aria-hidden="true"></span>
                        <strong>@if ($i->website_id)<a href="{{ route('admin.monitoring.website', $i->website_id) }}" style="color:inherit">{{ Describe::incident($i) }}</a>@else{{ Describe::incident($i) }}@endif</strong>
                        <x-ui.pill :status="Severity::pill($i->severity)">{{ Severity::label($i->severity) }}</x-ui.pill>
                        <span class="meta">{{ Describe::kind($i->kind) }} · {{ __('since :time', ['time' => $i->started_at->diffForHumans()]) }}@if ($i->detail) · <span class="mono">{{ $i->detail }}</span>@endif @if (! $i->notified_at) · {{ __('not alerted yet') }}@endif</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <div class="bento">
        <x-ui.card class="span-8 mo-start" :title="__('Websites')" flush>
            <x-slot:actions>
                <form method="GET" class="mo-filters">
                    <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search domain') }}" aria-label="{{ __('Search domain') }}">
                    <a class="btn btn-sm" href="{{ route('admin.monitoring.index', $filter === 'problems' ? [] : ['filter' => 'problems']) }}" @if ($filter === 'problems') aria-pressed="true" @endif>{{ $filter === 'problems' ? __('Show all') : __('Only problems') }}</a>
                </form>
            </x-slot:actions>
            @if ($sites->isEmpty())
                <x-ui.empty icon="activity">{{ $total ? __('No websites match.') : __('No results yet. The first check runs within a few minutes, or press Check now.') }}</x-ui.empty>
            @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Response') }}</th><th class="num">{{ __('24 hours') }}</th><th class="num">{{ __('30 days') }}</th><th>{{ __('Certificate') }}</th></x-slot:head>
                @foreach ($sites as $c)
                    @php $s = $ssl->get($c->website_id); $ms = $c->value['ms'] ?? null; @endphp
                    <tr>
                        <td class="mo-site"><a href="{{ route('admin.monitoring.website', $c->website_id) }}">{{ $c->label }}</a><span class="small muted">{{ $c->website?->customer?->displayName() }}</span></td>
                        <td>@if ($c->status === 'critical')<x-ui.pill status="bad">{{ __('Down') }}@if (($c->value['code'] ?? 0) > 0) · {{ $c->value['code'] }}@endif</x-ui.pill>
                            @elseif ($c->fails)<x-ui.pill status="warn">{{ __('Check failed') }}</x-ui.pill>
                            @else<x-ui.pill status="ok">{{ __('Online') }}</x-ui.pill>@endif</td>
                        <td class="num">@if ($ms !== null)<span @class(['mo-pct', 'mid' => $c->value['slow'] ?? false])>{{ Number::format($ms) }} ms</span>@else<span class="muted">–</span>@endif</td>
                        <td class="num">@include('monitoring::partials.pct', ['value' => $uptime1[$c->website_id] ?? null])</td>
                        <td class="num">@include('monitoring::partials.pct', ['value' => $uptime30[$c->website_id] ?? null])</td>
                        <td>@if (! $s)<span class="muted small">{{ __('No HTTPS') }}</span>
                            @elseif ($s->isProblem())<x-ui.pill :status="Severity::pill($s->status)">{{ isset($s->value['days_left']) && $s->value['days_left'] !== null ? trans_choice(':count day left|:count days left', max(0, $s->value['days_left'])) : __('Not valid') }}</x-ui.pill>
                            @else<span class="small">{{ isset($s->value['days_left']) ? trans_choice(':count day left|:count days left', $s->value['days_left']) : '–' }}</span>@endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>

        <div class="span-4" style="display:flex;flex-direction:column;gap:16px;min-width:0">
            <x-ui.card :title="__('Disks')">
                <div class="mo-disk">
                    @forelse ($disks as $d)
                        <div>
                            <x-ui.meter :label="$d->label" :percent="$d->value['pct'] ?? 0" />
                            <p class="small muted" style="margin:4px 0 0">{{ __(':used of :total used', ['used' => Number::fileSize($d->value['used'] ?? 0, 1), 'total' => Number::fileSize($d->value['total'] ?? 0, 1)]) }}@if (($inodes->get($d->label)?->value['pct'] ?? null) !== null) · {{ __('inodes :pct%', ['pct' => round($inodes->get($d->label)->value['pct'])]) }}@endif</p>
                        </div>
                    @empty
                        <p class="muted small">{{ __('Not checked yet') }}</p>
                    @endforelse
                </div>
            </x-ui.card>
            <x-ui.card :title="__('Server')">
                <dl class="mo-facts">
                    @php $mem = $server->get('memory'); $load = $server->get('load'); $mq = $server->get('mail_queue'); @endphp
                    <dt>{{ __('Memory') }}</dt><dd><span class="mo-dot {{ $mem?->status }}" style="display:inline-block"></span> {{ $mem ? round($mem->value['pct'] ?? 0).'%' : '–' }}</dd>
                    <dt>{{ __('Load (5 min)') }}</dt><dd><span class="mo-dot {{ $load?->status }}" style="display:inline-block"></span> {{ $load ? number_format($load->value['load'][1] ?? 0, 2).' · '.trans_choice(':count core|:count cores', $load->value['cpus'] ?? 1) : '–' }}</dd>
                    <dt>{{ __('Mail queue') }}</dt><dd><span class="mo-dot {{ $mq?->status }}" style="display:inline-block"></span> {{ $mq ? trans_choice(':count message|:count messages', $mq->value['count'] ?? 0) : '–' }}</dd>
                    <dt>{{ __('Last check') }}</dt><dd>{{ $last ? \Illuminate\Support\Carbon::createFromTimestamp($last['at'])->diffForHumans() : __('Never') }}</dd>
                </dl>
            </x-ui.card>
            <x-ui.card :title="__('Services')">
                <div class="mo-svc">
                    @forelse ($services as $s)
                        <div title="{{ $s->value['unit'] ?? '' }}"><span class="mo-dot {{ $s->status }}" aria-hidden="true"></span><span>{{ $s->label }}</span><span class="sr-only">{{ Severity::label($s->status) }}</span></div>
                    @empty
                        <p class="muted small">{{ __('Not checked yet') }}</p>
                    @endforelse
                </div>
                @if (Route::has('admin.services.index'))<p class="small" style="margin:12px 0 0"><a href="{{ route('admin.services.index') }}">{{ __('Restart a service') }} &rarr;</a></p>@endif
            </x-ui.card>
        </div>
    </div>
</x-layouts.admin>
