<x-layouts.admin :title="__('Firewall event')">
    @include('waf::partials.styles')
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.waf.index') }}">{{ __('Web application firewall') }}</a> /
                @if ($event->website)<a href="{{ route('admin.waf.site', $event->website) }}">{{ $event->website->domain }}</a>@else{{ $event->domain }}@endif</div>
            <h1>{{ $event->blocked ? __('Blocked request') : __('Detected request') }}</h1>
            <p class="sub">{{ $event->website?->customer?->displayName() }}</p>
        </div>
    </div>
    @include('waf::partials.alerts')
    @include('waf::partials.event-detail', [
        'event' => $event,
        'allowUrl' => route('admin.waf.events.allow', $event),
        'scopes' => array_filter([
            'page' => $event->website ? __('Only on :path', ['path' => \Illuminate\Support\Str::limit($event->path(), 40)]) : null,
            'site' => $event->website ? __('Whole website') : null,
            'server' => __('All websites'),
        ]),
    ])
</x-layouts.admin>
