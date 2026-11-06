<x-mail::message>
# {{ __('Data traffic limit reached') }}

{{ __(':customer has used :used of data traffic this month. The package :package allows :limit.', [
    'customer' => $customer->displayName(),
    'used' => \Illuminate\Support\Number::fileSize($usedBytes, 1),
    'package' => $customer->package->name,
    'limit' => $customer->package->limitLabel('traffic_gb_month'),
]) }}

@if ($suspended)
{{ __('The websites of this customer are suspended, as set in the package. Lift the suspension on the customer page, or give the customer a bigger package.') }}
@else
{{ __('The websites stay online. Contact the customer about a bigger package, or set the package to suspend websites at the limit.') }}
@endif

<x-mail::button :url="route('admin.customers.show', $customer)">
{{ __('Open customer') }}
</x-mail::button>
</x-mail::message>
