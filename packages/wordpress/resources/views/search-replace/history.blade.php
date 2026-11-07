@if ($history->isNotEmpty())
    <x-ui.card :title="__('Earlier runs')" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Change') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Replacements') }}</th><th>{{ __('When') }}</th></x-slot:head>
            @foreach ($history as $h)
                <tr>
                    <td class="wsr-break"><a href="{{ route($area.'.wordpress.sr.show', $h) }}">{{ $h->summary() }}</a></td>
                    <td>@include('wordpress::search-replace.status', ['run' => $h])</td>
                    <td class="num">{{ number_format((int) ($h->result['total'] ?? $h->preview['total'] ?? 0), 0, ',', '.') }}</td>
                    <td class="small">{{ $h->created_at->diffForHumans() }}@if ($h->user) · {{ $h->user->name }}@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
@endif
