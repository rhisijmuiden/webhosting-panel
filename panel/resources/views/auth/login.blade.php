<x-layouts.guest :title="__('Log in')">
    <div>
        <h1>{{ __('Log in') }}</h1>
        <p class="muted" style="margin-top:6px">{{ __('Welcome back. Log in to manage your hosting.') }}</p>
    </div>
    <form method="POST" action="{{ route('login') }}" class="form">
        @csrf
        <x-ui.field name="email" type="email" :label="__('E-mail address')" required autofocus autocomplete="username" />
        <x-ui.field name="password" type="password" :label="__('Password')" required autocomplete="current-password" />
        <label class="check"><input type="checkbox" name="remember" value="1"> {{ __('Keep me logged in') }}</label>
        <button type="submit" class="btn btn-primary">{{ __('Log in') }}</button>
    </form>
</x-layouts.guest>
