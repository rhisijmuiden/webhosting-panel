<x-dynamic-component :component="'layouts.'.$area" :title="__('SSL certificates')">
    @include('certificates::partials.styles')
    <div class="page-head">
        <div>
            <h1>{{ __('SSL certificates') }}</h1>
            <p class="sub">{{ __('Every website gets a free Let\'s Encrypt certificate that renews by itself. Use a wildcard for all subdomains at once, or upload a certificate you bought yourself.') }}</p>
        </div>
        @if ($checkedAt)<div class="actions"><span class="muted small">{{ __('Checked :time', ['time' => $checkedAt->diffForHumans()]) }}</span></div>@endif
    </div>

    <x-ui.card>
        <div class="kpi-row cert-kpis">
            <div><span>{{ __('Websites') }}</span><strong>{{ $stats['total'] }}</strong></div>
            <div><span>{{ __('Secured') }}</span><strong>{{ $stats['secure'] }}</strong></div>
            <div><span>{{ __('Expiring soon') }}</span><strong @class(['is-warn' => $stats['soon'] > 0])>{{ $stats['soon'] }}</strong></div>
            <div><span>{{ __('Expired') }}</span><strong @class(['is-bad' => $stats['expired'] > 0])>{{ $stats['expired'] }}</strong></div>
            <div><span>{{ __('Own / wildcard') }}</span><strong>{{ $stats['custom'] }} / {{ $stats['wildcard'] }}</strong></div>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Certificates per website')" flush>
        <div style="padding: 16px 20px 0">
            <nav class="cert-filter" aria-label="{{ __('Filter') }}">
                @foreach (['' => __('All'), 'attention' => __('Needs attention'), 'letsencrypt' => __('Let\'s Encrypt'), 'wildcard' => __('Wildcard'), 'custom' => __('Own certificate')] as $k => $label)
                    <a href="{{ route($area.'.certificates.index', $k === '' ? [] : ['filter' => $k]) }}" @class(['is-on' => $filter === $k])>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        @if ($rows->isEmpty())
            <x-ui.empty icon="lock">{{ $filter ? __('No websites match this filter.') : __('No websites yet.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <x-slot:head>
                    <th>{{ __('Website') }}</th><th>{{ __('Type') }}</th><th>{{ __('Valid for') }}</th><th>{{ __('Valid until') }}</th><th>{{ __('Status') }}</th>
                    <th class="num"><span class="sr-only">{{ __('Actions') }}</span></th>
                </x-slot:head>
                @foreach ($rows as $r)
                    @php $w = $r['website']; $c = $r['cert']; $health = $c ? $c->health() : ($w->ssl_status === 'active' ? 'ok' : 'warn'); @endphp
                    <tr>
                        <td><a class="strong" href="{{ route($area.'.certificates.show', $w) }}">{{ $w->domain }}</a>
                            @if ($area === 'admin' && $w->customer)<div class="muted small">{{ $w->customer->displayName() }}</div>@endif</td>
                        <td class="small">{{ $c ? \Rhis\Certificates\Present::source($c) : ($w->ssl_status === 'active' ? __('Let\'s Encrypt') : __('No certificate')) }}</td>
                        <td class="small">@if ($c?->names)<span class="mono">{{ implode(', ', array_slice($c->names, 0, 3)) }}</span>@if (count($c->names) > 3) <span class="muted">+{{ count($c->names) - 3 }}</span>@endif @else <span class="muted">–</span>@endif</td>
                        <td class="small">@include('certificates::partials.days', ['cert' => $c])</td>
                        <td>
                            @if ($c?->isBusy())<x-ui.pill status="pending">{{ __('Requesting…') }}</x-ui.pill>
                            @elseif ($c?->status === 'failed')<x-ui.pill status="failed">{{ __('Request failed') }}</x-ui.pill>
                            @else<x-ui.pill :status="$health">{{ match ($health) { 'ok' => __('Valid'), 'warn' => $c && $c->source !== 'none' ? __('Expires soon') : __('Pending'), default => $c?->isExpired() ? __('Expired') : __('Expires soon') } }}</x-ui.pill>@endif
                        </td>
                        <td class="num"><a class="btn btn-sm" href="{{ route($area.'.certificates.show', $w) }}">{{ __('Manage') }}</a></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</x-dynamic-component>
