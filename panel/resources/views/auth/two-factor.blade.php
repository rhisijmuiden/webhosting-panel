<x-layouts.guest :title="__('Two-factor authentication')">
    <div>
        <h1>{{ __('Enter your code') }}</h1>
        <p class="muted" style="margin-top:6px">{{ __('Open your authenticator app and enter the 6-digit code.') }}</p>
    </div>
    <form method="POST" action="{{ route('two-factor.verify') }}" class="form">
        @csrf
        <x-ui.field name="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" required autofocus />
        <button type="submit" class="btn btn-primary">{{ __('Verify') }}</button>
    </form>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost" type="submit">{{ __('Log out') }}</button></form>
</x-layouts.guest>
