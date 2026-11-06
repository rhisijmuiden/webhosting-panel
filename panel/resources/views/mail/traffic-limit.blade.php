<x-mail::message>
# {{ __('Data traffic limit reached') }}

{{ __(':domain (:customer) has used :used of data traffic this month. The package :package allows :limit.', [
    'domain' => $subscription->website?->domain,
    'customer' => $subscription->website?->customer?->displayName(),
    'used' => \Illuminate\Support\Number::fileSize($usedBytes, 1),
    'package' => $subscription->package->name,
    'limit' => $subscription->package->limitLabel('traffic_gb_month'),
]) }}

@if ($suspended)
{{ __('The website is suspended, as set in the package. Activate the subscription again, or give it a bigger package.') }}
@else
{{ __('The website stays online. Contact the customer about a bigger package, or set the package to suspend websites at the limit.') }}
@endif

<x-mail::button :url="route('admin.subscriptions.index', ['q' => $subscription->website?->domain])">
{{ __('Open subscription') }}
</x-mail::button>
</x-mail::message>
