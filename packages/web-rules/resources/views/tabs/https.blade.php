@php $https = $status['https_active'] ?? in_array($website->ssl_status, ['active', 'imported', 'custom', 'wildcard'], true); @endphp
<div class="wr-intro">
    <h2>{{ __('HTTPS & HSTS') }}</h2>
    <p class="muted small">{{ __('HTTPS encrypts the connection. HSTS tells browsers to always use HTTPS for this website, even when someone types http://.') }}</p>
</div>
@unless ($https)
    <div class="alert alert-warn" role="alert"><x-icon name="info" />{{ __('This website has no active certificate yet. These settings take effect as soon as the certificate is issued.') }}</div>
@endunless
<form method="POST" action="{{ $r('https.update') }}" class="form" x-data="{ hsts: @js((bool) old('hsts_enabled', $rules->hsts_enabled)), sub: @js((bool) old('hsts_subdomains', $rules->hsts_subdomains)) }">
    @csrf @method('PUT')
    <input type="hidden" name="force_https" value="0">
    <label class="check"><input type="checkbox" name="force_https" value="1" @checked(old('force_https', $rules->force_https))><span>{{ __('Force HTTPS') }}<small>{{ __('Visitors on http:// are redirected to https:// (recommended). Switch off only for old devices that cannot use HTTPS.') }}</small></span></label>

    <input type="hidden" name="hsts_enabled" value="0">
    <label class="check"><input type="checkbox" name="hsts_enabled" value="1" x-model="hsts" @checked(old('hsts_enabled', $rules->hsts_enabled))><span>{{ __('Send HSTS header') }}<small>{{ __('Strict-Transport-Security. Only sent over HTTPS.') }}</small></span></label>
    <div class="wr-hsts" x-show="hsts">
        <x-ui.field name="hsts_max_age" type="select" :label="__('Remember for')">
            @foreach (\Rhis\WebRules\WebRules::HSTS_AGES as $sec => $label)
                <option value="{{ $sec }}" @selected((int) old('hsts_max_age', $rules->hsts_max_age) === $sec)>{{ __($label) }}</option>
            @endforeach
        </x-ui.field>
        <input type="hidden" name="hsts_subdomains" value="0">
        <label class="check"><input type="checkbox" name="hsts_subdomains" value="1" x-model="sub" @checked(old('hsts_subdomains', $rules->hsts_subdomains))><span>{{ __('Include subdomains') }}<small>{{ __('Only when every subdomain of :domain works over HTTPS.', ['domain' => $website->domain]) }}</small></span></label>
        <input type="hidden" name="hsts_preload" value="0">
        <label class="check"><input type="checkbox" name="hsts_preload" value="1" @checked(old('hsts_preload', $rules->hsts_preload))><span>{{ __('Preload') }}<small>{{ __('Allows submitting the domain to hstspreload.org, so browsers use HTTPS from the very first visit. Hard to undo: needs "include subdomains" and at least one year.') }}</small></span></label>
        @error('hsts_preload')<span class="error">{{ $message }}</span>@enderror
    </div>
    <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
</form>
