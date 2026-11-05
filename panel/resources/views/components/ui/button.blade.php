@props(['variant' => null, 'href' => null, 'icon' => null, 'size' => null])
@php $classes = ['btn', 'btn-'.$variant => $variant, 'btn-'.$size => $size]; @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>@if ($icon)<x-icon :name="$icon" />@endif{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit'])->class($classes) }}>@if ($icon)<x-icon :name="$icon" />@endif{{ $slot }}</button>
@endif
