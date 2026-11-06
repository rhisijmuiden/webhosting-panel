{{-- Table of deployments. $deployments, $area, optional $showRepo. --}}
@php
    $triggers = ['manual' => __('Manual'), 'webhook' => __('Push'), 'rollback' => __('Rollback')];
    $states = ['succeeded' => __('Succeeded'), 'failed' => __('Failed'), 'running' => __('Running'), 'queued' => __('Waiting')];
@endphp
<x-ui.table>
    <x-slot:head>
        <th>{{ __('When') }}</th>
        @if ($showRepo ?? false)<th>{{ __('Repository') }}</th>@endif
        <th>{{ __('Commit') }}</th><th>{{ __('Started by') }}</th><th class="num">{{ __('Duration') }}</th><th>{{ __('Status') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th>
    </x-slot:head>
    @foreach ($deployments as $d)
        @php $c = $d->result['commit'] ?? null; $r = $d->repository; @endphp
        <tr>
            <td>{{ $d->created_at->isoFormat('lll') }} <div class="muted small">{{ $d->created_at->diffForHumans() }}</div></td>
            @if ($showRepo ?? false)<td><a href="{{ route($area.'.git.show', $r) }}">{{ $r->name }}</a> <div class="muted small">{{ $r->website->domain }}</div></td>@endif
            <td>@if ($c)
                    @if ($url = $r->commitUrl($c['commit'] ?? null))<a class="mono" href="{{ $url }}" target="_blank" rel="noopener">{{ $c['short'] ?? '' }}</a>@else<span class="mono">{{ $c['short'] ?? '' }}</span>@endif
                    <span class="small">{{ \Illuminate\Support\Str::limit($c['message'] ?? '', 60) }}</span>
                @else<span class="muted small">–</span>@endif</td>
            <td><x-ui.pill status="neutral" :dot="false">{{ $triggers[$d->trigger] ?? $d->trigger }}</x-ui.pill> <span class="small muted">{{ $d->user }}</span></td>
            <td class="num">{{ $d->seconds() !== null ? number_format((float) $d->seconds(), 1).' s' : '–' }}</td>
            <td><x-ui.pill :status="$d->pill()">{{ $states[$d->status] ?? $d->status }}</x-ui.pill></td>
            <td class="num">@if ($d->trigger !== 'rollback')<a class="btn btn-sm" href="{{ route($area.'.git.deployments.show', [$r, $d]) }}">{{ __('Log') }}</a>@endif</td>
        </tr>
    @endforeach
</x-ui.table>
