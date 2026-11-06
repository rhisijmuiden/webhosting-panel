<x-mail::message>
# {{ __('Your websites move to a newer PHP version') }}

{{ __('Dear :name,', ['name' => $customer->displayName()]) }}

{{ __('PHP :version, the version these websites run on, gets no more security updates after :date. To keep your websites safe, we move them to PHP :target on :date.', [
    'version' => $version,
    'target' => $target ?? __('the next version'),
    'date' => $at->locale(app()->getLocale())->isoFormat('LL'),
]) }}

@foreach ($domains as $domain)
- {{ $domain }}
@endforeach

{{ __('We check every website before and after the switch. If a website stops working on the new version, it goes back to the old version and we contact you.') }}

{{ __('Want to test it yourself first? Switch the PHP version of a website in your control panel; you can switch back at any time.') }}

<x-mail::button :url="url('/')">
{{ __('Open the control panel') }}
</x-mail::button>
</x-mail::message>
