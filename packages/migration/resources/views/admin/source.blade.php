@php
    $inv = $source->inventory;
    $running = $inventoryJob !== null || $items->contains(fn ($i) => $i->status === 'queued' || $i->isActive());
    $statusLabels = ['queued' => __('Queued'), 'preparing' => __('Preparing'), 'copying' => __('Copying'), 'copied' => __('Copied'),
        'syncing' => __('Syncing'), 'live' => __('DNS switched'), 'done' => __('Finished'), 'failed' => __('Failed')];
    $groups = collect($inv['domains'] ?? [])
        ->filter(fn ($d) => $search === '' || str_contains($d['name'], strtolower($search)))
        ->groupBy(fn ($d) => $d['subscription'] ?? $d['name']);
    $owners = collect($inv['owners'] ?? [])->keyBy('id');
@endphp
<x-layouts.admin :title="$source->name">
    @if ($inventoryJob)<meta http-equiv="refresh" content="5">@elseif ($running)
        {{-- Refresh for progress, but never while the admin is making a selection. --}}
        <script>setInterval(() => { if (!document.querySelector('form.mig-form input[name="domains[]"]:checked') && !window.migTouched) location.reload(); }, 10000);
            document.addEventListener('change', () => window.migTouched = true);</script>
    @endif
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.migration.index') }}">{{ __('Migration') }}</a></div>
            <div class="actions"><h1>{{ $source->name }}</h1>
                <x-ui.pill :status="['connected' => 'ok', 'error' => 'bad'][$source->status] ?? 'neutral'">{{ __(ucfirst($source->status)) }}</x-ui.pill></div>
            <p class="sub mono">{{ $source->user.'@'.$source->host }}:{{ $source->port }}@if ($source->version) · Plesk {{ $source->version }}@endif @if ($source->hostname) · {{ $source->hostname }}@endif</p>
        </div>
        <div class="actions">
            <form method="POST" action="{{ route('admin.migration.sources.test', $source) }}">@csrf<button class="btn" type="submit"><x-icon name="refresh" />{{ __('Test connection') }}</button></form>
            <form method="POST" action="{{ route('admin.migration.sources.inventory', $source) }}">@csrf<button class="btn btn-primary" type="submit" @disabled($inventoryJob || $source->status !== 'connected')><x-icon name="search" />{{ $inv ? __('Read again') : __('Read server') }}</button></form>
        </div>
    </div>

    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @error('domains')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @if ($source->last_error)<div class="alert alert-warn" role="alert"><x-icon name="info" /><div>{{ $source->last_error }}
        @if (str_contains($source->last_error, 'authorized_keys'))<p class="small">{{ __('Add the key shown on the migration page to the Plesk server first.') }} <a href="{{ route('admin.migration.index') }}">{{ __('Show key') }}</a></p>@endif
    </div></div>@endif
    @if ($source->host_key)<p class="small muted">{{ __('Host key') }}: <span class="mono">{{ $source->host_key }}</span></p>@endif

    @if ($inventoryJob)
        <x-ui.card :title="__('Reading the source server')">
            <p><x-ui.pill status="warn">{{ __('Running') }}</x-ui.pill> <span class="muted small">{{ __('This page refreshes by itself.') }}</span></p>
            @if (! empty($inventoryJob['log']))<div class="log">{{ implode("\n", $inventoryJob['log']) }}</div>@endif
        </x-ui.card>
    @endif

    @if ($inv)
        <section class="grid grid-stats">
            <div class="card stat"><span class="label">{{ __('Domains') }}</span><strong class="value">{{ $inv['totals']['domains'] }}</strong><span class="hint">{{ __(':count with a website', ['count' => $inv['totals']['sites']]) }}</span></div>
            <div class="card stat"><span class="label">{{ __('Databases') }}</span><strong class="value">{{ $inv['totals']['databases'] }}</strong></div>
            <div class="card stat"><span class="label">{{ __('Mailboxes') }}</span><strong class="value">{{ $inv['totals']['mailboxes'] }}</strong></div>
            <div class="card stat"><span class="label">{{ __('Disk') }}</span><strong class="value">{{ number_format($inv['totals']['size_mb'] / 1024, 1) }} GB</strong><span class="hint">{{ __('Read :time', ['time' => $source->inventoried_at?->diffForHumans()]) }}</span></div>
        </section>
        @foreach ($inv['warnings'] ?? [] as $w)<div class="alert alert-warn"><x-icon name="info" />{{ $w }}</div>@endforeach

        <div class="page-head" style="margin-top:8px">
            <div><h2>{{ __('Choose what to migrate') }}</h2></div>
            <div class="actions"><form class="search" method="GET" role="search"><x-icon name="search" /><input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Domain') }}" aria-label="{{ __('Search') }}"></form></div>
        </div>
        <form method="POST" action="{{ route('admin.migration.sources.migrate', $source) }}" class="mig-form" x-data="{ all: false }">
            @csrf
            <x-ui.card flush>
                <x-ui.table>
                    <x-slot:head>
                        <th style="width:34px"><input class="mig-sel" type="checkbox" aria-label="{{ __('Select all') }}" x-model="all" @change="$root.querySelectorAll('input[name=\'domains[]\']:not(:disabled)').forEach(c => c.checked = all)"></th>
                        <th>{{ __('Domain') }}</th><th>{{ __('Contents') }}</th><th>{{ __('Customer here') }}</th><th>{{ __('Migration') }}</th>
                    </x-slot:head>
                    @foreach ($groups as $subscription => $domains)
                        @php $owner = $owners->get($domains->first()['owner_id'] ?? null); @endphp
                        <tr class="mig-group"><td></td><td colspan="4">{{ $subscription }} <span class="muted">· {{ $owner && $owner['kind'] !== 'admin' ? ($owner['company'] ?: $owner['name']).' ('.$owner['email'].')' : __('Owned by the Plesk admin') }}</span></td></tr>
                        @foreach ($domains as $d)
                            @php $item = $items->get($d['name']); $key = \Rhis\Migration\Http\MigrationController::key($d['name']); $blocked = $item && $item->status !== 'failed'; @endphp
                            <tr @class(['mig-sub' => $d['name'] !== $subscription])>
                                <td><input class="mig-sel" type="checkbox" name="domains[]" value="{{ $d['name'] }}" aria-label="{{ $d['name'] }}" @disabled($blocked || ($d['kind'] !== 'site' && empty($d['mailboxes'])))></td>
                                <td>
                                    <span class="strong">{{ $d['name'] }}</span>
                                    @if (! $d['active'])<x-ui.pill status="neutral">{{ __('Suspended') }}</x-ui.pill>@endif
                                    @if ($d['kind'] === 'forward')<span class="small muted">{{ __('forwarding only') }}</span>@endif
                                    @if ($d['aliases'])<div class="small muted">{{ __('Aliases') }}: {{ collect($d['aliases'])->pluck('name')->implode(', ') }}</div>@endif
                                </td>
                                <td><div class="mig-tags">
                                    @if ($d['web'])<span>{{ ['wordpress' => 'WordPress', 'laravel' => 'Laravel', 'php' => 'PHP'][$d['web']['app'] ?? 'php'] ?? 'PHP' }}{{ $d['php'] ? ' · PHP '.$d['php'] : '' }}</span><span>{{ $d['size_mb'] }} MB</span>@endif
                                    @if ($d['databases'])<span>{{ trans_choice(':count database|:count databases', count($d['databases'])) }}</span>@endif
                                    @if ($d['mailboxes'])<span>{{ trans_choice(':count mailbox|:count mailboxes', count($d['mailboxes'])) }}</span>@endif
                                    @if ($d['ssl'])<span>SSL</span>@endif
                                    @if ($d['dns'])<span>{{ trans_choice(':count DNS record|:count DNS records', count($d['dns'])) }}</span>@endif
                                </div></td>
                                <td>
                                    @if ($item?->customer)
                                        <a href="{{ route('admin.customers.show', $item->customer) }}">{{ $item->customer->displayName() }}</a>
                                    @else
                                        <select class="select mig-cust" name="customer[{{ $key }}]" aria-label="{{ __('Customer for :domain', ['domain' => $d['name']]) }}" @disabled($blocked)>
                                            <option value="new">{{ $owner && $owner['kind'] !== 'admin' ? __('New or matching: :name', ['name' => $owner['company'] ?: $owner['name']]) : __('New customer: :name', ['name' => $subscription]) }}</option>
                                            @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->company ?: $c->name }}</option>@endforeach
                                        </select>
                                    @endif
                                </td>
                                <td>
                                    @if ($item)
                                        <a href="{{ route('admin.migration.items.show', $item) }}"><x-ui.pill :status="$item->statusPill()">{{ $statusLabels[$item->status] ?? $item->status }}</x-ui.pill></a>
                                    @else
                                        <span class="muted small">–</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </x-ui.table>
                <div class="mig-bar">
                    <div class="mig-what">
                        @foreach (['files' => __('Website files'), 'databases' => __('Databases'), 'mail' => __('Mailboxes and mail'), 'ssl' => __('Current certificate')] as $k => $label)
                            <input type="hidden" name="{{ $k }}" value="0">
                            <label class="check"><input type="checkbox" name="{{ $k }}" value="1" checked><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                    <button type="submit" class="btn btn-dark"><x-icon name="migrate" />{{ __('Migrate selected') }}</button>
                </div>
            </x-ui.card>
        </form>
        <p class="small muted">{{ __('DNS is not changed while copying. Switch it per domain on its migration page once you have checked the copy.') }}</p>

        @if ($items->where('status', 'copied')->isNotEmpty())
            <form method="POST" action="{{ route('admin.migration.sources.sync', $source) }}">@csrf
                <button class="btn" type="submit"><x-icon name="refresh" />{{ trans_choice('Sync :count copied domain again|Sync :count copied domains again', $items->where('status', 'copied')->count()) }}</button>
            </form>
        @endif
    @elseif (! $inventoryJob)
        <x-ui.card><x-ui.empty icon="search">{{ $source->status === 'connected' ? __('Read the server to see what can be migrated.') : __('Test the connection first.') }}</x-ui.empty></x-ui.card>
    @endif

    <x-ui.card :title="__('Source server')">
        <div class="actions">
            <form method="POST" action="{{ route('admin.migration.sources.forget', $source) }}">@csrf<button class="btn btn-ghost btn-sm" type="submit">{{ __('Reset host key') }}</button></form>
            <form method="POST" action="{{ route('admin.migration.sources.destroy', $source) }}" onsubmit="return confirm(@js(__('Remove this source server? Migrated websites stay.')))">@csrf @method('DELETE')<button class="btn btn-danger btn-sm" type="submit"><x-icon name="trash" />{{ __('Remove source') }}</button></form>
        </div>
    </x-ui.card>
    @include('migration::admin.styles')
</x-layouts.admin>
