@php $p = \Rhis\Security\Models\SiteProtection::where('website_id', $website->id)->first(); $on = $p === null || $p->enabled; @endphp
<x-ui.card :title="__('Security')">
    <p class="muted small">{{ $on ? __('This website is watched for attacks and password guessing.') : __('Attack detection is off for this website.') }}</p>
    <div><a class="btn" href="{{ route('client.protection.show', $website) }}"><x-icon name="shield" />{{ __('Protection and allowlist') }}</a></div>
</x-ui.card>
