<x-layouts.client :title="__('Overview')">
    <div class="page-head"><div><h1>{{ __('Hello :name', ['name' => auth()->user()->name]) }}</h1><p class="sub">{{ $customer->displayName() }}@if ($customer->package) · {{ __('Package :name', ['name' => $customer->package->name]) }}@endif</p></div></div>
    @includeIf('dns-mail::partials.dashboard-notice', ['customer' => $customer])
    <section class="grid grid-stats">
        <div class="card stat"><span class="label">{{ __('Websites') }}</span><strong class="value">{{ $customer->websites->count() }}@if ($customer->package)<span class="muted" style="font-size:16px"> / {{ $customer->package->max_websites }}</span>@endif</strong></div>
        <div class="card stat"><span class="label">{{ __('Disk space') }}</span><strong class="value" style="font-size:24px">{{ \Illuminate\Support\Number::fileSize($customer->diskUsedMb() * 1024 * 1024) }}</strong>
            @if ($customer->package)<span class="hint">{{ __('of :size', ['size' => \Illuminate\Support\Number::fileSize($customer->package->disk_mb * 1024 * 1024)]) }}</span>
            <x-ui.meter label="" :percent="$customer->package->disk_mb ? $customer->diskUsedMb() / $customer->package->disk_mb * 100 : 0" />@endif</div>
        <div class="card stat"><span class="label">{{ __('Certificates') }}</span><strong class="value">{{ $customer->websites->where('ssl_status', 'active')->count() }}</strong><span class="hint">{{ __('Let\'s Encrypt, renewed automatically') }}</span></div>
    </section>
    <x-ui.card :title="__('Your websites')" flush>
        <x-slot:actions><a class="small" href="{{ route('client.websites.index') }}">{{ __('All websites') }}</a></x-slot:actions>
        @include('client.websites.table', ['websites' => $customer->websites])
    </x-ui.card>
</x-layouts.client>
