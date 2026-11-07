{{-- Shown on a website's page (admin and client): its domain aliases. --}}
@php
    $daArea = request()->routeIs('admin.*') ? 'admin' : 'client';
    $daManager = app(\Rhis\DomainAliases\AliasManager::class);
    $daAliases = \Rhis\DomainAliases\Models\DomainAlias::with('website.customer')->where('website_id', $website->id)->orderBy('domain')->get();
    $daLimit = $daManager->limit($website);
    $daCanAdd = $website->status === 'active' && ($daArea === 'admin' || (config('domain-aliases.customer_manage') && $daManager->canAdd($website)));
@endphp
<x-ui.card :title="__('Domain aliases')" id="domain-aliases">
    <x-slot:actions>
        <span class="muted small">{{ $daLimit === null ? trans_choice(':count alias|:count aliases', $daAliases->count()) : __(':used of :limit used', ['used' => $daAliases->count(), 'limit' => $daLimit]) }}</span>
    </x-slot:actions>
    @if ($daAliases->contains->isBusy())<meta http-equiv="refresh" content="5">@endif
    <p class="muted small">{{ __('Extra domains for this website, for example your .be or .com next to the .nl. Each alias gets its own certificate and, with :provider DNS, its DNS records.', ['provider' => \App\Support\Brand::provider()]) }}</p>
    @include('domain-aliases::partials.messages')
    @if ($daAliases->isNotEmpty())
        @include('domain-aliases::partials.table', ['aliases' => $daAliases, 'area' => $daArea, 'showWebsite' => false])
    @endif
    @if ($daCanAdd)
        <details class="alias-details" @if ($errors->has('domain') || $daAliases->isEmpty()) open @endif>
            <summary class="btn btn-sm"><x-icon name="plus" />{{ __('Add alias') }}</summary>
            @include('domain-aliases::partials.add-form', ['area' => $daArea, 'websites' => collect([$website]), 'manager' => $daManager])
        </details>
    @elseif ($daArea === 'client' && $daLimit !== null && $daAliases->count() >= $daLimit)
        <p class="small muted">{{ __('Your package allows :count domain aliases for this website. Ask us for a larger package if you need more.', ['count' => $daLimit]) }}</p>
    @endif
</x-ui.card>
