@php $fmtBytes = fn ($v) => \Illuminate\Support\Number::fileSize($v ?? 0, precision: 1); @endphp
<x-layouts.admin :title="__('Statistics')">
    <div class="page-head">
        <div><h1>{{ __('Statistics') }}</h1><p class="sub">{{ __('Server load over time, and which websites use the most.') }}</p></div>
        <nav class="seg" aria-label="{{ __('Period') }}">
            @foreach (['24h' => __('24 hours'), '7d' => __('7 days'), '30d' => __('30 days'), '1y' => __('1 year')] as $key => $label)
                <a href="?range={{ $key }}" style="text-decoration:none"><span @if ($range === $key) style="background:var(--nav-active-bg);color:var(--nav-active-ink)" aria-current="true" @endif>{{ $label }}</span></a>
            @endforeach
        </nav>
    </div>

    <section class="grid grid-stats">
        <div class="card stat"><span class="label">{{ __('CPU now') }}</span><strong class="value">{{ $latest ? $latest['cpu'].'%' : '–' }}</strong><span class="hint">{{ __('Average :avg% · peak :peak%', ['avg' => $avgCpu, 'peak' => $peakCpu ?? 0]) }}</span></div>
        <div class="card stat"><span class="label">{{ __('Memory in use') }}</span><strong class="value">{{ $latest ? $fmtBytes($latest['memory_used']) : '–' }}</strong><span class="hint">{{ $latest ? __('of :size', ['size' => $fmtBytes($latest['memory_total'])]) : '' }}</span></div>
        <div class="card stat"><span class="label">{{ __('Disk in use') }}</span><strong class="value">{{ $latest ? $latest['disk'].'%' : '–' }}</strong><span class="hint">{{ $latest ? $fmtBytes($latest['disk_used']) : '' }}</span></div>
        <div class="card stat"><span class="label">{{ __('Load') }}</span><strong class="value">{{ $latest['load'] ?? '–' }}</strong><span class="hint">{{ __('Average over 1 minute') }}</span></div>
    </section>

    <section class="grid grid-charts">
        @foreach ($charts as $chart)
            <div class="card"><x-ui.chart :title="$chart['title']" :points="$points->all()" :series="$chart['series']" :max="$chart['max']" :axis="$chart['axis'] ?? null" /></div>
        @endforeach
    </section>

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Heaviest websites')" flush>
            @if ($topWebsites->isEmpty())
                <x-ui.empty icon="chart">{{ __('No usage per website yet.') }}</x-ui.empty>
            @else
            @php $maxCpu = max(0.01, $topWebsites->max('cpu')); @endphp
            <x-ui.table>
                <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Average CPU') }}</th><th class="num">{{ __('Peak') }}</th><th class="num">{{ __('Memory') }}</th></x-slot:head>
                @foreach ($topWebsites as $w)
                    <tr>
                        <td><a class="strong" href="{{ route('admin.websites.show', $w->website_id) }}">{{ $w->domain }}</a><div class="small muted">{{ $w->company ?: $w->name }}</div></td>
                        <td style="min-width:140px"><div style="display:flex;align-items:center;gap:8px"><div class="rank-bar" style="width:{{ round($w->cpu / $maxCpu * 100) }}%" aria-hidden="true"></div><span class="small">{{ round($w->cpu, 1) }}%</span></div></td>
                        <td class="num">{{ round($w->cpu_peak, 1) }}%</td>
                        <td class="num">{{ $fmtBytes($w->memory) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>
        <x-ui.card :title="__('Heaviest customers')" flush>
            @if ($topCustomers->isEmpty())
                <x-ui.empty icon="customers">{{ __('No usage per customer yet.') }}</x-ui.empty>
            @else
            @php $maxCpu = max(0.01, $topCustomers->max('cpu')); @endphp
            <x-ui.table>
                <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('Average CPU') }}</th><th class="num">{{ __('Websites') }}</th><th class="num">{{ __('Memory') }}</th></x-slot:head>
                @foreach ($topCustomers as $c)
                    <tr>
                        <td><a class="strong" href="{{ route('admin.customers.show', $c->customer_id) }}">{{ $c->name }}</a></td>
                        <td style="min-width:140px"><div style="display:flex;align-items:center;gap:8px"><div class="rank-bar" style="width:{{ round($c->cpu / $maxCpu * 100) }}%" aria-hidden="true"></div><span class="small">{{ round($c->cpu, 1) }}%</span></div></td>
                        <td class="num">{{ $c->websites }}</td>
                        <td class="num">{{ $fmtBytes($c->memory) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>
    </section>
    <p class="muted small">{{ __('CPU is a share of the whole server. Samples are kept per minute for 2 days, per hour for 90 days and per day after that.') }}</p>
</x-layouts.admin>
