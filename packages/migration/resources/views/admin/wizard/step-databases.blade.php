@if ($current['applicable'])
<x-ui.card :title="__('Databases')">
    <table class="table">
        <thead><tr><th>{{ __('Database') }}</th><th>{{ __('Users') }}</th><th>{{ __('Status') }}</th><th>{{ __('New password') }}</th></tr></thead>
        <tbody>
        @foreach ($item->source_data['databases'] ?? [] as $db)
            @php $res = collect($item->result['databases'] ?? [])->firstWhere('name', $db['name']); @endphp
            <tr>
                <td class="mono">{{ $db['name'] }}</td>
                <td class="small">{{ implode(', ', $db['users'] ?? []) ?: '–' }}</td>
                <td>@if ($res)<x-ui.pill :status="($res['ok'] ?? false) ? 'ok' : 'bad'">{{ ($res['ok'] ?? false) ? __('Copied') : __('Failed') }}</x-ui.pill>@else<x-ui.pill status="neutral">{{ __('Not copied yet') }}</x-ui.pill>@endif</td>
                <td class="mono small" style="word-break:break-all">@foreach ($db['users'] ?? [] as $u)@if (isset($secrets['db:'.$u])){{ $u }}: {{ $secrets['db:'.$u] }}<br>@endif @endforeach</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="small muted">{{ __('Users that got a new password: WordPress gets it in wp-config.php automatically; for other sites put it in their configuration.') }}</p>
</x-ui.card>
@endif
