<x-dynamic-component :component="'layouts.'.$area" :title="__('Change domain')">
    @include('wordpress::search-replace.head', ['title' => __('Change domain'), 'sub' => $install->label(), 'step' => 1])

    @if ($addresses)
        @php
            $hosts = collect($addresses['hosts'] ?? []);
            $pick = old('host', $hosts->first(fn ($h) => $h['host'] !== $currentHost)['host'] ?? null);
        @endphp
        @if ($addresses['multisite'] ?? false)
            <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('This is a WordPress multisite network. Changing the domain of a network is not supported here; ask support for help.') }}</div>
        @else
        <x-ui.card :title="__('Current address')">
            <p><a href="{{ $addresses['home'] }}" target="_blank" rel="noopener" class="strong wsr-break">{{ $addresses['home'] ?: '?' }}</a>
                @if ($currentScheme === 'http')<x-ui.pill status="warn">{{ __('No https') }}</x-ui.pill>@endif</p>
            @if (($addresses['siteurl'] ?? '') !== '' && rtrim($addresses['siteurl'], '/') !== rtrim($addresses['home'], '/'))
                <p class="small muted">{{ __('WordPress files are at :url', ['url' => $addresses['siteurl']]) }}</p>
            @endif
            @foreach (array_filter($addresses['constants'] ?? []) as $name => $value)
                <p class="small muted"><span class="mono">{{ $name }}</span> {{ __('is set in wp-config.php and is changed too.') }}</p>
            @endforeach
        </x-ui.card>

        <x-ui.card :title="__('New address')">
            <form method="POST" action="{{ route($area.'.wordpress.sr.domain-preview', $install) }}" class="form"
                x-data="{ host: @js($pick), hosts: @js($hosts->keyBy('host')), scheme: @js(old('scheme', 'https')) }"
                x-effect="if (scheme === 'https' && hosts[host] && !hosts[host].ssl) scheme = 'http'">
                @csrf
                <p class="muted small">{{ __('Choose one of the addresses this website answers on. Every link in the database moves along: http and https, with and without www, also inside page builder and plugin data.') }}</p>
                <div class="wsr-hosts" role="radiogroup" aria-label="{{ __('New address') }}">
                    @foreach ($hosts as $h)
                        <label class="wsr-host">
                            <input type="radio" name="host" value="{{ $h['host'] }}" x-model="host" @checked($pick === $h['host'])>
                            <span class="wsr-host-name">{{ $h['host'] }}</span>
                            @if ($h['host'] === $currentHost)<x-ui.pill status="neutral">{{ __('Current') }}</x-ui.pill>@endif
                            <x-ui.pill :status="$h['ssl'] ? 'ok' : 'warn'">{{ $h['ssl'] ? __('SSL active') : __('No SSL yet') }}</x-ui.pill>
                        </label>
                    @endforeach
                </div>
                @if ($aliasUrl)
                    <p class="small muted">{{ __('Is the new domain not in the list? First add it to this website as a domain alias.') }} <a href="{{ $aliasUrl }}">{{ __('Domain aliases') }}</a></p>
                @endif
                <div class="seg" role="radiogroup" aria-label="{{ __('Protocol') }}">
                    <label><input type="radio" name="scheme" value="https" x-model="scheme" x-bind:disabled="hosts[host] && !hosts[host].ssl"><span>https:// ({{ __('recommended') }})</span></label>
                    <label><input type="radio" name="scheme" value="http" x-model="scheme"><span>http://</span></label>
                </div>
                <p class="small muted" x-show="hosts[host] && !hosts[host].ssl" x-cloak>{{ __('This address has no SSL certificate yet, so https is not possible. Point the DNS to this server first; the certificate follows automatically.') }}</p>
                <div class="wsr-change">
                    <div><small>{{ __('Now') }}</small><span class="mono">{{ $addresses['home'] }}</span></div>
                    <span class="wsr-arrow" aria-hidden="true">→</span>
                    <div><small>{{ __('New') }}</small><span class="mono" x-text="scheme + '://' + host + @js((string) parse_url((string) $addresses['home'], PHP_URL_PATH))"></span></div>
                </div>
                <div class="actions"><x-ui.button variant="primary" icon="search">{{ __('Show preview') }}</x-ui.button>
                    <span class="small muted">{{ __('Nothing changes yet.') }}</span></div>
            </form>
        </x-ui.card>
        @endif
    @endif

    @include('wordpress::search-replace.history')
</x-dynamic-component>
