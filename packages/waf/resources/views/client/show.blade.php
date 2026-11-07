<x-layouts.client :title="__('Web firewall').' · '.$website->domain">
    @include('waf::partials.styles')
    <div class="page-head"><div>
        <div class="crumbs"><a href="{{ route('client.waf.index') }}">{{ __('Web firewall') }}</a> / {{ $website->domain }}</div>
        <h1>{{ $website->domain }}</h1>
        <p class="sub">@include('waf::partials.mode-pill', ['mode' => $mode]) · {{ __(':blocked blocked and :detected detected in the last 24 hours', ['blocked' => $summary['blocked_24h'], 'detected' => $summary['detected_24h']]) }}</p>
    </div></div>
    @include('waf::partials.alerts')
    @if ($mode === 'detect')
        <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('This website is in detect-only mode: attacks are logged, but not stopped.') }}</div>
    @elseif ($mode === 'off')
        <div class="alert alert-bad" role="status"><x-icon name="info" />{{ __('The web firewall is off for this website. Attacks reach your website unchecked.') }}</div>
    @endif
    <x-ui.card :title="__('Mode')">
        @if ($canChange)
            @include('waf::partials.mode-form', ['action' => route('client.waf.update', $website), 'current' => $mode, 'allowDefault' => false, 'defaultMode' => $mode])
        @else
            <p>@include('waf::partials.mode-pill', ['mode' => $mode])</p>
            <p class="muted small">{{ __('Your hosting provider manages the firewall settings. Contact support if something on your website is blocked by mistake.') }}</p>
        @endif
    </x-ui.card>
    @include('waf::partials.exceptions', [
        'exceptions' => $row->exceptions ?? [], 'canChange' => $canChange,
        'storeUrl' => route('client.waf.exceptions.store', $website),
        'destroyUrl' => fn ($i) => route('client.waf.exceptions.destroy', [$website, $i]),
    ])
    <x-ui.card :title="__('What the firewall saw')" flush>
        @include('waf::partials.events', ['events' => $events, 'showSite' => false, 'eventUrl' => fn ($e) => route('client.waf.event', [$website, $e])])
    </x-ui.card>
</x-layouts.client>
