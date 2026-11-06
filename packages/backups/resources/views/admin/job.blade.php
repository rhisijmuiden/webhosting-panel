<x-layouts.admin :title="__('Backup job')">
    @if (($job['status'] ?? null) === 'running')
        <meta http-equiv="refresh" content="3">
    @endif
    <div class="page-head">
        <div><h1>{{ $meta['label'] ?? __('Backup job') }}</h1><p class="sub">{{ __('Started') }} {{ isset($meta['started_at']) ? \Illuminate\Support\Carbon::parse($meta['started_at'])->diffForHumans() : '' }}{{ isset($meta['user']) ? ' · '.$meta['user'] : '' }}</p></div>
        <div class="actions"><x-ui.button :href="route('admin.backups.index')" icon="backup">{{ __('Back to backups') }}</x-ui.button></div>
    </div>
    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert">{{ $e }}</div>@endforeach
    @if ($job)
        <x-ui.card>
            <p>
                @if ($job['status'] === 'running')<x-ui.pill status="warn">{{ __('Running') }}</x-ui.pill> <span class="muted small">{{ __('This page refreshes by itself.') }}</span>
                @elseif ($job['status'] === 'succeeded')<x-ui.pill status="ok">{{ __('Done') }}</x-ui.pill>
                @else<x-ui.pill status="bad">{{ __('Failed') }}</x-ui.pill>@endif
            </p>
            @if ($job['error'] ?? null)<div class="alert alert-bad" role="alert">{{ $job['error']['message'] ?? '' }}</div>@endif
            @if (($job['result']['status'] ?? null) === 'failed' || ($job['result']['status'] ?? null) === 'warning')
                <div class="alert alert-warn">{{ __('Some items did not succeed, see the details below.') }}</div>
            @endif
            @if (! empty($job['log']))<div class="log">{{ implode("\n", $job['log']) }}</div>@endif
            @if (! empty($job['result']))
                <details><summary class="small">{{ __('Details') }}</summary><pre class="log">{{ json_encode($job['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
            @endif
        </x-ui.card>
    @endif
</x-layouts.admin>
