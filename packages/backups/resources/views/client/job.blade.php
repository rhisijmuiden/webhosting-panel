<x-layouts.client :title="__('Backups')">
    @if (($job['status'] ?? null) === 'running')
        <meta http-equiv="refresh" content="3">
    @endif
    @php $action = $record?->action; @endphp
    <div class="page-head">
        <div><h1>{{ $meta['label'] }}</h1></div>
        <div class="actions"><x-ui.button :href="route('client.backups.index')" icon="backup">{{ __('Back to backups') }}</x-ui.button></div>
    </div>
    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert">{{ $e }}</div>@endforeach
    @if ($job)
        <x-ui.card>
            @if ($job['status'] === 'running')<p><x-ui.pill status="warn">{{ __('Running') }}</x-ui.pill> <span class="muted small">{{ __('This page refreshes by itself.') }}</span></p>
            @elseif ($job['status'] === 'succeeded')
                <div class="alert alert-ok">{{ $action === 'backup' ? __('The backup is ready: :file', ['file' => $job['result']['file'] ?? '']) : __('Your website has been restored.') }}</div>
                @if (! empty($job['result']['safety_backup']))<p class="small muted">{{ __('The state before the restore was saved as :file.', ['file' => $job['result']['safety_backup']]) }}</p>@endif
                @if (! empty($job['result']['skipped_databases']))<p class="small muted">{{ __('Not restored because they do not belong to this website: :list', ['list' => implode(', ', $job['result']['skipped_databases'])]) }}</p>@endif
            @else
                <div class="alert alert-bad">{{ $action === 'backup' ? __('The backup failed.') : __('The restore failed. Please contact support.') }}
                    @if ($record && ($job['error']['message'] ?? null))<br><span class="small">{{ $job['error']['message'] }}</span>@endif</div>
            @endif
        </x-ui.card>
    @endif
</x-layouts.client>
