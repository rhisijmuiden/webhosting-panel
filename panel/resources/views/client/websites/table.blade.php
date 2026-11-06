@if ($websites->isEmpty())
    <x-ui.empty icon="globe">{{ __('You have no websites yet. Contact us to add one.') }}</x-ui.empty>
@else
<x-ui.table>
    <x-slot:head><th>{{ __('Domain') }}</th><th>PHP</th><th>SSL</th><th>{{ __('Status') }}</th></x-slot:head>
    @foreach ($websites as $w)
        <tr>
            <td><a class="strong" href="{{ route('client.websites.show', $w) }}">{{ $w->domain }}</a>
                @if ($w->parent_id)<div class="muted small">{{ __('Subdomain of :domain', ['domain' => $w->parent?->domain]) }}</div>@endif</td>
            <td>{{ $w->php_version }}</td>
            <td><x-ui.pill :status="$w->ssl_status">{{ $w->ssl_status === 'active' ? __('Secure') : __(ucfirst($w->ssl_status)) }}</x-ui.pill></td>
            <td><x-ui.pill :status="$w->status">{{ $w->status === 'active' ? __('Online') : __(ucfirst($w->status)) }}</x-ui.pill></td>
        </tr>
    @endforeach
</x-ui.table>
@endif
