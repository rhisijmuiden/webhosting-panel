{{-- Score ring 0-100. Tone: ok | warn | bad. The slot is shown in the middle. --}}
@props(['value' => 0, 'tone' => 'ok', 'size' => 132])
@php $c = 2 * M_PI * 50; $v = max(0, min(100, (float) $value)); @endphp
<div {{ $attributes->merge(['role' => 'img', 'aria-label' => round($v).' / 100'])->class(['ring', 'ring-'.$tone]) }} style="width:{{ $size }}px;height:{{ $size }}px">
    <svg viewBox="0 0 120 120" aria-hidden="true">
        <circle cx="60" cy="60" r="50" class="ring-track" />
        <circle cx="60" cy="60" r="50" class="ring-value" stroke-dasharray="{{ round($c * $v / 100, 1) }} {{ round($c, 1) }}" transform="rotate(-90 60 60)" />
    </svg>
    <span class="ring-label">{{ $slot }}</span>
</div>
