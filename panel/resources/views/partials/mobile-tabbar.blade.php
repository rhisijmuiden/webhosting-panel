{{-- Bottom tab bar on a phone: the most used pages in reach of the thumb, the full menu behind "Menu". --}}
@php
    $byKey = collect($sections)->flatten(1)->keyBy('key');
    $keys = $isAdmin ? ['dashboard', 'customers', 'quick', 'websites'] : ['overview', 'websites', 'mail', 'backups'];
    $tabs = collect($keys)->map(fn ($k) => $byKey->get($k))->filter()->values();
    $shortLabels = ['dashboard' => 'Home', 'overview' => 'Home', 'quick' => 'Quick', 'mail' => 'Mail'];
@endphp
<nav class="tabbar" aria-label="{{ __('Quick menu') }}">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}" @class(['tab', 'tab-main' => $tab['key'] === 'quick', 'active' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif>
            <span class="tab-icon"><x-icon :name="$tab['icon']" /></span>
            <span class="tab-label">{{ __($shortLabels[$tab['key']] ?? $tab['label']) }}</span>
        </a>
    @endforeach
    <button type="button" class="tab" @click="nav = true" :aria-expanded="nav" aria-controls="sidebar">
        <span class="tab-icon"><x-icon name="menu" /></span>
        <span class="tab-label">{{ __('Menu') }}</span>
    </button>
</nav>
