{{-- Card on the WordPress install page (packages/wordpress partials/show): hardening summary + link. --}}
@php
    $whRoute = $area.'.wp-hardening.show';
    $whOn = \Illuminate\Support\Facades\Route::has($whRoute) && ($area === 'admin' || config('wp-hardening.customer_access'));
    $wh = $whOn ? \App\Models\WpHardeningState::where('wordpress_install_id', $install->id)->first() : null;
@endphp
@if ($whOn)
    <link rel="stylesheet" href="{{ asset('assets/wp-hardening.css') }}?v={{ @filemtime(public_path('assets/wp-hardening.css')) }}">
    <section class="card wph-card">
        <span class="wph-icon" aria-hidden="true"><x-icon name="shield" /></span>
        <div class="wph-text">
            <h2>{{ __('Security hardening') }}
                @if ($wh)<x-ui.pill :status="$wh->isComplete() ? 'ok' : 'warn'">{{ __(':on of :total on', ['on' => $wh->on_count, 'total' => $wh->total]) }}</x-ui.pill>@endif
            </h2>
            <p class="muted small">{{ __('Turn off the file editor, block PHP in uploads, protect wp-config.php, hide the version and user names, renew the security keys. One click, and every measure can be undone.') }}</p>
        </div>
        <a class="btn {{ $wh && $wh->isComplete() ? '' : 'btn-dark' }}" href="{{ route($whRoute, $install) }}"><x-icon name="shield" />{{ $wh && $wh->isComplete() ? __('View measures') : __('Harden this site') }}</a>
    </section>
@endif
