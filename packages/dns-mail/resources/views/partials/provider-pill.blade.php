@php $labels = ['rhis' => __('RHIS Mail'), 'microsoft365' => 'Microsoft 365', 'external' => __('Other provider')]; @endphp
<x-ui.pill :status="$provider === 'rhis' ? 'info' : 'neutral'" :dot="false">{{ $labels[$provider] ?? $provider }}</x-ui.pill>
