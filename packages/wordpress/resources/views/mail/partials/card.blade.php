{{-- Website mail on the page of one WordPress install (admin and client). --}}
@php
    $wpmSetting = \Rhis\WordPress\Mail\WpMailSetting::where('wordpress_install_id', $install->id)->first();
    $wpmUrl = \Illuminate\Support\Facades\Route::has($area.'.wordpress.mail.show') ? route($area.'.wordpress.mail.show', $install) : null;
@endphp
@if ($wpmUrl)
<x-ui.card :title="__('Website mail')">
    <x-slot:actions><a class="btn btn-sm" href="{{ $wpmUrl }}"><x-icon name="settings" />{{ __('Manage') }}</a></x-slot:actions>
    <div class="actions">
        @if ($wpmSetting?->enabled)
            <x-ui.pill status="ok">{{ __('Through this server') }}</x-ui.pill><span class="small mono">{{ $wpmSetting->setRelation('install', $install)->sender() }}</span>
        @elseif ($wpmSetting?->reason === 'staging')
            <x-ui.pill status="warn">{{ __('Held back (staging)') }}</x-ui.pill>
        @else
            <x-ui.pill status="neutral">{{ __('Not through this server') }}</x-ui.pill>
        @endif
        @if ($wpmSetting?->last_test_at)<span class="small muted">{{ __('Last test') }} {{ $wpmSetting->last_test_at->diffForHumans() }}: {{ $wpmSetting->last_test_ok ? __('Sent') : __('Failed') }}</span>@endif
    </div>
    <p class="muted small">{{ __('Contact forms and web shop mail sent through this server, signed with DKIM. Send a test message to check it.') }}</p>
</x-ui.card>
@endif
