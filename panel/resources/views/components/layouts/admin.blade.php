@props(['title' => null])
<x-layouts.base :title="$title">
    @include('partials.shell', ['menu' => \App\Support\Menu::admin(), 'product' => __(config('panel.brand.product')), 'slot' => $slot, 'topbar' => $topbar ?? null])
</x-layouts.base>
