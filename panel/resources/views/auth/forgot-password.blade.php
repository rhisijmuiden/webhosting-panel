<x-layouts.guest :title="__('Forgot your password?')">
    <div>
        <h1>{{ __('Forgot your password?') }}</h1>
        <p class="muted" style="margin-top:6px">{{ __('Enter the e-mail address you log in with. We send you a link to choose a new password.') }}</p>
    </div>
    @include('partials.flash')
    <form method="POST" action="{{ route('password.email') }}" class="form">
        @csrf
        <x-ui.field name="email" type="email" :label="__('E-mail address')" :value="old('email')" required autofocus autocomplete="username" />
        <button type="submit" class="btn btn-primary">{{ __('Send reset link') }}</button>
    </form>
    <a class="small" href="{{ route('login') }}">{{ __('Back to log in') }}</a>
</x-layouts.guest>
