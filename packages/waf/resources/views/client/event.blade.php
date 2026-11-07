<x-layouts.client :title="__('Firewall event')">
    @include('waf::partials.styles')
    <div class="page-head"><div>
        <div class="crumbs"><a href="{{ route('client.waf.index') }}">{{ __('Web firewall') }}</a> / <a href="{{ route('client.waf.show', $website) }}">{{ $website->domain }}</a></div>
        <h1>{{ $event->blocked ? __('Blocked request') : __('Detected request') }}</h1>
    </div></div>
    @include('waf::partials.alerts')
    @include('waf::partials.event-detail', [
        'event' => $event,
        'allowUrl' => $canChange ? route('client.waf.events.allow', [$website, $event]) : null,
        'scopes' => ['page' => __('Only on :path', ['path' => \Illuminate\Support\Str::limit($event->path(), 40)]), 'site' => __('Whole website')],
    ])
</x-layouts.client>
