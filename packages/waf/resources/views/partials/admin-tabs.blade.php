<nav class="waf-tabs" aria-label="{{ __('Web application firewall') }}">
    <a href="{{ route('admin.waf.index') }}" @if (request()->routeIs('admin.waf.index', 'admin.waf.event')) aria-current="page" @endif><x-icon name="activity" />{{ __('Overview') }}</a>
    <a href="{{ route('admin.waf.sites') }}" @if (request()->routeIs('admin.waf.sites', 'admin.waf.site')) aria-current="page" @endif><x-icon name="globe" />{{ __('Websites') }}</a>
    <a href="{{ route('admin.waf.settings') }}" @if (request()->routeIs('admin.waf.settings')) aria-current="page" @endif><x-icon name="settings" />{{ __('Settings') }}</a>
</nav>
