{{-- Shared shell for the admin and client panel: menu on the left, top bar, content. --}}
@php
    $user = auth()->user();
    $sections = $menu->sections();
    $versions = app(\App\Services\VersionService::class);
@endphp
<div class="app" x-data="{ nav: false }" @keydown.escape.window="nav = false">
    <aside class="sidebar" :class="{ open: nav }" id="sidebar">
        <a class="brand" href="{{ route('home') }}">
            <img class="logo-light" src="{{ asset('brand/rhis-logo.svg') }}" alt="{{ config('app.name') }}">
            <img class="logo-dark" src="{{ asset('brand/rhis-logo-wit.svg') }}" alt="" aria-hidden="true">
            <span>{{ $product }}</span>
        </a>
        <nav class="nav" aria-label="{{ __('Main menu') }}">
            @foreach ($sections as $section => $items)
                @if ($section !== '')<div class="nav-section">{{ __($section) }}</div>@endif
                @foreach ($items as $item)
                    <a href="{{ route($item['route']) }}" @class(['active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" />{{ __($item['label']) }}
                        @if ($item['badge'])<span class="pill pill-neutral badge">{{ __($item['badge']) }}</span>@endif
                    </a>
                @endforeach
            @endforeach
        </nav>

        <div class="sidebar-foot" x-data="infoDialog(@js([
            'channel' => $versions->channel(),
            'infoUrl' => route('info'),
            'checkUrl' => $user->isAdmin() ? route('admin.system.check') : null,
            'updateUrl' => $user->isAdmin() ? route('admin.system.update') : null,
            'channelUrl' => $user->isAdmin() ? route('admin.system.channel') : null,
            'jobUrl' => $user->isAdmin() ? route('admin.system.job', '__JOB__') : null,
        ]))">
            @isset($sidebarFoot){{ $sidebarFoot }}@endisset
            <button type="button" class="info-btn" @click="open()">
                <span><strong>{{ __('Info') }}</strong><span class="muted">v{{ $versions->version() }} · {{ __($versions->channel()) }}</span></span>
                <x-icon name="info" />
            </button>
            @include('partials.info-dialog', ['isAdmin' => $user->isAdmin()])
        </div>
    </aside>
    <div class="backdrop" :class="{ open: nav }" @click="nav = false"></div>

    <div class="main">
        <header class="topbar">
            <button type="button" class="btn icon-btn menu-toggle" @click="nav = !nav" :aria-expanded="nav" aria-controls="sidebar" aria-label="{{ __('Menu') }}"><x-icon name="menu" /></button>
            @isset($topbar){{ $topbar }}@endisset
            @include('partials.locale-switch')
            <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" class="btn btn-ghost" @click="open = !open" :aria-expanded="open" aria-haspopup="true">
                    <span class="avatar" aria-hidden="true">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                    <span class="small">{{ $user->name }}</span>
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
        </header>
        <main class="content" id="content">
            @include('partials.flash')
            {{ $slot }}
        </main>
    </div>
</div>
