<x-layouts.admin :title="__('Web application firewall')">
    @include('waf::partials.styles')
    @php $available = $status['available'] ?? null; @endphp
    <div class="page-head">
        <div>
            <p class="eyebrow">
                @if ($available === null)<span class="live is-bad">{{ __('Server agent not reachable') }}</span>
                @elseif (! $available)<span class="live is-bad">{{ __('Not installed on this server') }}</span>
                @else<span class="live">{{ __('Active') }}</span><span>· ModSecurity {{ $status['engine'] ?? '?' }} · OWASP CRS {{ $status['crs'] ?? '?' }}</span>@endif
            </p>
            <h1>{{ __('Web application firewall') }}</h1>
            <p class="sub">{{ __('Checks every request to the websites for attacks such as SQL injection, cross-site scripting and code injection, before PHP sees it.') }}</p>
        </div>
        <div class="actions">
            <form method="POST" action="{{ route('admin.waf.collect') }}">@csrf<x-ui.button icon="refresh">{{ __('Fetch new events') }}</x-ui.button></form>
            <x-ui.button :href="route('admin.waf.settings')" icon="settings">{{ __('Settings') }}</x-ui.button>
        </div>
    </div>
    @include('waf::partials.admin-tabs')
    @include('waf::partials.alerts')
    @if ($last && ! $last['ok'] && ! $errors->has('agent'))
        <div class="alert alert-warn" role="alert"><x-icon name="info" />{{ __('The last change did not reach the server (:at): :error', ['at' => \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans(), 'error' => $last['error'] ?? '']) }}</div>
    @endif
    @if ($summary['default_mode'] === 'detect')
        <div class="alert alert-warn" role="status"><x-icon name="info" />
            <span>{{ __('Websites without their own choice are in detect-only mode: attacks are logged but not stopped. Check the events below for false alarms, then switch the default to blocking.') }}
            <a href="{{ route('admin.waf.settings') }}">{{ __('Change the default') }}</a></span></div>
    @endif

    <section class="bento">
        <div class="card span-8">
            <div class="kpi-row">
                <div><span>{{ __('Blocked, 24 h') }}</span><strong>{{ number_format($summary['blocked_24h']) }}</strong></div>
                <div><span>{{ __('Detected only, 24 h') }}</span><strong>{{ number_format($summary['detected_24h']) }}</strong></div>
                <div><span>{{ __('Blocked, 7 days') }}</span><strong>{{ number_format($summary['blocked_7d']) }}</strong></div>
                <div><span>{{ __('Attacking addresses, 7 days') }}</span><strong>{{ number_format($summary['attackers_7d']) }}</strong></div>
            </div>
            <x-ui.chart :title="__('Attacks per day')" :points="$perDay" :series="[
                ['key' => 'blocked', 'label' => __('Blocked'), 'format' => fn ($v) => number_format($v)],
                ['key' => 'detected', 'label' => __('Detected only'), 'format' => fn ($v) => number_format($v)],
            ]" />
        </div>
        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Websites') }}</h2><x-ui.button size="sm" :href="route('admin.waf.sites')">{{ __('Manage') }}</x-ui.button></div>
            <div class="waf-split">
                <div class="m-block"><strong>{{ $summary['modes']['block'] }}</strong><span>{{ __('Blocking') }}</span></div>
                <div class="m-detect"><strong>{{ $summary['modes']['detect'] }}</strong><span>{{ __('Detect only') }}</span></div>
                <div class="m-off"><strong>{{ $summary['modes']['off'] }}</strong><span>{{ __('Off') }}</span></div>
            </div>
            <h3 class="eyebrow" style="margin-top:18px">{{ __('Most attacked, 7 days') }}</h3>
            @if (empty($summary['top_sites']))
                <p class="muted small">{{ __('No attacks this week.') }}</p>
            @else
                <ul class="waf-top">
                    @foreach ($summary['top_sites'] as $s)
                        <li><a class="t" href="{{ route('admin.waf.site', $s['id']) }}">{{ $s['domain'] }}</a><span class="small nowrap"><strong>{{ number_format($s['hits']) }}</strong> <span class="muted">· {{ __(':n blocked', ['n' => number_format($s['blocked'])]) }}</span></span></li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="card span-12">
            <div class="card-head"><h2>{{ __('Rules that matched most, 7 days') }}</h2><span class="muted small">{{ __('A rule that fires on one site all the time is often a false alarm: open an event and allow it.') }}</span></div>
            @if (empty($summary['top_rules']))
                <p class="muted small">{{ __('No rule matched this week.') }}</p>
            @else
                <ul class="waf-top">
                    @foreach ($summary['top_rules'] as $r)
                        <li><span class="t"><a href="{{ route('admin.waf.index', ['rule' => $r['id']]) }}"><code>{{ $r['id'] }}</code></a> {{ $r['message'] }}</span><strong class="nowrap">{{ number_format($r['hits']) }}</strong></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <x-ui.card :title="__('Events')" flush id="events">
        <form method="GET" action="{{ route('admin.waf.index') }}#events" class="waf-filters">
            <x-ui.field type="select" name="type" :label="__('Result')">
                <option value="">{{ __('All') }}</option>
                <option value="blocked" @selected(($filters['type'] ?? '') === 'blocked')>{{ __('Blocked') }}</option>
                <option value="detected" @selected(($filters['type'] ?? '') === 'detected')>{{ __('Detected only') }}</option>
            </x-ui.field>
            <x-ui.field type="select" name="site" :label="__('Website')">
                <option value="">{{ __('All websites') }}</option>
                @foreach ($sites as $id => $domain)<option value="{{ $id }}" @selected((string) ($filters['site'] ?? '') === (string) $id)>{{ $domain }}</option>@endforeach
            </x-ui.field>
            <x-ui.field name="rule" :label="__('Rule')" :value="$filters['rule'] ?? ''" placeholder="942100" inputmode="numeric" />
            <x-ui.field name="ip" :label="__('IP address')" :value="$filters['ip'] ?? ''" />
            <div><button class="btn" type="submit"><x-icon name="search" />{{ __('Filter') }}</button></div>
        </form>
        @include('waf::partials.events', ['events' => $events, 'showSite' => true, 'eventUrl' => fn ($e) => route('admin.waf.event', $e)])
    </x-ui.card>
</x-layouts.admin>
