<x-layouts.admin :title="__('Web application firewall').' · '.$website->domain">
    @include('waf::partials.styles')
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.waf.sites') }}">{{ __('Web application firewall') }}</a> / {{ $website->domain }}</div>
            <h1>{{ $website->domain }}</h1>
            <p class="sub">@include('waf::partials.mode-pill', ['mode' => $mode]) · {{ __(':blocked blocked and :detected detected in the last 24 hours', ['blocked' => $summary['blocked_24h'], 'detected' => $summary['detected_24h']]) }}</p>
        </div>
    </div>
    @include('waf::partials.admin-tabs')
    @include('waf::partials.alerts')
    <x-ui.card :title="__('Mode')">
        @include('waf::partials.mode-form', ['action' => route('admin.waf.site.update', $website), 'current' => $row->mode, 'allowDefault' => true, 'defaultMode' => $defaultMode])
    </x-ui.card>
    @include('waf::partials.exceptions', [
        'exceptions' => $row->exceptions ?? [], 'canChange' => true,
        'storeUrl' => route('admin.waf.site.exceptions.store', $website),
        'destroyUrl' => fn ($i) => route('admin.waf.site.exceptions.destroy', [$website, $i]),
    ])
    <x-ui.card :title="__('Events')" flush>
        @include('waf::partials.events', ['events' => $events, 'showSite' => false, 'eventUrl' => fn ($e) => route('admin.waf.event', $e)])
    </x-ui.card>
</x-layouts.admin>
