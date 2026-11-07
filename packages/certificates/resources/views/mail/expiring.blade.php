<x-mail::message>
# {{ __('SSL certificates that need attention') }}

@if ($admin)
{{ __('These certificates expire soon. Own certificates must be replaced by hand; for Let\'s Encrypt this means automatic renewal keeps failing.') }}
@else
{{ __('You installed your own SSL certificate for these websites. Order a new certificate from your supplier and upload it in the panel before the date below, otherwise visitors get a security warning.') }}
@endif

<x-mail::table>
| {{ __('Website') }} | {{ __('Type') }} | {{ __('Valid until') }} | {{ __('Days left') }} |
| :-- | :-- | :-- | --: |
@foreach ($certificates as $c)
| {{ $c->website?->domain }} | {{ \Rhis\Certificates\Present::source($c) }} | {{ $c->not_after?->format('Y-m-d') }} | {{ $c->isExpired() ? __('expired') : $c->daysLeft() }} |
@endforeach
</x-mail::table>

<x-mail::button :url="$admin ? route('admin.certificates.index') : route('client.certificates.index')">
{{ __('Open SSL certificates') }}
</x-mail::button>
</x-mail::message>
