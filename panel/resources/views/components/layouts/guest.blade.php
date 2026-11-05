@props(['title' => null])
<x-layouts.base :title="$title">
<div class="auth">
    <div class="auth-side">
        <img src="{{ asset('brand/rhis-logo-wit.svg') }}" alt="{{ config('app.name') }}" style="height:30px;width:auto;align-self:flex-start">
        <div class="auth-tagline">
            <span class="pill">{{ __('Hosting panel') }}</span>
            <h1 style="margin-top:16px">{{ __('Your websites, safe and fast.') }}</h1>
            <p>{{ __('Manage websites, mail and backups in one place.') }}</p>
        </div>
        <span class="small" style="color:#9AA7C2">v{{ app(\App\Services\VersionService::class)->version() }}</span>
    </div>
    <div class="auth-form">
        <div class="auth-card">
            <div style="align-self:flex-end">@include('partials.locale-switch')</div>
            {{ $slot }}
        </div>
    </div>
</div>
</x-layouts.base>
