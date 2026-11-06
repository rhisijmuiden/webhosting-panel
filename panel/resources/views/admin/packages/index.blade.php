<x-layouts.admin :title="__('Service plans')">
    <div class="plans-page">
        <div class="page-head">
            <div>
                <h1>{{ __('Service plans') }}</h1>
                <p class="sub">{{ __('Hosting packages with the limits per customer. Prices and invoices live in the CRM.') }}</p>
            </div>
            <div class="actions">
                <x-ui.button variant="primary" icon="plus" :href="route('admin.packages.create')">{{ __('Add plan') }}</x-ui.button>
            </div>
        </div>

        <nav class="plan-tabs" aria-label="{{ __('Filter') }}">
            @foreach (['all' => 'All plans', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label)
                <a href="{{ route('admin.packages.index', array_filter(['tab' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}" @class(['on' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ __($label) }} <span class="muted">{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>

        <div class="list-toolbar">
            <span class="muted small">{{ trans_choice(':count item in total|:count items in total', $packages->count()) }}</span>
            <form class="list-filters" method="GET" role="search">
                @if ($tab !== 'all')<input type="hidden" name="tab" value="{{ $tab }}">@endif
                <label class="search"><x-icon name="search" /><input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Plan name') }}" aria-label="{{ __('Search') }}"></label>
            </form>
        </div>

        <x-ui.card flush>
            @if ($packages->isEmpty())
                <x-ui.empty icon="package">{{ $search ? __('No plan matches your search.') : __('No packages yet.') }}</x-ui.empty>
            @else
            <x-ui.table>
                <x-slot:head>
                    <th>{{ __('Plan name') }}</th>
                    <th class="num">{{ __('Disk space') }}</th>
                    <th class="num">{{ __('Data traffic') }}</th>
                    <th class="num">{{ __('Websites') }}</th>
                    <th class="num">{{ __('Mailboxes') }}</th>
                    <th>{{ __('Resources') }}</th>
                    <th>{{ __('Source') }}</th>
                    <th class="num">{{ __('Customers') }}</th>
                    <th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th>
                </x-slot:head>
                @foreach ($packages as $p)
                    <tr>
                        <td>
                            <div class="plan-name">
                                <span><a class="strong" href="{{ route('admin.packages.edit', $p) }}">{{ $p->name }}</a>
                                    @unless ($p->is_active)<x-ui.pill status="neutral">{{ __('Inactive') }}</x-ui.pill>@endunless</span>
                                @if ($p->description)<small>{{ \Illuminate\Support\Str::limit($p->description, 80) }}</small>@endif
                            </div>
                        </td>
                        <td class="num">{{ $p->limitLabel('disk_mb') }}</td>
                        <td class="num">{{ $p->limitLabel('traffic_gb_month') }}</td>
                        <td class="num">{{ $p->limitLabel('max_websites') }}</td>
                        <td class="num">{{ $p->limitLabel('max_mailboxes') }}</td>
                        <td>{{ $p->hasResourceCaps() ? collect(['cpu_percent', 'memory_mb'])->filter(fn ($k) => $p->{$k} !== null)->map(fn ($k) => $p->limitLabel($k))->implode(' · ') ?: __('Capped') : __('Fair share') }}</td>
                        <td>@if ($p->crm_id)<span class="mono small" title="{{ __('Linked to the CRM') }}">CRM {{ $p->crm_id }}</span>@else<span class="muted">{{ __('Panel') }}</span>@endif</td>
                        <td class="num"><a href="{{ route('admin.customers.index', ['package' => $p->id]) }}">{{ $p->customers_count }}</a></td>
                        <td class="actions-col">
                            <div class="quick-actions">
                                <a href="{{ route('admin.packages.edit', $p) }}" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }} {{ $p->name }}"><x-icon name="edit" /></a>
                                <form method="POST" action="{{ route('admin.packages.duplicate', $p) }}">@csrf
                                    <button type="submit" class="icon-link" title="{{ __('Clone') }}" aria-label="{{ __('Clone') }} {{ $p->name }}"><x-icon name="apps" /></button>
                                </form>
                                @if ($p->customers_count === 0)
                                    <form method="POST" action="{{ route('admin.packages.destroy', $p) }}" onsubmit="return confirm(@js(__('Delete plan :name?', ['name' => $p->name])))">@csrf @method('DELETE')
                                        <button type="submit" class="icon-link is-danger" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }} {{ $p->name }}"><x-icon name="trash" /></button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</x-layouts.admin>
