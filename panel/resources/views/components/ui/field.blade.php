@props(['name', 'label', 'type' => 'text', 'value' => null, 'help' => null, 'required' => false])
@php $id = 'f-'.str_replace(['[', ']', '.'], '-', $name); $err = $errors->first($name); @endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" {{ $attributes->class(['textarea']) }} @if ($err) aria-invalid="true" @endif>{{ old($name, $value) }}</textarea>
    @elseif ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->class(['select']) }} @if ($err) aria-invalid="true" @endif>{{ $slot }}</select>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}" @if ($required) required @endif {{ $attributes->class(['input']) }} @if ($err) aria-invalid="true" aria-describedby="{{ $id }}-err" @endif>
    @endif
    @if ($help)<span class="help">{{ $help }}</span>@endif
    @if ($err)<span class="error" id="{{ $id }}-err">{{ $err }}</span>@endif
</div>
