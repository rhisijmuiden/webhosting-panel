<x-layouts.admin :title="__('Packages')">
    <div class="page-head">
        <div><h1>{{ __('Packages') }}</h1><p class="sub">{{ __('Limits per customer. Prices and invoices live in the CRM.') }}</p></div>
        <x-ui.button variant="primary" icon="plus" :href="route('admin.packages.create')">{{ __('New package') }}</x-ui.button>
    </div>
    @if ($packages->isEmpty())
        <x-ui.card><x-ui.empty icon="package">{{ __('No packages yet.') }}</x-ui.empty></x-ui.card>
    @else
    <section class="grid grid-3">
        @foreach ($packages as $p)
            <x-ui.card :title="$p->name">
                <x-slot:actions>@unless ($p->is_active)<x-ui.pill status="neutral">{{ __('Inactive') }}</x-ui.pill>@endunless</x-slot:actions>
                @if ($p->description)<p class="muted small">{{ $p->description }}</p>@endif
                <div>
                    <div class="row"><span>{{ __('Disk space') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($p->disk_mb * 1024 * 1024) }}</strong></div>
                    <div class="row"><span>{{ __('Websites') }}</span><strong>{{ $p->max_websites }}</strong></div>
                    <div class="row"><span>{{ __('Mailboxes') }}</span><strong>{{ $p->max_mailboxes }}</strong></div>
                    <div class="row"><span>{{ __('Databases') }}</span><strong>{{ $p->max_databases }}</strong></div>
                    <div class="row"><span>{{ __('Cron jobs') }}</span><strong>{{ $p->max_cronjobs }}</strong></div>
                    <div class="row"><span>{{ __('SSH access') }}</span><strong>{{ $p->allow_ssh ? __('Yes') : __('No') }}</strong></div>
                    <div class="row"><span>{{ __('Customers') }}</span><strong>{{ $p->customers_count }}</strong></div>
                </div>
                <div class="actions">
                    <x-ui.button size="sm" icon="edit" :href="route('admin.packages.edit', $p)">{{ __('Edit') }}</x-ui.button>
                    @if ($p->crm_id)<span class="small muted mono">CRM {{ $p->crm_id }}</span>@endif
                </div>
            </x-ui.card>
        @endforeach
    </section>
    @endif
</x-layouts.admin>
