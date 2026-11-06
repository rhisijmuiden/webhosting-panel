@props(['label', 'percent'])
@php $p = max(0, min(100, (float) $percent)); @endphp
<div class="meter">
    <span>{{ $label }}</span>
    <span class="bar" role="progressbar" aria-label="{{ $label }}" aria-valuenow="{{ round($p) }}" aria-valuemin="0" aria-valuemax="100"><span class="{{ $p >= 90 ? 'bad' : ($p >= 75 ? 'warn' : '') }}" style="width: {{ $p }}%"></span></span>
    <span>{{ round($p) }}%</span>
</div>
