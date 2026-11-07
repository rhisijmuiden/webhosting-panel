{{-- Included by the Security center (packages/vulnerabilities) inside its bento grid. --}}
@if ($waf)
<div class="card span-12">
    @include('waf::partials.styles')
    <div class="card-head"><h2>{{ __('Web application firewall') }}</h2><x-ui.button size="sm" :href="route('admin.waf.index')">{{ __('View events') }}</x-ui.button></div>
    <div class="kpi-row">
        <div><span>{{ __('Attacks blocked, 24 h') }}</span><strong>{{ number_format($waf['blocked_24h']) }}</strong></div>
        <div><span>{{ __('Detected only, 24 h') }}</span><strong>{{ number_format($waf['detected_24h']) }}</strong></div>
        <div><span>{{ __('Websites blocking') }}</span><strong>{{ $waf['modes']['block'] }}<small class="muted"> / {{ array_sum($waf['modes']) }}</small></strong></div>
        <div><span>{{ __('Most attacked, 7 days') }}</span><strong style="font-size:16px">@if ($waf['top_sites'])<a href="{{ route('admin.waf.site', $waf['top_sites'][0]['id']) }}">{{ $waf['top_sites'][0]['domain'] }}</a> <small class="muted">({{ number_format($waf['top_sites'][0]['hits']) }})</small>@else – @endif</strong></div>
    </div>
    @if ($waf['modes']['detect'] + $waf['modes']['off'] > 0)
        <p class="muted small">{{ __(':detect websites only detect and :off have the firewall off.', ['detect' => $waf['modes']['detect'], 'off' => $waf['modes']['off']]) }} <a href="{{ route('admin.waf.sites') }}">{{ __('Manage') }}</a></p>
    @endif
</div>
@endif
