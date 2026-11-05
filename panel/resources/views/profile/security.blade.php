@component('profile.layout', ['title' => __('Security')])
<x-ui.card :title="__('Two-factor authentication')">
    <x-slot:actions>
        @if ($user->hasTwoFactor())<x-ui.pill status="ok">{{ __('On') }}</x-ui.pill>@else<x-ui.pill status="warn">{{ __('Off') }}</x-ui.pill>@endif
    </x-slot:actions>
    <p class="muted">{{ __('Protect your account with a code from an authenticator app, such as Microsoft Authenticator, Google Authenticator or 1Password.') }}</p>

    @if ($user->hasTwoFactor())
        <form method="POST" action="{{ route('profile.two-factor.disable') }}" class="form" style="max-width:420px">
            @csrf @method('DELETE')
            <x-ui.field name="password" type="password" :label="__('Password')" :help="__('Enter your password to turn two-factor authentication off.')" required />
            <div><button type="submit" class="btn btn-danger">{{ __('Turn off') }}</button></div>
        </form>
    @elseif ($pendingSecret)
        <div class="grid grid-2" style="align-items:start">
            <div class="field">
                <span class="label">{{ __('1. Scan this QR code') }}</span>
                <div x-data="qr(@js($otpUri))" style="width:200px;height:200px;background:#fff;border-radius:10px;padding:6px" role="img" aria-label="{{ __('QR code') }}"></div>
                <span class="help">{{ __('Or enter this key:') }} <code>{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</code></span>
            </div>
            <form method="POST" action="{{ route('profile.two-factor.confirm') }}" class="form">
                @csrf
                <x-ui.field name="code" :label="__('2. Enter the code from the app')" inputmode="numeric" autocomplete="one-time-code" required />
                <div><button type="submit" class="btn btn-primary">{{ __('Turn on') }}</button></div>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('profile.two-factor.enable') }}">
            @csrf<button type="submit" class="btn btn-primary">{{ __('Set up two-factor authentication') }}</button>
        </form>
    @endif
</x-ui.card>
@endcomponent
