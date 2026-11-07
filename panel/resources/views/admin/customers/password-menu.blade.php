{{-- Password help for one login of a customer: choose one, generate one, or mail a reset link. --}}
@php $pwErrors = $errors->getBag('password_set'); $reopen = $pwErrors->any() && (int) old('login_id') === $u->id; @endphp
<div class="dropdown" x-data="{ menu: false }" x-init="{{ $reopen ? '$nextTick(() => $refs.pwSet.showModal())' : '' }}" @click.outside="menu = false" @keydown.escape="menu = false">
    <button type="button" class="btn btn-sm" @click="menu = !menu" :aria-expanded="menu" aria-haspopup="menu" title="{{ __('Password') }}">
        <x-icon name="key" /><span class="login-action-label">{{ __('Password') }}</span> <span aria-hidden="true">▾</span></button>
    <div class="dropdown-menu" role="menu" x-show="menu" x-cloak style="min-width:240px">
        <button type="button" role="menuitem" @click="menu = false; $refs.pwSet.showModal()"><x-icon name="edit" />{{ __('Set a password…') }}</button>
        <form method="POST" action="{{ route('admin.customers.users.password.link', [$customer, $u]) }}" data-confirm="{{ __('Mail :email a link to choose a new password? The current password keeps working until they use it.', ['email' => $u->email]) }}">@csrf
            <button type="submit" role="menuitem"><x-icon name="mail" />{{ __('Mail a reset link') }}</button></form>
        <form method="POST" action="{{ route('admin.customers.users.password', [$customer, $u]) }}" data-confirm="{{ __('Give :email a new password? The old password stops working.', ['email' => $u->email]) }}">@csrf
            <button type="submit" role="menuitem"><x-icon name="refresh" />{{ __('Generate a password') }}</button></form>
    </div>

    <dialog class="modal" x-ref="pwSet" aria-labelledby="pw-set-{{ $u->id }}">
        <form method="POST" action="{{ route('admin.customers.users.password.set', [$customer, $u]) }}">
            @csrf
            <input type="hidden" name="login_id" value="{{ $u->id }}">
            <div class="modal-head"><h2 id="pw-set-{{ $u->id }}">{{ __('Set a password') }}</h2><button type="button" class="btn btn-ghost icon-btn" @click="$refs.pwSet.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <p class="small muted">{{ __('For :email. The old password stops working right away and the login is logged out on all devices.', ['email' => $u->email]) }}</p>
                <div class="field">
                    <label for="pw-new-{{ $u->id }}">{{ __('New password') }} <span aria-hidden="true">*</span></label>
                    <input id="pw-new-{{ $u->id }}" class="input" type="password" name="password" required minlength="12" autocomplete="new-password" @if ($reopen && $pwErrors->has('password')) aria-invalid="true" @endif>
                    <span class="help">{{ __('At least 12 characters.') }}</span>
                    @if ($reopen && $pwErrors->has('password'))<span class="error">{{ $pwErrors->first('password') }}</span>@endif
                </div>
                <div class="field">
                    <label for="pw-rep-{{ $u->id }}">{{ __('Repeat the password') }} <span aria-hidden="true">*</span></label>
                    <input id="pw-rep-{{ $u->id }}" class="input" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" @click="$refs.pwSet.close()">{{ __('Cancel') }}</button><button type="submit" class="btn btn-primary">{{ __('Save password') }}</button></div>
        </form>
    </dialog>
</div>
