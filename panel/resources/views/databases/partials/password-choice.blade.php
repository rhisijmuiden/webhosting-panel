{{-- Password: made up by the server (default) or chosen. $bag = error bag, $id = unique prefix. --}}
@php $err = $errors->getBag($bag)->first('password'); $own = old('password_mode') === 'own'; @endphp
<div class="field" x-data="{ own: @js($own) }">
    <span class="label">{{ __('Password') }}</span>
    <div class="seg" role="radiogroup" aria-label="{{ __('Password') }}">
        <label><input type="radio" name="password_mode" value="generate" @change="own = false" @checked(! $own)><span>{{ __('Generate a strong password') }}</span></label>
        <label><input type="radio" name="password_mode" value="own" @change="own = true" @checked($own)><span>{{ __('Choose my own') }}</span></label>
    </div>
    <template x-if="own">
        <input id="{{ $id }}-password" name="password" type="password" class="input" minlength="12" maxlength="128" autocomplete="new-password"
            placeholder="{{ __('At least 12 characters') }}" aria-label="{{ __('Password') }}" required @if ($err) aria-invalid="true" @endif>
    </template>
    <span class="help" x-show="! own">{{ __('32 random characters. You see it once, right after saving.') }}</span>
    @if ($err)<span class="error">{{ $err }}</span>@endif
</div>
