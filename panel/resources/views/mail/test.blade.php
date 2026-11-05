<x-mail::message>
# {{ __('It works') }}

{{ __('This is a test message from :app. Your mail server settings are correct.', ['app' => config('app.name')]) }}

{{ __('Sent at :time', ['time' => now()->toDayDateTimeString()]) }}
</x-mail::message>
