<x-layouts.client :title="__('Restore')">
    @if (($job['status'] ?? null) === 'running')
        <meta http-equiv="refresh" content="3">
    @endif
    <div class="page-head">
        <div><h1>{{ $meta['label'] }}</h1></div>
        <div class="actions"><x-ui.button :href="route('client.backups.index')" icon="backup">{{ __('Back to backups') }}</x-ui.button></div>
    </div>
    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert">{{ $e }}</div>@endforeach
    @if ($job)
        <x-ui.card>
            @if ($job['status'] === 'running')<p><x-ui.pill status="warn">{{ __('Running') }}</x-ui.pill> <span class="muted small">{{ __('This page refreshes by itself.') }}</span></p>
            @elseif ($job['status'] === 'succeeded')<div class="alert alert-ok">{{ __('Your website has been restored.') }}</div>
            @else<div class="alert alert-bad">{{ __('The restore failed. Please contact support.') }}</div>@endif
        </x-ui.card>
    @endif
</x-layouts.client>
