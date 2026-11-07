@php
    [$st, $label] = match ($issue) {
        'missing' => ['bad', __('Missing')],
        'added' => ['warn', __('Should not be there')],
        default => ['warn', __('Changed')],
    };
@endphp
<x-ui.pill :status="$st">{{ $label }}</x-ui.pill>
