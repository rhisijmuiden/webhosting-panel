@php
    $bytes = function ($b) { if ($b === null) return '–'; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, $i ? 1 : 0).' '.$u[$i]; };
    $pill = ['succeeded' => 'ok', 'failed' => 'bad', 'running' => 'warn'];
    $schedules = ['off' => __('Off'), 'daily' => __('Daily'), 'weekly' => __('Weekly')];
@endphp
<x-layouts.admin :title="__('Customer backups')">
    <div class="page-head">
        <div><h1>{{ __('Backups') }}</h1><p class="sub">{{ __('Backups that customers make to their own locations, and their restores.') }}</p></div>
    </div>
    @include('backups::partials.admin-tabs')

    <x-ui.card :title="__('Customer backup locations')" flush>
        @if ($targets->isEmpty())
            <x-ui.empty icon="backup">{{ __('No customer has set up a backup location yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('Name') }}</th><th>{{ __('Type') }}</th><th>{{ __('Where') }}</th><th>{{ __('Schedule') }}</th><th>{{ __('Status') }}</th></x-slot:head>
            @foreach ($targets as $t)
                <tr>
                    <td>{{ $t->customer?->displayName() }}</td>
                    <td><strong>{{ $t->name }}</strong></td>
                    <td>{{ strtoupper($t->type) }}</td>
                    <td class="small mono">{{ $t->location() }}</td>
                    <td class="small">{{ $schedules[$t->schedule] ?? $t->schedule }}@if ($t->schedule !== 'off') · {{ $t->schedule_time }}@endif</td>
                    <td>@if ($t->last_error)<x-ui.pill status="bad">{{ __('Error') }}</x-ui.pill> <span class="small muted">{{ \Illuminate\Support\Str::limit($t->last_error, 80) }}</span>@else<x-ui.pill status="ok">{{ __('Ok') }}</x-ui.pill>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('History')" flush>
        <x-slot:actions>
            @foreach (['' => __('All'), 'running' => __('Running'), 'failed' => __('Failed')] as $value => $label)
                <a href="{{ route('admin.backups.customers', array_filter(['status' => $value])) }}" @class(['btn', 'btn-sm', 'btn-dark' => (string) $status === $value])>{{ $label }}</a>
            @endforeach
        </x-slot:actions>
        @if ($records->isEmpty())
            <x-ui.empty icon="backup">{{ __('Nothing here yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Started') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Website') }}</th><th>{{ __('What') }}</th><th>{{ __('Location') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Size') }}</th></x-slot:head>
            @foreach ($records as $r)
                <tr>
                    <td class="small">{{ $r->started_at?->isoFormat('lll') }}<br><span class="muted">{{ $r->trigger === 'schedule' ? __('Scheduled') : ($r->user ?? '') }}</span></td>
                    <td>{{ $r->customer?->displayName() }}</td>
                    <td>{{ $r->domain }}</td>
                    <td>{{ $r->label() }}@if ($r->file)<br><span class="small mono muted">{{ $r->file }}</span>@endif</td>
                    <td class="small">{{ $r->target?->name ?? '–' }}</td>
                    <td><x-ui.pill :status="$pill[$r->status] ?? 'neutral'">{{ __(ucfirst($r->status)) }}</x-ui.pill>@if ($r->message)<br><span class="small muted">{{ \Illuminate\Support\Str::limit($r->message, 120) }}</span>@endif</td>
                    <td class="num small" style="white-space:nowrap">{{ $bytes($r->size) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($records->hasPages())
            <div class="actions" style="padding: 12px 16px">
                @if ($records->previousPageUrl())<a class="btn btn-sm" href="{{ $records->previousPageUrl() }}">{{ __('Newer') }}</a>@endif
                @if ($records->nextPageUrl())<a class="btn btn-sm" href="{{ $records->nextPageUrl() }}">{{ __('Older') }}</a>@endif
            </div>
        @endif
        @endif
    </x-ui.card>
</x-layouts.admin>
