@php $editing = $customer->exists; @endphp
<x-layouts.admin :title="$editing ? __('Edit customer') : __('New customer')">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.customers.index') }}">{{ __('Customers') }}</a>@if ($editing) / <a href="{{ route('admin.customers.show', $customer) }}">{{ $customer->displayName() }}</a>@endif</div>
            <h1>{{ $editing ? __('Edit customer') : __('New customer') }}</h1>
        </div>
    </div>
    <form method="POST" action="{{ $editing ? route('admin.customers.update', $customer) : route('admin.customers.store') }}" class="form">
        @csrf @if ($editing) @method('PUT') @endif
        <x-ui.card :title="__('Contact')">
            <div class="form-grid">
                <x-ui.field name="name" :label="__('Name')" :value="$customer->name" required />
                <x-ui.field name="company" :label="__('Company')" :value="$customer->company" />
                <x-ui.field name="email" type="email" :label="__('E-mail address')" :value="$customer->email" required />
                <x-ui.field name="phone" :label="__('Phone')" :value="$customer->phone" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Hosting')">
            <div class="form-grid">
                <x-ui.field name="status" type="select" :label="__('Status')" :help="__('A suspended customer cannot log in.')">
                    <option value="active" @selected(old('status', $customer->status) === 'active')>{{ __('Active') }}</option>
                    <option value="suspended" @selected(old('status', $customer->status) === 'suspended')>{{ __('Suspended') }}</option>
                </x-ui.field>
                <x-ui.field name="locale" type="select" :label="__('Language')">
                    @foreach ($locales as $code => $name)<option value="{{ $code }}" @selected(old('locale', $customer->locale) === $code)>{{ $name }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="crm_id" :label="__('CRM ID')" :value="$customer->crm_id" :help="__('Filled in automatically when the customer comes from the CRM.')" />
            </div>
            <x-ui.field name="notes" type="textarea" :label="__('Notes')" :value="$customer->notes" />
        </x-ui.card>
        <div class="actions">
            <button type="submit" class="btn btn-primary">{{ $editing ? __('Save') : __('Create customer') }}</button>
            <a class="btn" href="{{ $editing ? route('admin.customers.show', $customer) : route('admin.customers.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
