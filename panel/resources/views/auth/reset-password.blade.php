<x-layouts.guest :title="__('Choose a new password')">
    <div>
        <h1>{{ __('Choose a new password') }}</h1>
        <p class="muted" style="margin-top:6px">{{ __('After saving you are logged out on all devices. Log in again with your new password.') }}</p>
    </div>
    <form method="POST" action="{{ route('password.update') }}" class="form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-ui.field name="email" type="email" :label="__('E-mail address')" :value="old('email', $email)" required autocomplete="username" />
        <x-ui.field name="password" type="password" :label="__('New password')" :help="__('At least 12 characters, with upper and lower case letters and a number.')" required autofocus autocomplete="new-password" />
        <x-ui.field name="password_confirmation" type="password" :label="__('Repeat the password')" required autocomplete="new-password" />
        <button type="submit" class="btn btn-primary">{{ __('Save password') }}</button>
    </form>
    <a class="small" href="{{ route('password.request') }}">{{ __('Request a new link') }}</a>
</x-layouts.guest>
