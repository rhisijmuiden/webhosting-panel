{{-- Shared shell for the admin and client panel: dark menu on the left, top bar, content. --}}
@php
    $user = auth()->user();
    $sections = $menu->sections();
    $current = $menu->active();
    $versions = app(\App\Services\VersionService::class);
    $channel = $versions->channel();
    $isAdmin = $user->isAdmin();
    $paletteItems = collect($sections)->flatMap(fn ($items, $section) => collect($items)->map(fn ($i) => [
        'label' => __($i['label']),
        'section' => $section !== '' ? __($section) : '',
        'url' => route($i['route']),
    ]))->values();
@endphp
<div class="app" x-data="{ nav: false }" @keydown.escape.window="nav = false">
    <aside class="sidebar" :class="{ open: nav }" id="sidebar">
        <a class="brand" href="{{ route('home') }}">
            <img src="{{ asset('brand/rhis-logo-wit.svg') }}" alt="{{ config('app.name') }}">
            <span>{{ $product }}</span>
        </a>

        @if ($isAdmin)
            <div class="server-card">
                <span class="status-dot" aria-hidden="true"></span>
                <span class="server-card-text">
                    <strong>{{ gethostname() ?: 'localhost' }}</strong>
                    <span>{{ $channel === 'test' ? __('Test server') : __('Production server') }}</span>
                </span>
            </div>
        @endif

        <nav class="nav" aria-label="{{ __('Main menu') }}">
            @foreach ($sections as $section => $items)
                @if ($section !== '')<div class="nav-section">{{ __($section) }}</div>@endif
                @foreach ($items as $item)
                    <a href="{{ route($item['route']) }}" @class(['active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" /><span class="nav-label">{{ __($item['label']) }}</span>
                        @if ($item['badge'])<span class="badge">{{ __($item['badge']) }}</span>@endif
                    </a>
                @endforeach
            @endforeach
        </nav>

        <div class="sidebar-foot" x-data="infoDialog(@js([
            'channel' => $channel,
            'infoUrl' => route('info'),
            'checkUrl' => $isAdmin ? route('admin.system.check') : null,
            'updateUrl' => $isAdmin ? route('admin.system.update') : null,
            'channelUrl' => $isAdmin ? route('admin.system.channel') : null,
            'jobUrl' => $isAdmin ? route('admin.system.job', '__JOB__') : null,
        ]))">
            @isset($sidebarFoot){{ $sidebarFoot }}@endisset
            <div class="info-card">
                <button type="button" class="info-card-head" @click="open()">
                    <x-icon name="info" />
                    <span><strong>{{ __('Info') }}</strong><span>v{{ $versions->version() }} · {{ __($channel) }}</span></span>
                </button>
                @if ($isAdmin)
                    <button type="button" class="info-card-btn" @click="open().then(() => checkUpdates())"><x-icon name="refresh" />{{ __('Check for updates') }}</button>
                @endif
            </div>
            @include('partials.info-dialog', ['isAdmin' => $isAdmin])
        </div>
    </aside>
    <div class="backdrop" :class="{ open: nav }" @click="nav = false"></div>

    <div class="main">
        <header class="topbar" x-data="palette(@js($paletteItems))" @keydown.window.prevent.ctrl.k="show()" @keydown.window.prevent.meta.k="show()">
            <button type="button" class="btn icon-btn menu-toggle" @click="nav = !nav" :aria-expanded="nav" aria-controls="sidebar" aria-label="{{ __('Menu') }}"><x-icon name="menu" /></button>
            <nav class="topbar-crumbs" aria-label="{{ __('Breadcrumbs') }}">
                @if ($current && $current['section_label'] !== '')
                    <span>{{ __($current['section_label']) }}</span><x-icon name="chevron" />
                @endif
                <strong>{{ $current ? __($current['label']) : ($title ?? '') }}</strong>
            </nav>
            <button type="button" class="palette-btn" @click="show()">
                <x-icon name="search" /><span>{{ __('Go to…') }}</span><kbd>⌘K</kbd>
            </button>
            @isset($topbar){{ $topbar }}@endisset
            @if ($isAdmin)
                <span @class(['channel-pill', 'is-test' => $channel === 'test']) title="{{ __('Release channel') }}"><span class="dot"></span>{{ $channel === 'test' ? __('Test') : __('Production') }}</span>
            @endif
            @include('partials.locale-switch')
            <button type="button" class="btn icon-btn" @click="$store.theme.set($store.theme.isDark() ? 'light' : 'dark')" :aria-label="$store.theme.isDark() ? @js(__('Light')) : @js(__('Dark'))">
                <x-icon name="moon" x-show="!$store.theme.isDark()" /><x-icon name="sun" x-show="$store.theme.isDark()" x-cloak />
            </button>
            <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" class="btn btn-ghost user-btn" @click="open = !open" :aria-expanded="open" aria-haspopup="true">
                    <span class="avatar" aria-hidden="true">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                    <span class="user-btn-text"><strong>{{ $user->name }}</strong><span>{{ $isAdmin ? __('Administrator') : ($user->customer?->displayName() ?? '') }}</span></span>
                </button>
                <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity>
                    <a href="{{ route('profile.edit') }}"><x-icon name="user" />{{ __('Profile') }}</a>
                    <a href="{{ route('profile.security') }}"><x-icon name="lock" />{{ __('Security') }}</a>
                    <div class="sep"></div>
                    <button type="button" @click="$store.theme.set('light')"><x-icon name="sun" />{{ __('Light') }}</button>
                    <button type="button" @click="$store.theme.set('dark')"><x-icon name="moon" />{{ __('Dark') }}</button>
                    <button type="button" @click="$store.theme.set('system')"><x-icon name="settings" />{{ __('Follow system') }}</button>
                    <div class="sep"></div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><x-icon name="logout" />{{ __('Log out') }}</button></form>
                </div>
            </div>

            <dialog class="palette" x-ref="palette" @close="query = ''" @click.self="$refs.palette.close()" aria-label="{{ __('Go to…') }}">
                <div class="palette-search">
                    <x-icon name="search" />
                    <input type="text" x-ref="input" x-model="query" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go()"
                           placeholder="{{ __('Search pages…') }}" aria-label="{{ __('Search pages…') }}" autocomplete="off">
                    <kbd>Esc</kbd>
                </div>
                <ul class="palette-list" role="listbox">
                    <template x-for="(item, i) in results()" :key="item.url">
                        <li role="option" :aria-selected="i === cursor">
                            <a :href="item.url" :class="{ on: i === cursor }" @mouseenter="cursor = i">
                                <span x-text="item.label"></span><small x-text="item.section"></small>
                            </a>
                        </li>
                    </template>
                    <li class="palette-empty" x-show="results().length === 0">{{ __('Nothing found') }}</li>
                </ul>
            </dialog>
        </header>
        <main class="content" id="content">
            @include('partials.flash')
            {{ $slot }}
        </main>
    </div>
</div>
