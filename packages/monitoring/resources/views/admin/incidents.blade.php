@php use Rhis\Monitoring\Describe; use Rhis\Monitoring\Severity; @endphp
<x-layouts.admin :title="__('Incidents')">
    @include('monitoring::partials.styles')
    <div class="page-head"><div><h1>{{ __('Monitoring') }}</h1><p class="sub">{{ __('Every problem the monitoring found, from start to solved.') }}</p></div></div>
    @include('monitoring::partials.tabs')
    <x-ui.card flush>
        <x-slot:actions>
            <form method="GET" class="mo-filters">
                <select class="select" name="kind" aria-label="{{ __('Kind') }}">
                    <option value="">{{ __('Everything') }}</option>
                    @foreach (['website', 'ssl', 'disk', 'inode', 'service', 'memory', 'load', 'mail_queue', 'agent'] as $k)<option value="{{ $k }}" @selected($kind === $k)>{{ Describe::kind($k) }}</option>@endforeach
                </select>
                <select class="select" name="state" aria-label="{{ __('State') }}">
                    <option value="">{{ __('Open and solved') }}</option><option value="open" @selected($state === 'open')>{{ __('Only open') }}</option>
                </select>
                <x-ui.button size="sm">{{ __('Filter') }}</x-ui.button>
            </form>
        </x-slot:actions>
        @if ($incidents->isEmpty())
            <x-ui.empty icon="check">{{ __('No incidents.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('What') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Started') }}</th><th>{{ __('Duration') }}</th><th>{{ __('State') }}</th></x-slot:head>
            @foreach ($incidents as $i)
                <tr>
                    <td class="mo-site">@if ($i->website_id)<a href="{{ route('admin.monitoring.website', $i->website_id) }}">{{ Describe::incident($i) }}</a>@else<strong>{{ Describe::incident($i) }}</strong>@endif
                        @if ($i->detail)<span class="small muted mono">{{ $i->detail }}</span>@endif</td>
                    <td>{{ Describe::kind($i->kind) }}</td>
                    <td class="small">{{ $i->started_at->timezone(config('app.timezone'))->isoFormat('D MMM YYYY HH:mm') }}</td>
                    <td class="small">{{ $i->duration() }}</td>
                    <td>@if ($i->resolved_at)<x-ui.pill status="ok">{{ __('Solved') }}</x-ui.pill>@else<x-ui.pill :status="Severity::pill($i->severity)">{{ Severity::label($i->severity) }}</x-ui.pill>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    {{ $incidents->links() }}
</x-layouts.admin>
