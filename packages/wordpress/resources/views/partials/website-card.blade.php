{{-- Shown on a website's page (admin and client) when it runs WordPress. --}}
@php
    $wpInstalls = \Rhis\WordPress\Models\WordPressInstall::where('website_id', $website->id)->orderBy('path')->get();
    $wpArea = request()->routeIs('admin.*') ? 'admin' : 'client';
@endphp
@if ($wpInstalls->isNotEmpty() && ($wpArea === 'admin' || config('wordpress.customer_toolkit')))
<x-ui.card :title="__('WordPress')">
    @foreach ($wpInstalls as $i)
        <div class="actions" style="justify-content:space-between;padding:6px 0">
            <div><a class="strong" href="{{ route($wpArea.'.wordpress.show', $i) }}">{{ $i->label() }}</a>
                <span class="muted small">WordPress {{ $i->version }}</span>
                @if ($i->isOutdated())<x-ui.pill status="warn">{{ trans_choice(':count update|:count updates', $i->outdatedCount()) }}</x-ui.pill>@else<x-ui.pill status="ok">{{ __('Up to date') }}</x-ui.pill>@endif</div>
            <form method="POST" action="{{ route($wpArea.'.wordpress.login', $i) }}" target="_blank">@csrf<button class="btn btn-sm btn-primary" type="submit"><x-icon name="external" />{{ __('WP admin') }}</button></form>
        </div>
    @endforeach
</x-ui.card>
@endif
