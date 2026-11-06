<x-layouts.admin :title="__('Websites')">
    <div class="page-head">
        <div><h1>{{ __('Websites') }}</h1><p class="sub">{{ trans_choice(':count website|:count websites', $websites->total()) }}</p></div>
        <div class="actions">
            <form class="search" method="GET" role="search"><x-icon name="search" /><input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Domain') }}" aria-label="{{ __('Search') }}"></form>
            <x-ui.button variant="primary" icon="plus" :href="route('admin.websites.create')">{{ __('New website') }}</x-ui.button>
        </div>
    </div>
    <x-ui.card flush>
        @if ($websites->isEmpty())
            <x-ui.empty icon="globe">{{ __('No websites yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Domain') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Type') }}</th><th>PHP</th><th>{{ __('Cache') }}</th><th>SSL</th><th class="num">{{ __('Disk') }}</th><th>{{ __('Status') }}</th></x-slot:head>
            @foreach ($websites as $w)
                <tr>
                    <td><a class="strong" href="{{ route('admin.websites.show', $w) }}">{{ $w->domain }}</a></td>
                    <td><a href="{{ route('admin.customers.show', $w->customer) }}">{{ $w->customer->displayName() }}</a></td>
                    <td>{{ __(ucfirst($w->profile)) }}</td>
                    <td>{{ $w->php_version }}</td>
                    <td>{{ $w->page_cache ? __('On') : __('Off') }}</td>
                    <td><x-ui.pill :status="$w->ssl_status">{{ __(ucfirst($w->ssl_status)) }}</x-ui.pill></td>
                    <td class="num">{{ $w->disk_used_mb }} MB</td>
                    <td><x-ui.pill :status="$w->status">{{ __(ucfirst($w->status)) }}</x-ui.pill></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    {{ $websites->links('partials.pagination') }}
</x-layouts.admin>
