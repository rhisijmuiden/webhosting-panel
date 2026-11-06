<x-layouts.admin :title="__('Activity log')">
    <div class="page-head"><div><h1>{{ __('Activity log') }}</h1><p class="sub">{{ __('Who changed what, and when.') }}</p></div></div>
    <x-ui.card flush>
        @if ($logs->isEmpty())
            <x-ui.empty icon="list">{{ __('Nothing yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('When') }}</th><th>{{ __('Who') }}</th><th>{{ __('What') }}</th><th>{{ __('Subject') }}</th><th>IP</th></x-slot:head>
            @foreach ($logs as $log)
                <tr>
                    <td class="small" title="{{ $log->created_at }}">{{ $log->created_at->isoFormat('lll') }}</td>
                    <td>{{ $log->user?->name ?? __('System') }}</td>
                    <td>{{ $log->label() }}</td>
                    <td>{{ $log->subject ?? '–' }}</td>
                    <td class="mono small">{{ $log->ip ?? '–' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    {{ $logs->links('partials.pagination') }}
</x-layouts.admin>
