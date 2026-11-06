{{-- $state: running | starting | stopping | crashing | failed | stopped | null (unknown) --}}
@php
    $map = ['running' => ['ok', __('Running')], 'starting' => ['warn', __('Starting')], 'stopping' => ['warn', __('Stopping')],
            'crashing' => ['bad', __('Restarting after a crash')], 'failed' => ['bad', __('Crashed')], 'stopped' => ['neutral', __('Stopped')]];
    [$tone, $text] = $map[$state ?? ''] ?? ['neutral', __('Unknown')];
@endphp
<x-ui.pill :status="$tone">{{ $text }}</x-ui.pill>
