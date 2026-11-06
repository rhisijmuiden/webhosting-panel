@php
    $fmtBytes = fn ($v) => \Illuminate\Support\Number::fileSize($v ?? 0, precision: 1);
    $fmtNum = fn ($v) => \Illuminate\Support\Number::format($v ?? 0, locale: app()->getLocale());
    $q = $range->query();
    $presets = ['24h' => __('24 hours'), '7d' => __('7 days'), '30d' => __('30 days'), '90d' => __('90 days'), '1y' => __('1 year'), '2y' => __('2 years')];
    $tz = config('app.timezone');
    $i18n = [
        'locale' => str_replace('_', '-', app()->getLocale()),
        'empty' => __('No data for this period yet.'),
        'loading' => __('Loading…'),
        'failed' => __('The graph could not be loaded.'),
        'zoom_hint' => __('Drag across a graph to zoom in.'),
        'all' => __('All'),
        'none' => __('None'),
    ];
    $assetV = fn ($f) => @filemtime(public_path($f));
@endphp
<x-layouts.admin :title="__('Statistics')">
    <link rel="stylesheet" href="{{ asset('assets/vendor/uplot/uPlot.min.css') }}?v={{ $assetV('assets/vendor/uplot/uPlot.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/statistics.css') }}?v={{ $assetV('assets/statistics.css') }}">
    <script src="{{ asset('assets/vendor/uplot/uPlot.iife.min.js') }}?v={{ $assetV('assets/vendor/uplot/uPlot.iife.min.js') }}" defer></script>
    <script src="{{ asset('assets/statistics.js') }}?v={{ $assetV('assets/statistics.js') }}" defer></script>

    <div class="stats-page" data-stats-url="{{ route('admin.statistics.data') }}" data-stats-range='@json($q)' data-stats-i18n='@json($i18n)'>
    <div class="page-head">
        <div>
            <h1>{{ __('Statistics') }}</h1>
            <p class="sub">{{ __('Server load, visitors and data traffic over time, for the whole server and per customer or website.') }}</p>
        </div>
    </div>

    <form class="stats-toolbar card" method="get" action="{{ route('admin.statistics.index') }}" aria-label="{{ __('Period') }}">
        <nav class="seg" aria-label="{{ __('Period') }}">
            @foreach ($presets as $key => $label)
                <a href="?{{ http_build_query(['range' => $key, 'sort' => $sort]) }}"><span @if ($range->key === $key) class="is-on" aria-current="true" @endif>{{ $label }}</span></a>
            @endforeach
        </nav>
        <div class="stats-custom">
            <input type="hidden" name="range" value="custom">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <label><span class="small muted">{{ __('From') }}</span>
                <input class="input" type="datetime-local" name="from" required value="{{ $range->from->setTimezone($tz)->format('Y-m-d\TH:i') }}"></label>
            <label><span class="small muted">{{ __('To') }}</span>
                <input class="input" type="datetime-local" name="to" value="{{ $range->to->setTimezone($tz)->format('Y-m-d\TH:i') }}"></label>
            <x-ui.button type="submit" icon="chart">{{ __('Show') }}</x-ui.button>
            @if ($range->key === 'custom')
                <x-ui.button :href="'?'.http_build_query(['range' => '24h', 'sort' => $sort])">{{ __('Reset zoom') }}</x-ui.button>
            @endif
        </div>
        <p class="small muted stats-hint"><x-icon name="info" /> {{ __('Drag across a graph to zoom in. Double-click a graph to zoom out.') }}</p>
    </form>

    <section class="bento" aria-label="{{ __('Now') }}">
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="chart" /></span>{{ __('CPU now') }}</span>
            <strong class="kpi-value">{{ $latest ? round($latest->cpu_percent).'%' : '–' }}</strong>
            <span class="kpi-foot">{{ __('Load') }} {{ $latest->load1 ?? '–' }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="server" /></span>{{ __('Memory in use') }}</span>
            <strong class="kpi-value">{{ $latest ? $fmtBytes($latest->memory_used) : '–' }}</strong>
            <span class="kpi-foot">{{ $latest ? __('of :size', ['size' => $fmtBytes($latest->memory_total)]) : '' }} · {{ __('Disk') }} {{ $latest && $latest->disk_total ? round($latest->disk_used / $latest->disk_total * 100).'%' : '–' }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="customers" /></span>{{ __('Visitors in this period') }}</span>
            <strong class="kpi-value">{{ $fmtNum($totals['visitors']) }}</strong>
            <span class="kpi-foot">{{ __(':n page views', ['n' => $fmtNum($totals['pageviews'])]) }} · {{ __(':n today', ['n' => $fmtNum($totals['visitors_today'])]) }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="globe" /></span>{{ __('Data traffic in this period') }}</span>
            <strong class="kpi-value">{{ $fmtBytes($totals['bytes']) }}</strong>
            <span class="kpi-foot">{{ __(':n requests', ['n' => $fmtNum($totals['requests'])]) }}@if ($totals['requests']) · {{ __(':pct% bots', ['pct' => round($totals['bots'] / $totals['requests'] * 100)]) }}@endif</span>
        </div>
    </section>

    <section class="card stats-breakdown" data-breakdown>
        <div class="card-head stats-breakdown-head">
            <div>
                <h2>{{ __('Per customer or website') }}</h2>
                <p class="small muted">{{ __('The busiest ones in this period. Click a name in the legend to hide or show it.') }}</p>
            </div>
            <div class="stats-controls">
                <div class="seg" role="radiogroup" aria-label="{{ __('Split by') }}">
                    <label><input type="radio" name="bd-by" value="customer" checked><span>{{ __('Customers') }}</span></label>
                    <label><input type="radio" name="bd-by" value="website"><span>{{ __('Websites') }}</span></label>
                </div>
                <label class="sr-only" for="bd-metric">{{ __('Show') }}</label>
                <select class="select" id="bd-metric" name="bd-metric">
                    <option value="visitors">{{ __('Visitors') }}</option>
                    <option value="pageviews">{{ __('Page views') }}</option>
                    <option value="requests">{{ __('Requests') }}</option>
                    <option value="bytes">{{ __('Data traffic') }}</option>
                    <option value="cpu_percent">{{ __('CPU') }}</option>
                    <option value="memory_bytes">{{ __('Memory') }}</option>
                </select>
                <label class="sr-only" for="bd-customer">{{ __('Customer') }}</label>
                <select class="select" id="bd-customer" name="bd-customer" hidden>
                    <option value="">{{ __('All customers') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->company ?: $c->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="stats-plot stats-plot-tall" data-plot></div>
        <div class="stats-legend" data-legend></div>
    </section>

    <h2 class="stats-section">{{ __('Whole server') }}</h2>
    <section class="grid grid-charts">
        @foreach ([
            'cpu' => __('CPU'), 'load' => __('Load'), 'memory' => __('Memory'), 'network' => __('Network'),
            'visitors' => __('Visitors and page views'), 'requests' => __('Requests'), 'traffic' => __('Data traffic of the websites'), 'disk' => __('Disk space'),
        ] as $chart => $title)
            <figure class="card stats-chart" data-chart="{{ $chart }}">
                <figcaption class="chart-head"><h3>{{ $title }}</h3><span class="legend" data-legend></span></figcaption>
                <div class="stats-plot" data-plot></div>
            </figure>
        @endforeach
    </section>

    <div class="stats-section stats-rank-head">
        <h2>{{ __('Heaviest websites') }}</h2>
        <nav class="seg" aria-label="{{ __('Sort by') }}">
            @foreach (['cpu' => __('CPU'), 'memory' => __('Memory'), 'visitors' => __('Visitors'), 'traffic' => __('Data traffic')] as $key => $label)
                <a href="?{{ http_build_query($q + ['sort' => $key]) }}"><span @if ($sort === $key) class="is-on" aria-current="true" @endif>{{ $label }}</span></a>
            @endforeach
        </nav>
    </div>
    <section class="grid stats-rank">
        <x-ui.card :title="__('Websites')" flush>
            @if ($topWebsites->isEmpty())
                <x-ui.empty icon="chart">{{ __('No usage per website yet.') }}</x-ui.empty>
            @else
            @php $sortCol = ['cpu' => 'cpu', 'memory' => 'memory', 'visitors' => 'visitors', 'traffic' => 'bytes'][$sort]; $maxV = max(0.01, $topWebsites->max($sortCol)); @endphp
            <x-ui.table>
                <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Average CPU') }}</th><th class="num">{{ __('Peak') }}</th><th class="num">{{ __('Memory') }}</th><th class="num">{{ __('Visitors') }}</th><th class="num">{{ __('Data traffic') }}</th></x-slot:head>
                @foreach ($topWebsites as $w)
                    <tr>
                        <td><a class="strong" href="{{ route('admin.websites.show', $w->website_id) }}">{{ $w->domain }}</a><div class="small muted">{{ $w->customer }}</div></td>
                        <td style="min-width:140px"><div class="rank-cell"><div class="rank-bar" style="width:{{ round($w->$sortCol / $maxV * 100) }}%" aria-hidden="true"></div><span class="small">{{ round($w->cpu, 1) }}%</span></div></td>
                        <td class="num">{{ round($w->cpu_peak, 1) }}%</td>
                        <td class="num">{{ $fmtBytes($w->memory) }}</td>
                        <td class="num">{{ $fmtNum($w->visitors) }}</td>
                        <td class="num">{{ $fmtBytes($w->bytes) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>
        <x-ui.card :title="__('Customers')" flush>
            @if ($topCustomers->isEmpty())
                <x-ui.empty icon="customers">{{ __('No usage per customer yet.') }}</x-ui.empty>
            @else
            @php $maxV = max(0.01, $topCustomers->max($sortCol)); @endphp
            <x-ui.table>
                <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('Average CPU') }}</th><th class="num">{{ __('Websites') }}</th><th class="num">{{ __('Visitors') }}</th><th class="num">{{ __('Data traffic') }}</th></x-slot:head>
                @foreach ($topCustomers as $c)
                    <tr>
                        <td><a class="strong" href="{{ route('admin.customers.show', $c->customer_id) }}">{{ $c->customer }}</a></td>
                        <td style="min-width:140px"><div class="rank-cell"><div class="rank-bar" style="width:{{ round($c->$sortCol / $maxV * 100) }}%" aria-hidden="true"></div><span class="small">{{ round($c->cpu, 1) }}%</span></div></td>
                        <td class="num">{{ $c->websites }}</td>
                        <td class="num">{{ $fmtNum($c->visitors) }}</td>
                        <td class="num">{{ $fmtBytes($c->bytes) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>
    </section>
    <p class="muted small">{{ __('CPU is a share of the whole server. A visitor is a unique device (IP address and browser) that opened at least one page; bots are not counted, and IP addresses are never stored. Visitors of a customer are added up over its websites. Load is kept per minute for 2 days, per hour for 90 days and per day after that; visitors per hour for 400 days and per day after that.') }}</p>
    </div>
</x-layouts.admin>
