{{-- Debug log viewer of one WordPress install (admin and client). --}}
@php
    $r = fn (string $name, array $q = []) => route($area.'.wordpress.switches.'.$name, [$install] + $q);
    $counts = $log['counts'] ?? [];
    $levels = ['all' => __('All'), 'error' => __('Errors'), 'warning' => __('Warnings'), 'notice' => __('Notices'), 'deprecated' => __('Deprecated'), 'other' => __('Other')];
    $kb = fn (int $b) => $b >= 1048576 ? number_format($b / 1048576, 1).' MB' : max(1, (int) ceil($b / 1024)).' KB';
    $query = array_filter(['level' => $filters['level'] !== 'all' ? $filters['level'] : null, 'q' => $filters['q'] ?: null]);
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Debug log').' · '.$install->label()">
<link rel="stylesheet" href="{{ asset('assets/wordpress-switches/switches.css') }}?v={{ @filemtime(public_path('assets/wordpress-switches/switches.css')) }}">
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / <a href="{{ route($area.'.wordpress.show', $install) }}">{{ $install->label() }}</a> / {{ __('Debug log') }}</div>
        <div class="actions"><h1>{{ __('Debug log') }}</h1>
            <x-ui.pill :status="$state->debug ? 'info' : 'neutral'">{{ $state->debug ? __('Debug mode on') : __('Debug mode off') }}</x-ui.pill>
        </div>
        <p class="sub">
            @if ($log['exists'] ?? false){{ $kb((int) $log['size']) }} · {{ __('last entry :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($log['modified'])->diffForHumans()]) }}@endif
            @if ($state->debug && $state->debug_until) · {{ __('debug mode switches off :time', ['time' => $state->debug_until->diffForHumans()]) }}@endif
        </p>
    </div>
    <div class="actions">
        @if ($log['exists'] ?? false)
            <form method="POST" action="{{ $r('log.clear') }}" onsubmit="return confirm(@js(__('Empty the debug log?')))">@csrf
                <x-ui.button icon="trash">{{ __('Empty log') }}</x-ui.button></form>
        @endif
        <form method="POST" action="{{ $r('debug') }}">@csrf @method('PUT')<input type="hidden" name="enabled" value="{{ $state->debug ? 0 : 1 }}">
            <x-ui.button :variant="$state->debug ? null : 'dark'" icon="code">{{ $state->debug ? __('Switch debug mode off') : __('Switch debug mode on') }}</x-ui.button></form>
    </div>
</div>

<p class="muted small">{{ __('Shows the PHP error log of the website (visits) together with the WordPress debug log (WP-cron and WP-CLI). Both are private: they are not in the website folder.') }}</p>
@foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
@unless ($state->debug)
    <div class="alert alert-info" role="status"><x-icon name="info" /><div>{{ __('Debug mode is off, so nothing new is written to this log. Switch it on, reproduce the problem and come back here.') }}</div></div>
@endunless

<x-ui.card flush>
    <div class="wps-filter" x-data="{ live: false, timer: null, busy: false,
            toggle() { this.live = !this.live; this.live ? this.tick() : clearTimeout(this.timer) },
            async tick() {
                if (!this.live) return;
                if (!document.hidden && !this.busy) {
                    this.busy = true;
                    try { const r = await fetch(@js($r('log.entries', $query)), { headers: { Accept: 'application/json' } });
                          if (r.ok) document.getElementById('wps-entries').innerHTML = (await r.json()).html; } catch (e) {}
                    this.busy = false;
                }
                this.timer = setTimeout(() => this.tick(), 5000);
            } }">
        <nav class="seg" aria-label="{{ __('Level') }}">
            @foreach ($levels as $key => $name)
                @php $n = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0); @endphp
                @if ($key === 'all' || $n > 0 || $filters['level'] === $key)
                <a href="{{ $r('log', array_filter(['level' => $key !== 'all' ? $key : null, 'q' => $filters['q'] ?: null])) }}" @class(['seg-link', 'is-active' => $filters['level'] === $key]) @if ($filters['level'] === $key) aria-current="page" @endif>
                    {{ $name }} <span class="muted">{{ $n }}</span></a>
                @endif
            @endforeach
        </nav>
        <form method="GET" action="{{ $r('log') }}" class="wps-search" role="search">
            @if ($filters['level'] !== 'all')<input type="hidden" name="level" value="{{ $filters['level'] }}">@endif
            <label class="sr-only" for="wps-q">{{ __('Search the log') }}</label>
            <input id="wps-q" class="input" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('Search, e.g. a plugin name') }}">
            <button class="btn btn-sm" type="submit"><x-icon name="search" />{{ __('Search') }}</button>
        </form>
        <button type="button" class="btn btn-sm" x-on:click="toggle()" x-bind:aria-pressed="live">
            <span class="live" x-show="live" x-cloak aria-hidden="true"></span>
            <span x-text="live ? @js(__('Refreshing every 5 s')) : @js(__('Refresh automatically'))">{{ __('Refresh automatically') }}</span></button>
    </div>
    <div id="wps-entries" class="wps-entries-wrap" aria-live="polite">
        @include('wordpress::switches.entries', ['log' => $log ?? []])
    </div>
</x-ui.card>
</x-dynamic-component>
