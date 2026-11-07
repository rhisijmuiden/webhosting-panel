{{-- Mode of a policy with its icon. Needs $policy (may be unsaved). --}}
@php $m = $policy->effectiveMode(); @endphp
<span class="su-mode su-mode-{{ $m }}"><span class="ic"><x-icon :name="\Rhis\WordPress\SmartUpdates\Models\UpdatePolicy::modeIcon($m)" /></span>
    <span>{{ \Rhis\WordPress\SmartUpdates\Models\UpdatePolicy::modeLabel($m) }}@if ($policy->usesDefault())<small>{{ __('Panel default') }}</small>@endif</span></span>
