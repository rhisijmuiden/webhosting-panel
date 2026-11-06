@props(['title' => null])
@php $customer = auth()->user()->customer; @endphp
<x-layouts.base :title="$title">
    @include('partials.shell', ['menu' => \App\Support\Menu::client(), 'product' => __('My hosting'), 'slot' => $slot, 'topbar' => $topbar ?? null,
        'sidebarFoot' => new \Illuminate\Support\HtmlString(view('partials.client-card', ['customer' => $customer])->render())])
</x-layouts.base>
