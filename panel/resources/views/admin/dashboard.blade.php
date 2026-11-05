@php
    $fmt = fn ($bytes) => \Illuminate\Support\Number::fileSize($bytes ?? 0, precision: 1);
    $pct = fn ($used, $total) => $total ? round($used / $total * 100) : 0;
@endphp
<x-layouts.admin :title="__('Dashboard')">
    <div class="page-head">
        <div>
            <h1>{{ __('Dashboard') }}</h1>
            @if ($system)
                <p class="sub">{{ $system['hostname'] }} · {{ $system['os'] }}@if ($system['container']) · {{ strtoupper($system['container']) }}@endif</p>
            @endif
        </div>
        <div class="actions">
            <form class="search" method="GET" action="{{ route('admin.customers.index') }}" role="search">
                <x-icon name="search" /><input type="search" name="q" placeholder="{{ __('Search customers, domains') }}" aria-label="{{ __('Search') }}">
            </form>
            <x-ui.button variant="primary" icon="plus" :href="route('admin.customers.create')">{{ __('New customer') }}</x-ui.button>
        </div>
    </div>

    @if ($agentError)
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The server agent is not reachable.') }}</strong><p class="small">{{ $agentError }}</p></div></div>
    @endif

    <section class="grid grid-stats" aria-label="{{ __('Totals') }}">
        <div class="card stat"><span class="label">{{ __('Customers') }}</span><strong class="value">{{ $counts['customers'] }}</strong><span class="hint">{{ __('Synced with the CRM') }}</span></div>
        <div class="card stat"><span class="label">{{ __('Websites') }}</span><strong class="value">{{ $counts['websites'] }}</strong>
            <span class="hint">@forelse ($counts['php_versions'] as $v => $n)PHP {{ $v }}: {{ $n }}@if (! $loop->last) · @endif @empty{{ __('No websites yet') }}@endforelse</span></div>
        <div class="card stat"><span class="label">{{ __('Blocked attacks (24h)') }}</span><strong class="value">{{ $security['alerts_24h'] ?? '–' }}</strong><span class="hint">CrowdSec</span></div>
        <div class="card stat"><span class="label">{{ __('Server load') }}</span>
            @if ($system)
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px">
                    <x-ui.meter label="CPU" :percent="$system['cpu_percent']" />
                    <x-ui.meter label="RAM" :percent="$pct($system['memory']['used'], $system['memory']['total'])" />
                    <x-ui.meter :label="__('Disk')" :percent="$pct($system['disk']['used'], $system['disk']['total'])" />
                </div>
            @else<span class="hint">–</span>@endif
        </div>
    </section>

    <section class="grid grid-2">
        <x-ui.card :title="__('Services')">
            <x-slot:actions><a href="{{ route('admin.services.index') }}" class="small">{{ __('Manage') }}</a></x-slot:actions>
            <div>
                @forelse ($services ?? [] as $s)
                    <div class="row"><span>{{ $s['label'] }}</span>
                        @if ($s['active'])<x-ui.pill status="ok">{{ __('Running') }}</x-ui.pill>@else<x-ui.pill status="bad">{{ __('Stopped') }}</x-ui.pill>@endif
                    </div>
                @empty
                    <p class="muted small">{{ __('No service information.') }}</p>
                @endforelse
            </div>
        </x-ui.card>

        <div style="display:flex;flex-direction:column;gap:16px">
            <x-ui.card :title="__('Security')">
                <x-slot:actions><a href="{{ route('admin.security.index') }}" class="small">{{ __('Open') }}</a></x-slot:actions>
                <div>
                    <div class="row"><span>{{ __('CrowdSec alerts, last 24 hours') }}</span><strong>{{ $security['alerts_24h'] ?? '–' }}</strong></div>
                    <div class="row"><span>{{ __('Panel login: two-factor for admins') }}</span>
                        @if (config('panel.require_admin_2fa'))<x-ui.pill status="ok">{{ __('Required') }}</x-ui.pill>@else<x-ui.pill status="warn">{{ __('Optional') }}</x-ui.pill>@endif</div>
                    <div class="row"><span>{{ __('Certificates waiting for Let\'s Encrypt') }}</span>
                        @if ($counts['ssl_pending'])<x-ui.pill status="warn">{{ $counts['ssl_pending'] }}</x-ui.pill>@else<x-ui.pill status="ok">0</x-ui.pill>@endif</div>
                </div>
            </x-ui.card>
            @if ($system)
            <x-ui.card :title="__('Server')">
                <dl class="kv">
                    <dt>{{ __('Uptime') }}</dt><dd>{{ \Carbon\CarbonInterval::seconds($system['uptime_seconds'])->cascade()->forHumans(['parts' => 2]) }}</dd>
                    <dt>{{ __('Load') }}</dt><dd>{{ implode(' · ', $system['load']) }} ({{ $system['cpu_count'] }} {{ __('cores') }})</dd>
                    <dt>RAM</dt><dd>{{ $fmt($system['memory']['used']) }} / {{ $fmt($system['memory']['total']) }}</dd>
                    <dt>{{ __('Disk') }}</dt><dd>{{ $fmt($system['disk']['used']) }} / {{ $fmt($system['disk']['total']) }}</dd>
                    <dt>Kernel</dt><dd>{{ $system['kernel'] }}</dd>
                </dl>
            </x-ui.card>
            @endif
        </div>
    </section>

    <x-ui.card :title="__('Latest customers')" flush>
        <x-slot:actions><a href="{{ route('admin.customers.index') }}" class="small">{{ __('All customers') }}</a></x-slot:actions>
        @if ($latestCustomers->isEmpty())
            <x-ui.empty icon="customers">{{ __('No customers yet.') }}
                <x-slot:action><x-ui.button variant="primary" icon="plus" :href="route('admin.customers.create')">{{ __('New customer') }}</x-ui.button></x-slot:action>
            </x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('Package') }}</th><th class="num">{{ __('Websites') }}</th><th>{{ __('Status') }}</th></x-slot:head>
            @foreach ($latestCustomers as $c)
                <tr>
                    <td><a class="strong" href="{{ route('admin.customers.show', $c) }}">{{ $c->displayName() }}</a></td>
                    <td>{{ $c->package?->name ?? '–' }}</td>
                    <td class="num">{{ $c->websites_count }}</td>
                    <td><x-ui.pill :status="$c->status">{{ __(ucfirst($c->status)) }}</x-ui.pill></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.admin>
