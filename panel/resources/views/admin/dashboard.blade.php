@php
    $fmt = fn ($bytes) => \Illuminate\Support\Number::fileSize($bytes ?? 0, precision: 1);
    $pct = fn ($used, $total) => $total ? round($used / $total * 100) : 0;
    $hour = (int) now()->setTimezone(config('app.timezone'))->format('G');
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 18 ? __('Good afternoon') : __('Good evening'));
    $running = collect($services ?? [])->filter(fn ($s) => $s['active'] ?? false)->count();
    $totalServices = count($services ?? []);
    $allGood = ! $agentError && $running === $totalServices;
    $urgent = collect($attention)->where('tone', 'bad')->count();
    $sparkCpu = collect($points)->pluck('cpu')->filter(fn ($v) => $v !== null)->values();
@endphp
<x-layouts.admin :title="__('Dashboard')">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ now()->setTimezone(config('app.timezone'))->isoFormat('dddd D MMMM') }} · {{ $greeting }}, {{ auth()->user()->name }}</p>
            <h1>{{ $allGood ? __('Everything is running') : __('Something needs your attention') }}</h1>
            <p class="sub">
                @if ($system)<span @class(['live', 'is-bad' => ! $allGood])>{{ __(':n of :total services running', ['n' => $running, 'total' => $totalServices]) }}</span> · {{ $system['hostname'] }} · {{ $system['os'] }}@endif
                @if (count($attention)) · {{ trans_choice(':count thing needs your attention|:count things need your attention', count($attention)) }}@endif
            </p>
        </div>
        <div class="actions">
            <x-ui.button icon="globe" :href="route('admin.websites.create')">{{ __('New website') }}</x-ui.button>
            <x-ui.button variant="primary" icon="plus" :href="route('admin.customers.create')">{{ __('New customer') }}</x-ui.button>
        </div>
    </div>

    @if ($agentError)
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The server agent is not reachable.') }}</strong><p class="small">{{ $agentError }}</p></div></div>
    @endif

    <section class="bento" aria-label="{{ __('Totals') }}">
        <a class="card kpi span-3" href="{{ route('admin.customers.index') }}">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="customers" /></span>{{ __('Customers') }}</span>
            <strong class="kpi-value">{{ $counts['customers'] }}</strong>
            <span class="kpi-foot">@if ($counts['customers_30d'])<b class="up">+{{ $counts['customers_30d'] }}</b> {{ __('last 30 days') }}@else{{ __('Synced with the CRM') }}@endif</span>
        </a>
        <a class="card kpi span-3" href="{{ route('admin.websites.index') }}">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="globe" /></span>{{ __('Websites') }}</span>
            <strong class="kpi-value">{{ $counts['websites'] }}</strong>
            <span class="kpi-foot">@forelse ($counts['php_versions'] as $v => $n)PHP {{ $v }}: {{ $n }}@if (! $loop->last) · @endif @empty{{ __('No websites yet') }}@endforelse</span>
        </a>
        <a class="card kpi span-3" href="{{ route('admin.security.index') }}">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="shield" /></span>{{ __('Blocked attacks (24h)') }}</span>
            <strong class="kpi-value">{{ isset($security['alerts_24h']) ? number_format($security['alerts_24h']) : '–' }}</strong>
            <span class="kpi-foot">CrowdSec</span>
        </a>
        <a class="card kpi span-3" href="{{ route('admin.statistics.index') }}">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="chart" /></span>{{ __('CPU now') }}</span>
            <strong class="kpi-value">{{ $system ? round($system['cpu_percent']).'%' : '–' }}</strong>
            <span class="kpi-foot">
                @if ($system){{ __('Load') }} {{ $system['load'][0] ?? '–' }} · {{ $system['cpu_count'] }} {{ __('cores') }}@else – @endif
                @if ($sparkCpu->count() > 1)
                    @php $max = max(1, $sparkCpu->max()); $n = $sparkCpu->count(); $d = $sparkCpu->map(fn ($v, $i) => ($i ? 'L' : 'M').round($i / ($n - 1) * 96, 1).' '.round(28 - $v / $max * 24, 1))->join(' '); @endphp
                    <svg class="spark" viewBox="0 0 96 30" aria-hidden="true"><path d="{{ $d }} L96 30 L0 30 Z" class="spark-area" /><path d="{{ $d }}" class="spark-line" /></svg>
                @endif
            </span>
        </a>

        <div class="card span-8">
            @if (count($points) > 1)
                <x-ui.chart :title="__('Server load, last 24 hours')" :points="$points->all()" :max="100"
                    :series="[['key' => 'cpu', 'label' => __('CPU'), 'format' => fn ($v) => $v.'%'], ['key' => 'memory', 'label' => __('Memory in use'), 'format' => fn ($v) => $v.'%']]" />
            @else
                <div class="card-head"><h2>{{ __('Server load, last 24 hours') }}</h2></div>
                <p class="muted small">{{ __('Usage is measured every minute. The chart appears after the first hour.') }}</p>
            @endif
            @if ($system)
                <div class="kpi-row">
                    <div><span>RAM</span><strong>{{ $fmt($system['memory']['used']) }}</strong><small class="muted">{{ __('of :total', ['total' => $fmt($system['memory']['total'])]) }}</small></div>
                    <div><span>{{ __('Disk') }}</span><strong>{{ $pct($system['disk']['used'], $system['disk']['total']) }}%</strong><small class="muted">{{ $fmt($system['disk']['used']) }} / {{ $fmt($system['disk']['total']) }}</small></div>
                    <div><span>{{ __('Uptime') }}</span><strong>{{ \Carbon\CarbonInterval::seconds($system['uptime_seconds'])->cascade()->forHumans(['parts' => 1, 'short' => true]) }}</strong><small class="muted">Kernel {{ $system['kernel'] }}</small></div>
                    <div><span>{{ __('Load') }}</span><strong>{{ $system['load'][0] ?? '–' }}</strong><small class="muted">{{ implode(' · ', $system['load']) }}</small></div>
                </div>
            @endif
        </div>

        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Security') }}</h2>
                <a class="small" href="{{ \Illuminate\Support\Facades\Route::has('admin.vulns.index') ? route('admin.vulns.index') : route('admin.security.index') }}">{{ __('Open') }}</a></div>
            @if ($posture)
                @php $okCount = collect($posture['checks'])->where('status', 'ok')->count(); @endphp
                <div class="score">
                    <x-ui.ring :value="$posture['score']" :tone="in_array($posture['grade'], ['ok', 'warn', 'bad'], true) ? $posture['grade'] : 'warn'" :size="112"><strong>{{ $posture['score'] }}</strong><small>{{ __('of 100') }}</small></x-ui.ring>
                    <div class="score-text">
                        <x-ui.pill :status="$posture['grade']">{{ match ($posture['grade']) { 'ok' => __('Well protected'), 'warn' => __('Needs attention'), default => __('At risk') } }}</x-ui.pill>
                        <p class="muted small">{{ __(':ok of :total checks are fine', ['ok' => $okCount, 'total' => count($posture['checks'])]) }}</p>
                    </div>
                </div>
            @endif
            <ul class="checks">
                <li class="is-{{ ($security['alerts_24h'] ?? null) !== null ? 'ok' : 'warn' }}"><x-icon name="shield" /><span>{{ __('CrowdSec alerts, last 24 hours') }}</span><small>{{ $security['alerts_24h'] ?? '–' }}</small></li>
                <li class="is-{{ config('panel.require_admin_2fa') ? 'ok' : 'warn' }}"><x-icon name="lock" /><span>{{ __('Panel login: two-factor for admins') }}</span><small>{{ config('panel.require_admin_2fa') ? __('Required') : __('Optional') }}</small></li>
                <li class="is-{{ $counts['ssl_pending'] ? 'warn' : 'ok' }}"><x-icon name="globe" /><span>{{ __('Certificates waiting for Let\'s Encrypt') }}</span><small>{{ $counts['ssl_pending'] }}</small></li>
            </ul>
        </div>

        <div class="card span-5">
            <div class="card-head"><h2>{{ __('Needs attention') }}</h2>@if ($urgent)<x-ui.pill status="bad">{{ trans_choice(':count urgent|:count urgent', $urgent) }}</x-ui.pill>@endif</div>
            @if (empty($attention))
                <div class="all-clear"><span class="sev-icon ok"><x-icon name="check" /></span><div><strong>{{ __('Nothing to do') }}</strong><p class="muted small">{{ __('No stopped services, risky customers or missing certificates.') }}</p></div></div>
            @else
                <ul class="todo">
                    @foreach ($attention as $a)
                        <li><span class="sev-icon {{ $a['tone'] }}"><x-icon :name="$a['icon']" /></span>
                            <span class="todo-text"><strong>{{ $a['title'] }}</strong><small class="muted">{{ $a['detail'] }}</small></span>
                            @if ($a['url'])<x-ui.button size="sm" :href="$a['url']">{{ $a['action'] }}</x-ui.button>@endif</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Services') }}</h2><a href="{{ route('admin.services.index') }}" class="small">{{ __('Manage') }}</a></div>
            @if (empty($services))
                <p class="muted small">{{ __('No service information.') }}</p>
            @else
                <div class="svc-grid">
                    @foreach ($services as $s)
                        <span @class(['svc', 'is-off' => ! ($s['active'] ?? false)]) title="{{ ($s['active'] ?? false) ? __('Running') : __('Stopped') }}"><i aria-hidden="true"></i>{{ $s['label'] }}<span class="sr-only"> ({{ ($s['active'] ?? false) ? __('Running') : __('Stopped') }})</span></span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="card span-3">
            <div class="card-head"><h2>{{ __('Server') }}</h2></div>
            @if ($system)
                <dl class="kv kv-tight">
                    <dt>{{ __('Host') }}</dt><dd>{{ $system['hostname'] }}</dd>
                    <dt>OS</dt><dd>Debian {{ $system['os_version'] ?? '' }}</dd>
                    <dt>{{ __('Cores') }}</dt><dd>{{ $system['cpu_count'] }}</dd>
                    <dt>RAM</dt><dd>{{ $fmt($system['memory']['total']) }}</dd>
                    <dt>{{ __('Disk') }}</dt><dd>{{ $fmt($system['disk']['total']) }}</dd>
                </dl>
            @else<p class="muted small">–</p>@endif
        </div>

        <div class="card span-12 card-flush">
            <div class="card-head"><h2>{{ __('Latest customers') }}</h2><a href="{{ route('admin.customers.index') }}" class="small">{{ __('All customers') }}</a></div>
            @if ($latestCustomers->isEmpty())
                <x-ui.empty icon="customers">{{ __('No customers yet.') }}
                    <x-slot:action><x-ui.button variant="primary" icon="plus" :href="route('admin.customers.create')">{{ __('New customer') }}</x-ui.button></x-slot:action>
                </x-ui.empty>
            @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Customer') }}</th><th class="num">{{ __('Subscriptions') }}</th><th>{{ __('Status') }}</th></x-slot:head>
                @foreach ($latestCustomers as $c)
                    <tr>
                        <td><div class="who"><span class="avatar avatar-soft" aria-hidden="true">{{ collect(preg_split('/\s+/', trim($c->displayName())))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('') }}</span>
                            <span><a class="strong" href="{{ route('admin.customers.show', $c) }}">{{ $c->displayName() }}</a><small class="muted">{{ $c->email }}</small></span></div></td>
                        <td class="num">{{ $c->websites_count }}</td>
                        <td><x-ui.pill :status="$c->status">{{ __(ucfirst($c->status)) }}</x-ui.pill></td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </div>
    </section>
</x-layouts.admin>
