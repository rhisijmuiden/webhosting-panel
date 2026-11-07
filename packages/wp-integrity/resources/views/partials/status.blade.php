@php
    $s = $report?->status ?? 'unknown';
    [$st, $label] = match (true) {
        $s === 'changed' && ($report->malware_files ?? 0) > 0 => ['bad', __('Malware in changed files')],
        $s === 'changed' => ['warn', trans_choice(':count changed file|:count changed files', $report->changed_files, ['count' => $report->changed_files])],
        $s === 'ok' => ['ok', __('Original files')],
        $s === 'error' => ['bad', __('Check failed')],
        default => ['neutral', __('Not checked yet')],
    };
@endphp
<x-ui.pill :status="$st">{{ $label }}</x-ui.pill>
