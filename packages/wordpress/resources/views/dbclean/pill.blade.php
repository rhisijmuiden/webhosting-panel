@php
    $dbcPill = ['running' => ['warn', __('Running')], 'succeeded' => ['ok', __('Done')], 'rolled_back' => ['bad', __('Put back')], 'failed' => ['bad', __('Failed')]][$cleanup->status] ?? ['neutral', $cleanup->status];
@endphp
<x-ui.pill :status="$dbcPill[0]">{{ $dbcPill[1] }}</x-ui.pill>
