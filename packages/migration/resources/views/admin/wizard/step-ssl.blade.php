@if ($current['applicable'])
<x-ui.card :title="__('Certificate')">
    <dl class="kv">
        <dt>{{ __('Now') }}</dt><dd>@if ($item->result['ssl']['installed'] ?? false){{ __('Certificate of the old server is in use until Let\'s Encrypt takes over.') }}@else{{ __(ucfirst((string) $item->website?->ssl_status)) }}@endif</dd>
        <dt>{{ __('After the switch') }}</dt><dd>{{ __('Let\'s Encrypt for :domain and its aliases, as soon as DNS reaches this server.', ['domain' => $item->domain]) }}</dd>
    </dl>
</x-ui.card>
@endif
