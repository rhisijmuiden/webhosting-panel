{{-- One database cleanup: progress while it runs, then what was removed. --}}
@if ($cleanup->isRunning())
    <meta http-equiv="refresh" content="3">
@endif
@php
    $dbcleanItems = \Rhis\WordPress\DbCleanup\Cleaner::labels();
    $num = fn ($n) => number_format((int) $n, 0, app()->getLocale() === 'nl' ? ',' : '.', app()->getLocale() === 'nl' ? '.' : ',');
    $fs = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1);
    $result = $cleanup->result ?? [];
@endphp
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / <a href="{{ route($area.'.wordpress.show', $install) }}">{{ $install->label() }}</a> / <a href="{{ route($area.'.wordpress.dbclean.show', $install) }}">{{ __('Database cleanup') }}</a></div>
        <div class="actions"><h1>{{ __('Database cleanup') }}</h1>@include('wordpress::dbclean.pill', ['cleanup' => $cleanup])</div>
        <p class="sub">{{ $install->label() }} · {{ __('Started') }} {{ $cleanup->created_at->diffForHumans() }}{{ $cleanup->user ? ' · '.$cleanup->user->name : '' }}</p>
    </div>
    <div class="actions">
        <x-ui.button :href="route($area.'.wordpress.show', $install)" icon="wordpress">{{ __('Back to the site') }}</x-ui.button>
    </div>
</div>
@foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert">{{ $e }}</div>@endforeach

@if ($cleanup->isRunning())
    <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('First a snapshot is made, then the database is cleaned up. This page refreshes by itself; you can also close it, the cleanup continues.') }}</div>
@elseif ($cleanup->status === 'rolled_back')
    <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('The website stopped working after the cleanup (HTTP :code), so the database was put back as it was. Nothing was lost.', ['code' => $result['health']['after'] ?? '?']) }}</div>
@elseif ($cleanup->status === 'failed')
    <div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $cleanup->error }}</div>
@endif

@if ($cleanup->status === 'succeeded')
    <section class="grid grid-stats">
        <div class="card stat"><span class="label">{{ __('Removed') }}</span><strong class="value" style="font-size:24px">{{ $num($cleanup->removedTotal()) }}</strong><span class="hint">{{ __('rows') }}</span></div>
        <div class="card stat"><span class="label">{{ __('Database size') }}</span><strong class="value" style="font-size:24px">{{ $fs($cleanup->size_after ?? 0) }}</strong>
            <span class="hint">{{ __('was :size', ['size' => $fs($cleanup->size_before ?? 0)]) }}</span></div>
        <div class="card stat"><span class="label">{{ __('Snapshot') }}</span><strong class="value mono" style="font-size:16px">{{ $cleanup->snapshot ?? '–' }}</strong>
            <span class="hint"><a href="{{ route($area.'.wordpress.show', $install) }}">{{ __('Restore on the WordPress page') }}</a></span></div>
    </section>
@endif

<x-ui.card :title="__('What was cleaned up')" flush>
    <x-ui.table>
        <x-slot:head><th>{{ __('What') }}</th><th class="num">{{ __('Removed') }}</th></x-slot:head>
        @foreach ($cleanup->items as $key)
            <tr>
                <td>{{ $dbcleanItems[$key][0] ?? $key }}@if ($key === 'revisions' && $cleanup->keep_revisions) <span class="muted small">({{ trans_choice('The newest :count revision of every post is kept.|The newest :count revisions of every post are kept.', $cleanup->keep_revisions) }})</span>@endif</td>
                <td class="num">@if ($cleanup->status !== 'succeeded')<span class="muted">–</span>@elseif ($key === 'optimize'){{ trans_choice(':count table|:count tables', $cleanup->removed()[$key] ?? 0) }}@else{{ $num($cleanup->removed()[$key] ?? 0) }}@endif</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>

@if ($area === 'admin' && ! empty($job['log']))
    <x-ui.card :title="__('Log')"><div class="log">{{ implode("\n", $job['log']) }}</div></x-ui.card>
@endif
