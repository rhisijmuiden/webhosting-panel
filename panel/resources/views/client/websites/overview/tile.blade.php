{{-- One tool on the domain page. $href (null: hidden), $icon, $label, optional $sub, $tone (ok|warn|bad), $external. --}}
@php $tone = $tone ?? null; @endphp
@if ($href ?? null)
<a class="tool-tile" href="{{ $href }}" @if ($external ?? false) target="_blank" rel="noopener" @endif>
    <span @class(['tool-icon', 'tone-'.($tone ?? 'none')])>@include('client.websites.overview.icon', ['name' => $icon])</span>
    <span class="tool-text"><strong>{{ $label }}@if ($external ?? false) <x-icon name="external" />@endif</strong>
        @if ($sub ?? null)<small @class(['is-'.$tone => $tone !== null])>{{ $sub }}</small>@endif</span>
</a>
@endif
