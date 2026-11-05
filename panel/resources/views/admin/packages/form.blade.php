@php $editing = $package->exists; @endphp
<x-layouts.admin :title="$editing ? __('Edit package') : __('New package')">
    <div class="page-head"><div><div class="crumbs"><a href="{{ route('admin.packages.index') }}">{{ __('Packages') }}</a></div><h1>{{ $editing ? $package->name : __('New package') }}</h1></div></div>
    <form method="POST" action="{{ $editing ? route('admin.packages.update', $package) : route('admin.packages.store') }}" class="form">
        @csrf @if ($editing) @method('PUT') @endif
        <x-ui.card>
            <div class="form-grid">
                <x-ui.field name="name" :label="__('Name')" :value="$package->name" required />
                <x-ui.field name="crm_id" :label="__('CRM ID')" :value="$package->crm_id" :help="__('Links this package to the product in the CRM.')" />
            </div>
            <x-ui.field name="description" type="textarea" :label="__('Description')" :value="$package->description" />
        </x-ui.card>
        <x-ui.card :title="__('Limits')">
            <div class="form-grid">
                <x-ui.field name="disk_mb" type="number" min="100" :label="__('Disk space (MB)')" :value="$package->disk_mb" required />
                <x-ui.field name="max_websites" type="number" min="0" :label="__('Websites')" :value="$package->max_websites" required />
                <x-ui.field name="max_mailboxes" type="number" min="0" :label="__('Mailboxes')" :value="$package->max_mailboxes" required />
                <x-ui.field name="max_databases" type="number" min="0" :label="__('Databases')" :value="$package->max_databases" required />
                <x-ui.field name="max_cronjobs" type="number" min="0" :label="__('Cron jobs')" :value="$package->max_cronjobs" required />
            </div>
            <label class="check"><input type="checkbox" name="allow_ssh" value="1" @checked(old('allow_ssh', $package->allow_ssh))><span>{{ __('SSH access') }}<small>{{ __('Customers can log in with SSH (SFTP is always available).') }}</small></span></label>
            <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->is_active))><span>{{ __('Active') }}<small>{{ __('Inactive packages cannot be given to new customers.') }}</small></span></label>
        </x-ui.card>
        <div class="actions">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.packages.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
    @if ($editing)
        <form method="POST" action="{{ route('admin.packages.destroy', $package) }}" data-confirm="{{ __('Delete this package?') }}">@csrf @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit"><x-icon name="trash" />{{ __('Delete package') }}</button></form>
    @endif
</x-layouts.admin>
