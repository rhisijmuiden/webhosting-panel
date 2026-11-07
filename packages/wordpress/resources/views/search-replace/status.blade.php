@php
    $srStatus = [
        'previewing' => ['warn', __('Preview running')], 'previewed' => ['neutral', __('Preview ready')],
        'preview_failed' => ['bad', __('Preview failed')], 'applying' => ['warn', __('Changing')],
        'applied' => ['ok', __('Done')], 'failed' => ['bad', __('Failed')], 'rolled_back' => ['bad', __('Put back')],
    ][$run->status] ?? ['neutral', $run->status];
@endphp
<x-ui.pill :status="$srStatus[0]">{{ $srStatus[1] }}</x-ui.pill>
