@php
    $geo = $status['geoip'] ?? ['available' => false];
    $networks = collect($rules->access_networks ?? [])->map(fn ($n) => trim($n['address'].($n['comment'] ? '  # '.$n['comment'] : '')))->implode("\n");
    $chosen = old('countries', $rules->countries ?? []);
    $modes = ['off' => __('Off'), 'deny' => __('Block these'), 'allow' => __('Only these')];
@endphp
<div class="wr-intro">
    <h2>{{ __('Block visitors') }}</h2>
    <p class="muted small">{{ __('Block addresses or countries that should not reach this website, or allow only your own office. Blocked visitors get a 403 page. Certificates keep renewing.') }}</p>
</div>
<form method="POST" action="{{ $r('access.update') }}" class="form" x-data="{ mode: @js(old('access_mode', $rules->access_mode)), cmode: @js(old('countries_mode', $rules->countries_mode)) }">
    @csrf @method('PUT')
    <x-ui.card :title="__('IP addresses')" class="wr-sub">
        <div class="seg" role="radiogroup" aria-label="{{ __('IP addresses') }}">
            @foreach ($modes as $k => $label)
                <label><input type="radio" name="access_mode" value="{{ $k }}" x-model="mode" @checked(old('access_mode', $rules->access_mode) === $k)><span>{{ $label }}</span></label>
            @endforeach
        </div>
        <div x-show="mode !== 'off'">
            <x-ui.field name="networks" type="textarea" :label="__('Addresses, one per line')" :value="old('networks', $networks)" rows="6" class="mono" placeholder="203.0.113.10  # {{ __('office') }}&#10;198.51.100.0/24" :help="__('An IP address or a range like 198.51.100.0/24. Text after # is a note.')" />
            <p class="muted small">{{ __('Your current address is :ip.', ['ip' => $ip]) }}</p>
            <p class="muted small" x-show="mode === 'allow'">{{ __('With "only these" everybody else is blocked. The server itself can always reach the website.') }}</p>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Countries')" class="wr-sub">
        @if (! ($geo['available'] ?? false))
            <div class="alert alert-info" role="status"><x-icon name="info" />{{ __('Country blocking is not available on this server yet: the country database is missing. It is downloaded during the next update.') }}</div>
        @endif
        <div class="seg" role="radiogroup" aria-label="{{ __('Countries') }}">
            @foreach ($modes as $k => $label)
                <label><input type="radio" name="countries_mode" value="{{ $k }}" x-model="cmode" @checked(old('countries_mode', $rules->countries_mode) === $k) @disabled($k !== 'off' && ! ($geo['available'] ?? false))><span>{{ $label }}</span></label>
            @endforeach
        </div>
        <div x-show="cmode !== 'off'">
            <x-ui.field name="countries[]" type="select" :label="__('Countries')" multiple size="10" class="wr-countries">
                @foreach (\Rhis\WebRules\WebRules::countries() as $code => $name)
                    <option value="{{ $code }}" @selected(in_array($code, $chosen, true))>{{ $name }} ({{ $code }})</option>
                @endforeach
            </x-ui.field>
            @error('countries')<span class="error">{{ $message }}</span>@enderror
            <p class="muted small">{{ __('Hold Ctrl (Cmd on a Mac) to choose more than one. Visitors whose country is unknown are never blocked. Behind a proxy like Cloudflare the country of the proxy counts; use the blocking of the proxy there.') }}</p>
        </div>
        <p class="muted small">{{ __('Country data:') }} <a href="https://db-ip.com" rel="noopener" target="_blank">IP Geolocation by DB-IP</a> (CC BY 4.0)@if (! empty($geo['updated_at'])), {{ __('updated :date', ['date' => \Illuminate\Support\Carbon::createFromTimestamp($geo['updated_at'])->isoFormat('LL')]) }}@endif.</p>
    </x-ui.card>
    <div><button type="submit" class="btn btn-dark">{{ __('Save access rules') }}</button></div>
</form>
