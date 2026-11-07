<x-mail::message>
# {{ __('Reset your password') }}

{{ __('Hello :name,', ['name' => $user->name]) }}

{{ __('Someone asked to reset the password of your login :email for :app. Click the button to choose a new password.', ['email' => $user->email, 'app' => config('app.name')]) }}

<x-mail::button :url="$url">
{{ __('Choose a new password') }}
</x-mail::button>

{{ __('The link works for :minutes minutes and only once. Did you not ask for this? Then you can ignore this e-mail; your password stays the same.', ['minutes' => $minutes]) }}
</x-mail::message>
