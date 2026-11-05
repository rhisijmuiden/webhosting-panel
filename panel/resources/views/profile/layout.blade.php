@php $layout = auth()->user()->isAdmin() ? 'layouts.admin' : 'layouts.client'; @endphp
<x-dynamic-component :component="$layout" :title="$title">
    <div class="page-head">
        <div><h1>{{ $title }}</h1></div>
        <div class="actions">
            <x-ui.button :href="route('profile.edit')" :variant="request()->routeIs('profile.edit') ? 'dark' : null" icon="user">{{ __('Profile') }}</x-ui.button>
            <x-ui.button :href="route('profile.security')" :variant="request()->routeIs('profile.security') ? 'dark' : null" icon="lock">{{ __('Security') }}</x-ui.button>
        </div>
    </div>
    {{ $slot }}
</x-dynamic-component>
