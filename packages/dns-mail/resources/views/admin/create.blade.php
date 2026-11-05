<x-layouts.admin :title="__('Add domain')">
    <div class="page-head"><div>
        <div class="crumbs"><a href="{{ route('admin.mail.domains.index') }}">{{ __('Domains & mail') }}</a></div>
        <h1>{{ __('Add domain') }}</h1>
        <p class="sub">{{ __('For a domain with mail or DNS but no website here. Website domains are added automatically.') }}</p>
    </div></div>
    <form method="POST" action="{{ route('admin.mail.domains.store') }}" class="form" style="max-width:640px">
        @csrf
        <x-ui.card>
            <x-ui.field name="customer_id" type="select" :label="__('Customer')" required>
                @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->displayName() }}</option>@endforeach
            </x-ui.field>
            <x-ui.field name="domain" :label="__('Domain')" placeholder="example.com" required />
            <div class="field">
                <span class="label">{{ __('Mail for this domain') }}</span>
                @include('dns-mail::partials.provider-choice', ['current' => old('provider', 'rhis')])
            </div>
        </x-ui.card>
        <div><button type="submit" class="btn btn-primary">{{ __('Add domain') }}</button></div>
    </form>
</x-layouts.admin>
