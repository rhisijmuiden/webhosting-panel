@php
    $map = ['ok' => ['ok', __('Live')], 'failed' => ['bad', __('Failed')], 'deploying' => ['info', __('Deploying')], 'new' => ['neutral', __('Not deployed yet')]];
    [$pillStatus, $pillLabel] = $map[$repo->status] ?? ['neutral', $repo->status];
@endphp
<x-ui.pill :status="$pillStatus">{{ $pillLabel }}</x-ui.pill>
