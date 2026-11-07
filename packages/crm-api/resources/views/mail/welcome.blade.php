<x-mail::message>
# {{ __('Welcome, :name', ['name' => $customer->name]) }}

{{ __('Your hosting account is ready. Use the button below to log in for the first time and choose a password. The link works once and is valid for 7 days.') }}

<x-mail::button :url="$url">
{{ __('Log in and choose a password') }}
</x-mail::button>

{{ __('After that you log in at :url with your e-mail address :email.', ['url' => url('/login'), 'email' => $customer->email]) }}

{{ \App\Support\Brand::provider() }}
</x-mail::message>
