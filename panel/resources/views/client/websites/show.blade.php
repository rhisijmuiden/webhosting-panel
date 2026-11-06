<x-layouts.client :title="$website->domain">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('client.websites.index') }}">{{ __('Websites') }}</a> / {{ $website->domain }}</div>
            <div class="actions"><h1>{{ $website->domain }}</h1>
                <x-ui.pill :status="$website->status">{{ $website->status === 'active' ? __('Online') : __(ucfirst($website->status)) }}</x-ui.pill>
                <x-ui.pill :status="$website->ssl_status">{{ $website->ssl_status === 'active' ? __('SSL valid') : __('SSL :status', ['status' => __($website->ssl_status)]) }}</x-ui.pill></div>
        </div>
        <a class="btn btn-primary" href="https://{{ $website->domain }}" target="_blank" rel="noopener">{{ __('Visit website') }}<x-icon name="external" /></a>
    </div>
    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('PHP version')">
            <form method="POST" action="{{ route('client.websites.update', $website) }}" class="form">
                @csrf @method('PUT')
                <p class="muted small">{{ __('Only versions that still get security updates are offered. Switching takes a few seconds, without downtime.') }}</p>
                <div class="seg" role="radiogroup" aria-label="{{ __('PHP version') }}">
                    @foreach (\App\Models\Website::PHP_VERSIONS as $v)
                        <label><input type="radio" name="php_version" value="{{ $v }}" @checked($website->php_version === $v)><span>PHP {{ $v }}</span></label>
                    @endforeach
                </div>
                <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Speed & caching')">
            <form method="POST" action="{{ route('client.websites.update', $website) }}" class="form">
                @csrf @method('PUT')
                <p class="muted small">{{ __('Pages are served straight from the server cache. Logged-in visitors and shopping carts are skipped automatically.') }}</p>
                <input type="hidden" name="page_cache" value="0">
                <label class="check"><input type="checkbox" name="page_cache" value="1" @checked($website->page_cache)><span>{{ __('Page cache') }}</span></label>
                <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Details')">
            <dl class="kv">
                <dt>{{ __('Type') }}</dt><dd>{{ __(ucfirst($website->profile)) }}</dd>
                <dt>{{ __('Also reachable as') }}</dt><dd>{{ $website->aliases ? implode(', ', $website->aliases) : '–' }}</dd>
                <dt>{{ __('Disk') }}</dt><dd>{{ $website->disk_used_mb }} MB</dd>
                <dt>{{ __('SFTP user') }}</dt><dd class="mono">{{ $website->system_user ?? '–' }}</dd>
            </dl>
        </x-ui.card>
    </section>
    @includeIf('wordpress::partials.website-card', ['website' => $website])
    @includeIf('domain-aliases::partials.website-card', ['website' => $website])
</x-layouts.client>
