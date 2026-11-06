@php
$labels = ['in_sync' => __('Up to date'), 'changes' => __('Changes waiting'), 'conflict' => __('Needs attention'),
    'error' => __('Error'), 'no_zone' => __('Not at Cloudflare'), 'unknown' => __('Not checked')];
@endphp
@if (! $domain->dns_managed)
    <x-ui.pill status="neutral">{{ __('Managed elsewhere') }}</x-ui.pill>
@else
    <x-ui.pill :status="$domain->dnsPill()">{{ $labels[$domain->dns_status] ?? $domain->dns_status }}</x-ui.pill>
@endif
