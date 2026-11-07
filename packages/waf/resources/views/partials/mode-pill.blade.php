@php $status = ['block' => 'ok', 'detect' => 'warn', 'off' => 'bad'][$mode] ?? 'neutral'; @endphp
<x-ui.pill :status="$status">{{ \Rhis\Waf\Http\AdminWafController::modeLabel($mode) }}</x-ui.pill>
