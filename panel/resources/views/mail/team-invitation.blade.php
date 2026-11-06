<x-mail::message>
# {{ __('You are invited') }}

{{ __(':name invites you to help manage their hosting in :app.', ['name' => $inviter, 'app' => config('app.name')]) }}

**{{ __('Websites') }}:** {{ $websites }}<br>
**{{ __('Access to') }}:** {{ $areas }}

<x-mail::button :url="$url">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('The link works until :date. Did you not expect this e-mail? Then you can ignore it.', ['date' => $invitation->expires_at->locale(app()->getLocale())->isoFormat('LL')]) }}
</x-mail::message>
