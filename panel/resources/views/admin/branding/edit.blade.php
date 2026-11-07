<x-layouts.admin :title="__('Branding')">
    <div class="page-head"><div><h1>{{ __('Branding') }}</h1><p class="sub">{{ __('How your hosting company appears to customers in :app.', ['app' => \App\Support\Brand::name()]) }}</p></div></div>
    <form method="POST" action="{{ route('admin.branding.update') }}" class="form">
        @csrf @method('PUT')
        <x-ui.card :title="__('Hosting company')">
            <x-ui.field name="provider" :label="__('Company name')" :value="old('provider', $providerName)" required maxlength="40"
                :help="__('Shown to customers, for example in “Let :name run your DNS” and in the name of the mail service. Default: :default.', ['name' => $providerName, 'default' => $default])" />
        </x-ui.card>
        <x-ui.card :title="__('Product')">
            <div class="brand-preview" style="display:flex;align-items:center;gap:14px">
                <img src="{{ asset(\App\Support\Brand::icon()) }}" alt="" width="40" height="40">
                <div><strong>{{ \App\Support\Brand::name() }}</strong><p class="muted small" style="margin:2px 0 0">{{ __('The name and logo of the software. They are the same on every server.') }}</p></div>
            </div>
        </x-ui.card>
        <div><button type="submit" class="btn btn-primary">{{ __('Save') }}</button></div>
    </form>
</x-layouts.admin>
