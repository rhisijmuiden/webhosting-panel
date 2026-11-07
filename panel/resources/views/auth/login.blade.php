<x-layouts.guest :title="__('Log in')">
    <div>
        <h1>{{ __('Log in') }}</h1>
        <p class="muted" style="margin-top:6px">{{ __('Welcome back. Log in to manage your hosting.') }}</p>
    </div>
    @include('partials.flash')
    <form method="POST" action="{{ route('login') }}" class="form">
        @csrf
        <x-ui.field name="email" type="email" :label="__('E-mail address')" required autofocus autocomplete="username" />
        <x-ui.field name="password" type="password" :label="__('Password')" required autocomplete="current-password" />
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><label class="check"><input type="checkbox" name="remember" value="1"> {{ __('Keep me logged in') }}</label>
            @if (Route::has('password.request'))<a class="small" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>@endif</div>
        <button type="submit" class="btn btn-primary">{{ __('Log in') }}</button>
    </form>
</x-layouts.guest>
