{{--
    The four quick switches of one install. Used by the card on the install page and the
    row on the domain page. Needs: $switches (SwitchState), $url (fn(name) => ?string),
    $area ('admin' | 'client'), $compact (bool).
--}}
@php
    $hours = config('wordpress-switches.debug_hours');
    $forever = $area === 'admin' && config('wordpress-switches.debug_forever_admin');
    $kb = fn (int $b) => $b >= 1048576 ? number_format($b / 1048576, 1).' MB' : max(1, (int) ceil($b / 1024)).' KB';
    $uid = 'wps-'.$switches->wordpress_install_id.'-'.($compact ? 'c' : 'f');
@endphp
@if ($switches->public_log_size !== null && $url('log.clear'))
    <div class="alert alert-warn" role="status"><x-icon name="info" />
        <div><strong>{{ __('There is a public debug.log') }}</strong>
            <p class="small">{{ __('wp-content/debug.log (:size) can be downloaded by anybody and may contain paths, queries or personal data.', ['size' => $kb($switches->public_log_size)]) }}</p>
            <form method="POST" action="{{ $url('log.clear') }}" onsubmit="return confirm(@js(__('Delete wp-content/debug.log?')))">@csrf<input type="hidden" name="which" value="public">
                <button class="btn btn-sm" type="submit"><x-icon name="trash" />{{ __('Delete it') }}</button></form>
        </div>
    </div>
@endif
<div @class(['wps-list', 'is-compact' => $compact])>
    {{-- Maintenance mode --}}
    @if ($url('maintenance'))
    <div @class(['wps-row', 'is-on' => $switches->maintenance, 'is-alert' => $switches->maintenance]) x-data="{ open: false }">
        <div class="wps-icon" aria-hidden="true"><x-icon name="tools" /></div>
        <div class="wps-text">
            <strong id="{{ $uid }}-m">{{ __('Maintenance mode') }}</strong>
            @if ($switches->maintenance)<x-ui.pill status="warn">{{ $switches->maintenance_since ? __('On for :time', ['time' => $switches->maintenance_since->diffForHumans(null, true)]) : __('On') }}</x-ui.pill>@endif
            @unless ($compact)<p class="muted small">{{ __('Visitors see a maintenance page. You keep seeing the website while you are logged in to WordPress.') }}</p>@endunless
        </div>
        <div class="wps-control">
            @if ($switches->maintenance)
                <form method="POST" action="{{ $url('maintenance') }}">@csrf @method('PUT')<input type="hidden" name="enabled" value="0">
                    <button type="submit" class="wps-toggle" role="switch" aria-checked="true" aria-labelledby="{{ $uid }}-m" title="{{ __('Switch off') }}"></button></form>
            @else
                <button type="button" class="wps-toggle" role="switch" aria-checked="false" aria-labelledby="{{ $uid }}-m" x-bind:aria-expanded="open" x-on:click="open = !open" title="{{ __('Switch on') }}"></button>
            @endif
        </div>
        @unless ($switches->maintenance)
        <form method="POST" action="{{ $url('maintenance') }}" class="form wps-more" x-show="open" x-cloak>
            @csrf @method('PUT')<input type="hidden" name="enabled" value="1">
            <div class="field"><label for="{{ $uid }}-mt">{{ __('Title') }}</label>
                <input id="{{ $uid }}-mt" name="title" class="input" maxlength="120" value="{{ $switches->maintenance_title ?: __('We will be back soon') }}"></div>
            <div class="field"><label for="{{ $uid }}-mm">{{ __('Message for visitors') }}</label>
                <textarea id="{{ $uid }}-mm" name="message" class="textarea" maxlength="1000" rows="3">{{ $switches->maintenance_message ?: __('This website is undergoing maintenance. Please try again in a little while.') }}</textarea></div>
            <p class="muted small">{{ __('Search engines get "503 Service Unavailable" with "try again later", so the website keeps its place in search results.') }}</p>
            <div class="actions"><button type="submit" class="btn btn-dark btn-sm">{{ __('Switch on maintenance mode') }}</button>
                <button type="button" class="btn btn-sm btn-ghost" x-on:click="open = false">{{ __('Cancel') }}</button></div>
        </form>
        @endunless
    </div>
    @endif

    {{-- Debug mode --}}
    @if ($url('debug'))
    <div @class(['wps-row', 'is-on' => $switches->debug]) x-data="{ open: false }">
        <div class="wps-icon" aria-hidden="true"><x-icon name="code" /></div>
        <div class="wps-text">
            <strong id="{{ $uid }}-d">{{ __('Debug mode') }}</strong>
            @if ($switches->debug)
                <x-ui.pill status="info">{{ $switches->debug_until ? __('Switches off :time', ['time' => $switches->debug_until->diffForHumans()]) : __('On') }}</x-ui.pill>
            @endif
            @unless ($compact)<p class="muted small">{{ __('Writes PHP errors and warnings to a private log. Visitors never see them.') }}</p>@endunless
            @if ($url('log') && ($switches->debug || $switches->log_size > 0))
                <p class="small"><a href="{{ $url('log') }}"><x-icon name="list" />{{ __('View debug.log') }}</a>
                    <span class="muted">{{ $switches->log_size > 0 ? $kb($switches->log_size) : __('empty') }}</span></p>
            @endif
            @if ($switches->debug && $switches->debug_log === 'public')
                <p class="small wps-warn">{{ __('This install writes its log to wp-content/debug.log. Switch debug mode off and on again to move it to the private log.') }}</p>
            @endif
        </div>
        <div class="wps-control">
            @if ($switches->debug)
                <form method="POST" action="{{ $url('debug') }}">@csrf @method('PUT')<input type="hidden" name="enabled" value="0">
                    <button type="submit" class="wps-toggle" role="switch" aria-checked="true" aria-labelledby="{{ $uid }}-d" title="{{ __('Switch off') }}"></button></form>
            @else
                <button type="button" class="wps-toggle" role="switch" aria-checked="false" aria-labelledby="{{ $uid }}-d" x-bind:aria-expanded="open" x-on:click="open = !open" title="{{ __('Switch on') }}"></button>
            @endif
        </div>
        @unless ($switches->debug)
        <form method="POST" action="{{ $url('debug') }}" class="form wps-more" x-show="open" x-cloak>
            @csrf @method('PUT')<input type="hidden" name="enabled" value="1">
            <div class="seg" role="radiogroup" aria-label="{{ __('Switch off automatically after') }}">
                @foreach (collect($hours)->sort()->values() as $h)
                    <label><input type="radio" name="hours" value="{{ $h }}" @checked($h === $hours[0])><span>{{ trans_choice(':count hour|:count hours', $h) }}</span></label>
                @endforeach
                @if ($forever)<label><input type="radio" name="hours" value="0"><span>{{ __('Until I switch it off') }}</span></label>@endif
            </div>
            <p class="muted small">{{ __('Debug mode slows WordPress down a little and the log can grow quickly, so it switches itself off.') }}</p>
            <div class="actions"><button type="submit" class="btn btn-dark btn-sm">{{ __('Switch on debug mode') }}</button>
                <button type="button" class="btn btn-sm btn-ghost" x-on:click="open = false">{{ __('Cancel') }}</button></div>
        </form>
        @endunless
    </div>
    @endif

    {{-- Search engine indexing --}}
    @if ($url('indexing'))
    <div @class(['wps-row', 'is-on' => $switches->indexing])>
        <div class="wps-icon" aria-hidden="true"><x-icon name="search" /></div>
        <div class="wps-text">
            <strong id="{{ $uid }}-i">{{ __('Search engines') }}</strong>
            @unless ($switches->indexing)<x-ui.pill status="warn">{{ __('Hidden from search engines') }}</x-ui.pill>@endunless
            @unless ($compact)<p class="muted small">{{ $switches->indexing
                ? __('Google, Bing and others may show this website in their results.')
                : __('Search engines are asked not to show this website. Handy while building, but do not forget it at launch.') }}</p>@endunless
        </div>
        <div class="wps-control">
            <form method="POST" action="{{ $url('indexing') }}" @if ($switches->indexing) onsubmit="return confirm(@js(__('Ask search engines not to index this website? It can disappear from Google.')))" @endif>
                @csrf @method('PUT')<input type="hidden" name="enabled" value="{{ $switches->indexing ? 0 : 1 }}">
                <button type="submit" class="wps-toggle" role="switch" aria-checked="{{ $switches->indexing ? 'true' : 'false' }}" aria-labelledby="{{ $uid }}-i"
                    title="{{ $switches->indexing ? __('Switch off') : __('Switch on') }}"></button>
            </form>
        </div>
    </div>
    @endif

    {{-- Caches --}}
    @if ($url('cache'))
    <div class="wps-row">
        <div class="wps-icon" aria-hidden="true"><x-icon name="bolt" /></div>
        <div class="wps-text">
            <strong>{{ __('Caches') }}</strong>
            @if ($switches->cache_cleared_at)<span class="muted small">{{ __('Emptied :time', ['time' => $switches->cache_cleared_at->diffForHumans()]) }}</span>@endif
            @unless ($compact)<p class="muted small">{{ __('Empties the page cache, the object cache, cache plugins (WP Rocket, W3 Total Cache, LiteSpeed and others) and expired transients.') }}</p>@endunless
        </div>
        <div class="wps-control">
            <form method="POST" action="{{ $url('cache') }}">@csrf
                <button type="submit" class="btn btn-sm"><x-icon name="refresh" />{{ __('Clear cache') }}</button></form>
        </div>
    </div>
    @endif
</div>
