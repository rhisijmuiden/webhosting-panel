<x-layouts.admin :title="__('Services')">
    <div class="page-head"><div><h1>{{ __('Services') }}</h1><p class="sub">{{ __('The software that runs your websites and mail.') }}</p></div></div>
    @if ($error)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $error }}</div>@endif
    <x-ui.card flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Service') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Status') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($services as $s)
                <tr>
                    <td><strong>{{ $s['label'] }}</strong></td>
                    <td class="mono small">{{ $s['service'] }}</td>
                    <td>@if ($s['active'])<x-ui.pill status="ok">{{ __('Running') }}</x-ui.pill>@else<x-ui.pill status="bad">{{ __('Stopped') }} ({{ $s['state'] }})</x-ui.pill>@endif</td>
                    <td class="num">
                        @unless ($s['service'] === 'nftables')
                        <form method="POST" action="{{ route('admin.services.restart', $s['service']) }}" data-confirm="{{ __('Restart :service?', ['service' => $s['label']]) }}">@csrf
                            <button class="btn btn-sm" type="submit"><x-icon name="refresh" />{{ __('Restart') }}</button></form>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.admin>
