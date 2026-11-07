{{-- $events (paginator), $eventUrl (fn WafEvent => url), $showSite (bool) --}}
@if ($events->isEmpty())
    <x-ui.empty icon="firewall">{{ __('No attacks recorded. Requests that match a firewall rule show up here within five minutes.') }}</x-ui.empty>
@else
    <x-ui.table>
        <x-slot:head>
            <th>{{ __('When') }}</th>@if ($showSite)<th>{{ __('Website') }}</th>@endif<th>{{ __('Result') }}</th><th>{{ __('Attack') }}</th><th>{{ __('Request') }}</th><th>{{ __('From') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th>
        </x-slot:head>
        @foreach ($events as $e)
            <tr>
                <td class="small nowrap" title="{{ $e->occurred_at->isoFormat('LLL') }}">{{ $e->occurred_at->diffForHumans() }}</td>
                @if ($showSite)<td class="small">{{ $e->website?->domain ?? $e->domain }}</td>@endif
                <td>@if ($e->blocked)<x-ui.pill status="ok">{{ __('Blocked') }}</x-ui.pill>@else<x-ui.pill status="warn">{{ __('Detected') }}</x-ui.pill>@endif</td>
                <td>@include('waf::partials.kind', ['event' => $e])
                    @php $main = $e->mainRule(); @endphp
                    @if ($main)<small class="muted block">{{ __('Rule :id', ['id' => $main['id']]) }}@if (count($e->rules) > 1) · {{ trans_choice('+:count more|+:count more', count($e->rules) - 1) }}@endif</small>@endif</td>
                <td><span class="mono small">{{ $e->method }}</span> <span class="waf-uri">{{ \Illuminate\Support\Str::limit($e->uri, 90) }}</span></td>
                <td class="small mono">{{ $e->ip }}</td>
                <td class="num"><x-ui.button size="sm" :href="$eventUrl($e)">{{ __('Details') }}</x-ui.button></td>
            </tr>
        @endforeach
    </x-ui.table>
    @if ($events->hasPages())<div style="padding:12px 20px">{{ $events->links() }}</div>@endif
@endif
