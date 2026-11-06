<x-dynamic-component :component="'layouts.'.$area" :title="__('Domain aliases')">
    @if ($aliases->contains->isBusy())<meta http-equiv="refresh" content="5">@endif
    <div class="page-head">
        <div>
            <h1>{{ __('Domain aliases') }}</h1>
            <p class="sub">{{ __('Extra domains that show the same website or redirect to it. Need a separate website on one of them? Make it a website of its own.') }}</p>
        </div>
    </div>
    @include('domain-aliases::partials.messages')

    <x-ui.card :title="__('All aliases')" flush>
        @if ($aliases->isEmpty())
            <x-ui.empty icon="globe">{{ __('No domain aliases yet.') }}</x-ui.empty>
        @else
            @include('domain-aliases::partials.table', ['aliases' => $aliases, 'area' => $area, 'showWebsite' => true])
        @endif
    </x-ui.card>

    @if ($websites->isNotEmpty() && ($area === 'admin' || config('domain-aliases.customer_manage')))
        <x-ui.card :title="__('Add alias')">
            @include('domain-aliases::partials.add-form', ['area' => $area, 'websites' => $websites, 'manager' => $manager])
        </x-ui.card>
    @endif
</x-dynamic-component>
