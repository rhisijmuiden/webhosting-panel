<x-layouts.admin :title="__('Customers')">
    <div class="page-head">
        <div><h1>{{ __('Customers') }}</h1><p class="sub">{{ trans_choice(':count customer|:count customers', $customers->total()) }}</p></div>
        <div class="actions">
            <form class="search" method="GET" role="search"><x-icon name="search" /><input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Name, e-mail or domain') }}" aria-label="{{ __('Search') }}"></form>
            <x-ui.button variant="primary" icon="plus" :href="route('admin.customers.create')">{{ __('New customer') }}</x-ui.button>
        </div>
    </div>
    <x-ui.card flush>
        @if ($customers->isEmpty())
            <x-ui.empty icon="customers">{{ $search ? __('No customers match your search.') : __('No customers yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('E-mail') }}</th><th>{{ __('Package') }}</th><th class="num">{{ __('Websites') }}</th><th>CRM</th><th>{{ __('Status') }}</th></x-slot:head>
            @foreach ($customers as $c)
                <tr>
                    <td><a class="strong" href="{{ route('admin.customers.show', $c) }}">{{ $c->displayName() }}</a>@if ($c->company)<div class="small muted">{{ $c->name }}</div>@endif</td>
                    <td>{{ $c->email }}</td>
                    <td>{{ $c->package?->name ?? '–' }}</td>
                    <td class="num">{{ $c->websites_count }}</td>
                    <td class="mono small">{{ $c->crm_id ?? '–' }}</td>
                    <td><x-ui.pill :status="$c->status">{{ __(ucfirst($c->status)) }}</x-ui.pill></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    {{ $customers->links('partials.pagination') }}
</x-layouts.admin>
