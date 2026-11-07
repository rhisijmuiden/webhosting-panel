<x-layouts.client :title="__('Web firewall')">
    @include('waf::partials.styles')
    <div class="page-head"><div>
        <h1>{{ __('Web firewall') }}</h1>
        <p class="sub">{{ __('Every visit to your websites is checked for attacks such as SQL injection, cross-site scripting and code injection. Attacks are stopped before they reach your website.') }}</p>
    </div></div>
    @include('waf::partials.alerts')
    <section class="bento">
        <div class="card span-8">
            <div class="kpi-row">
                <div><span>{{ __('Attacks blocked, 24 h') }}</span><strong>{{ number_format($summary['blocked_24h']) }}</strong></div>
                <div><span>{{ __('Attacks blocked, 7 days') }}</span><strong>{{ number_format($summary['blocked_7d']) }}</strong></div>
                <div><span>{{ __('Attacking addresses, 7 days') }}</span><strong>{{ number_format($summary['attackers_7d']) }}</strong></div>
            </div>
            <x-ui.chart :title="__('Attacks per day')" :points="$perDay" :series="[
                ['key' => 'blocked', 'label' => __('Blocked'), 'format' => fn ($v) => number_format($v)],
                ['key' => 'detected', 'label' => __('Detected only'), 'format' => fn ($v) => number_format($v)],
            ]" />
        </div>
        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Your websites') }}</h2></div>
            <div class="waf-split">
                <div class="m-block"><strong>{{ $summary['modes']['block'] }}</strong><span>{{ __('Blocking') }}</span></div>
                <div class="m-detect"><strong>{{ $summary['modes']['detect'] }}</strong><span>{{ __('Detect only') }}</span></div>
                <div class="m-off"><strong>{{ $summary['modes']['off'] }}</strong><span>{{ __('Off') }}</span></div>
            </div>
            @if ($summary['top_rules'])
                <h3 class="eyebrow" style="margin-top:18px">{{ __('Most seen, 7 days') }}</h3>
                <ul class="waf-top">@foreach (array_slice($summary['top_rules'], 0, 3) as $r)<li><span class="t small">{{ $r['message'] }}</span><strong>{{ number_format($r['hits']) }}</strong></li>@endforeach</ul>
            @endif
        </div>
    </section>
    <x-ui.card flush>
        @if ($websites->isEmpty())
            <x-ui.empty icon="globe">{{ __('You have no websites yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Firewall') }}</th><th class="num">{{ __('Blocked, 30 days') }}</th><th class="num">{{ __('Detected, 30 days') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($websites as $w)
                @php $c = $counts[$w->id] ?? null; @endphp
                <tr>
                    <td><a class="strong" href="{{ route('client.waf.show', $w) }}">{{ $w->domain }}</a></td>
                    <td>@include('waf::partials.mode-pill', ['mode' => $waf->modeFor($rows[$w->id] ?? null)])</td>
                    <td class="num">{{ number_format((int) ($c->blocked ?? 0)) }}</td>
                    <td class="num">{{ number_format((int) ($c->detected ?? 0)) }}</td>
                    <td class="num"><x-ui.button size="sm" :href="route('client.waf.show', $w)">{{ __('Manage') }}</x-ui.button></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.client>
