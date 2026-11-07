{{-- $id --}}
<div class="field">
    <label for="{{ $id }}">{{ __('Password') }}</label>
    <input id="{{ $id }}" class="input" type="password" name="password" minlength="{{ \App\Services\FtpAccounts::MIN_PASSWORD }}" maxlength="128" autocomplete="new-password" placeholder="{{ __('Leave empty for a strong generated password') }}">
    <span class="help">{{ __('At least :count characters. Leave it empty and we make one for you; it is shown once.', ['count' => \App\Services\FtpAccounts::MIN_PASSWORD]) }}</span>
    @error('password')<span class="error">{{ $message }}</span>@enderror
</div>
