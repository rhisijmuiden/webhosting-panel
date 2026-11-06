@props(['name', 'label', 'value' => null, 'unit' => null, 'min' => 0, 'max' => null, 'help' => null, 'unlimitedLabel' => null])
@php
    $id = 'f-'.$name;
    $err = $errors->first($name);
    $unlimited = old() ? (bool) old("unlimited.$name") : $value === null;
@endphp
<div class="field limit" x-data="{ u: @js($unlimited) }">
    <label for="{{ $id }}">{{ $label }}</label>
    <div class="limit-row">
        <span class="input-unit" :class="{ 'is-off': u }">
            <input id="{{ $id }}" name="{{ $name }}" type="number" class="input" min="{{ $min }}" @if ($max) max="{{ $max }}" @endif
                value="{{ old($name, $value) }}" :disabled="u" :required="!u" @if ($err) aria-invalid="true" aria-describedby="{{ $id }}-err" @endif>
            @if ($unit)<span class="unit">{{ $unit }}</span>@endif
        </span>
        <label class="check limit-toggle"><input type="checkbox" name="unlimited[{{ $name }}]" value="1" x-model="u"><span>{{ $unlimitedLabel ?? __('Unlimited') }}</span></label>
    </div>
    @if ($help)<span class="help">{{ $help }}</span>@endif
    @if ($err)<span class="error" id="{{ $id }}-err">{{ $err }}</span>@endif
</div>
