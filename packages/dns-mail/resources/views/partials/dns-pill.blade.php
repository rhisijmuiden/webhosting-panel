@php
$labels = ['in_sync' => __('Up to date'), 'changes' => __('Changes waiting'), 'conflict' => __('Needs attention'),
    'error' => __('Error'), 'no_zone' => __('Not at Cloudflare'), 'unknown' => __('Not checked'), 'missing' => __('Records missing')];
$onb = $domain->relationLoaded('onboarding') ? $domain->onboarding : null;
@endphp
@if ($onb?->isPending())
    <x-ui.pill status="warn">{{ __('Waiting for nameservers') }}</x-ui.pill>
@elseif (! $domain->dns_managed)
    @if ($domain->dns_status === 'in_sync')<x-ui.pill status="ok">{{ __('Own DNS, all set') }}</x-ui.pill>
    @elseif ($domain->dns_status === 'missing')<x-ui.pill status="warn">{{ __('Own DNS, records missing') }}</x-ui.pill>
    @else<x-ui.pill status="neutral">{{ __('Managed elsewhere') }}</x-ui.pill>@endif
@else
    <x-ui.pill :status="$domain->dnsPill()">{{ $labels[$domain->dns_status] ?? $domain->dns_status }}</x-ui.pill>
@endif
