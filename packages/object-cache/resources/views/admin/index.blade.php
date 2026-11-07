<x-layouts.admin :title="__('Object cache')">
    <style>
        .oc-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
        .oc-kpis div { display: flex; flex-direction: column; gap: 2px; }
        .oc-kpis span { font-size: 12.5px; color: var(--ink-muted); font-weight: 600; }
        .oc-kpis strong { font-size: 24px; font-weight: 800; font-variant-numeric: tabular-nums; }
        .oc-kpis small { color: var(--ink-muted); }
        .oc-mem { min-width: 160px; }
        .oc-mem .meter { margin-top: 4px; }
        .oc-mem .meter .bar { min-width: 110px; }
        .oc-table td.num { white-space: nowrap; }
        .oc-search { max-width: 240px; }
        @media (max-width: 900px) { .oc-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>
    @php
        $ratios = collect($live)->pluck('stats.hit_ratio')->filter(fn ($r) => $r !== null);
    @endphp
    <div class="page-head">
        <div>
            <h1>{{ __('Object cache') }}</h1>
            <p class="sub">{{ __('Every website with the object cache on has its own Valkey server, running as the website user in its resource jail, on a unix socket only. The memory comes from the service plan.') }}</p>
        </div>
        <div class="actions">
            <x-ui.button icon="package" :href="route('admin.packages.index')">{{ __('Service plans') }}</x-ui.button>
        </div>
    </div>

    @error('object_cache')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror

    <x-ui.card>
        <div class="oc-kpis">
            <div><span>{{ __('Websites with object cache') }}</span><strong>{{ $caches->count() }}</strong></div>
            <div><span>{{ __('Memory in use') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($totalMemory, 1) }}</strong>
                <small>{{ __('of :max reserved', ['max' => \Illuminate\Support\Number::fileSize($totalMax)]) }}</small></div>
            <div><span>{{ __('Average hit ratio') }}</span><strong>{{ $ratios->isNotEmpty() ? \Illuminate\Support\Number::format($ratios->avg(), 1).' %' : '–' }}</strong></div>
            <div><span>{{ __('Server default') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($defaultMb * 1024 * 1024) }}</strong>
                <small>{{ __('For plans without their own limit') }}</small></div>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Websites')" flush class="oc-table">
        <x-slot:actions>
            <form method="GET" class="actions" role="search">
                <input type="search" class="input oc-search" name="q" value="{{ $search }}" placeholder="{{ __('Search domain') }}" aria-label="{{ __('Search domain') }}">
            </form>
        </x-slot:actions>
        @if ($caches->isEmpty())
            <x-ui.empty icon="bolt">{{ __('No website uses the object cache yet. Switch it on from the Hosting & DNS tab of a domain.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head>
                <th>{{ __('Domain') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Status') }}</th><th>{{ __('Memory') }}</th>
                <th class="num">{{ __('Hit ratio') }}</th><th class="num">{{ __('Items') }}</th><th>{{ __('WordPress') }}</th><th></th>
            </x-slot:head>
            @foreach ($caches as $c)
                @php
                    $st = $live[$c->website->domain] ?? null;
                    $s = $st['stats'] ?? null;
                    $state = $c->isBusy() ? 'applying' : ($st['state'] ?? $c->state);
                    $pct = ($s['maxmemory_bytes'] ?? 0) > 0 ? $s['used_memory_bytes'] / $s['maxmemory_bytes'] * 100 : 0;
                @endphp
                <tr>
                    <td><a class="strong" href="{{ route('admin.websites.show', [$c->website, 'tab' => 'hosting']) }}#object-cache">{{ $c->website->domain }}</a></td>
                    <td>{{ $c->website->customer?->displayName() ?? '–' }}</td>
                    <td>
                        @switch($state)
                            @case('applying')<x-ui.pill status="pending">{{ __('Being set up') }}</x-ui.pill>@break
                            @case('running')<x-ui.pill status="running">{{ __('Running') }}</x-ui.pill>@break
                            @case('failed')<x-ui.pill status="failed">{{ __('Failed') }}</x-ui.pill>@break
                            @default<x-ui.pill status="warn">{{ __('Stopped') }}</x-ui.pill>
                        @endswitch
                    </td>
                    <td class="oc-mem">
                        <span class="small">{{ $s ? \Illuminate\Support\Number::fileSize($s['used_memory_bytes'], 1) : '–' }} / {{ \Illuminate\Support\Number::fileSize(($c->memory_mb ?? 0) * 1024 * 1024) }}</span>
                        @if ($s)<x-ui.meter label="" :percent="$pct" />@endif
                    </td>
                    <td class="num">{{ ($s['hit_ratio'] ?? null) !== null ? \Illuminate\Support\Number::format($s['hit_ratio'], 1).' %' : '–' }}</td>
                    <td class="num">{{ $s ? \Illuminate\Support\Number::format($s['keys']) : '–' }}</td>
                    <td>
                        @php $wp = $c->configuredInstalls(); @endphp
                        @if ($wp)
                            {{ trans_choice(':count install|:count installs', count($wp)) }}@if ($c->hasWooCommerce()) · WooCommerce @endif
                        @elseif ($c->failedInstalls())
                            <x-ui.pill status="warn">{{ __('Not connected') }}</x-ui.pill>
                        @else
                            <span class="muted">–</span>
                        @endif
                    </td>
                    <td class="num">
                        @if ($state === 'running')
                            <form method="POST" action="{{ route('admin.object-cache.flush', $c->website) }}" data-confirm="{{ __('Empty the object cache of :domain? It fills itself again.', ['domain' => $c->website->domain]) }}">@csrf
                                <button class="btn btn-sm" type="submit"><x-icon name="refresh" />{{ __('Flush cache') }}</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.admin>
