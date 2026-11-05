<x-layouts.client :title="__('Websites')">
    <div class="page-head"><div><h1>{{ __('Websites') }}</h1></div></div>
    <x-ui.card flush>@include('client.websites.table')</x-ui.card>
</x-layouts.client>
