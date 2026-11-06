<x-layouts.admin :title="__('Security')">
    <div class="page-head"><div><h1>{{ __('Security') }}</h1><p class="sub">{{ __('CrowdSec watches the logs and blocks attackers on SSH, mail, the panel and WordPress logins.') }}</p></div></div>
    @if ($error)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $error }}</div>@endif
    <section class="grid grid-stats">
        <div class="card stat"><span class="label">{{ __('Alerts, last 24 hours') }}</span><strong class="value">{{ $metrics['alerts_24h'] ?? '–' }}</strong></div>
        <div class="card stat"><span class="label">{{ __('Active blocks') }}</span><strong class="value">{{ count($decisions) }}</strong><span class="hint">{{ __('Including the shared CrowdSec blocklist') }}</span></div>
    </section>
    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Block an IP address')">
            <form method="POST" action="{{ route('admin.security.ban') }}" class="form">
                @csrf
                <div class="form-grid">
                    <x-ui.field name="address" :label="__('IP address or range')" placeholder="203.0.113.7" required />
                    <x-ui.field name="duration" type="select" :label="__('Duration')">
                        @foreach (['1h' => __('1 hour'), '4h' => __('4 hours'), '24h' => __('1 day'), '7d' => __('1 week'), '30d' => __('30 days'), '365d' => __('1 year')] as $v => $l)
                            <option value="{{ $v }}" @selected(old('duration', '24h') === $v)>{{ $l }}</option>
                        @endforeach
                    </x-ui.field>
                </div>
                <x-ui.field name="reason" :label="__('Reason')" />
                <div><button type="submit" class="btn btn-dark">{{ __('Block') }}</button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Tip')">
            <p class="muted small">{{ __('Blocked yourself? Unblock your IP below, or from the console with: cscli decisions delete --ip <address>') }}</p>
            <p class="small">{{ __('Your current IP address:') }} <code>{{ request()->ip() }}</code></p>
        </x-ui.card>
    </section>
    <x-ui.card :title="__('Blocked now')" flush>
        @if (empty($decisions))
            <x-ui.empty icon="shield">{{ __('Nobody is blocked right now.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Address') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Source') }}</th><th>{{ __('Country') }}</th><th>{{ __('Time left') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($decisions as $d)
                <tr>
                    <td class="mono">{{ $d['value'] }}</td>
                    <td class="small">{{ $d['scenario'] }}</td>
                    <td>{{ $d['origin'] }}</td>
                    <td>{{ $d['country'] ?? '–' }}</td>
                    <td>{{ $d['duration'] }}</td>
                    <td class="num"><form method="POST" action="{{ route('admin.security.unban') }}">@csrf<input type="hidden" name="address" value="{{ $d['value'] }}"><button class="btn btn-sm" type="submit">{{ __('Unblock') }}</button></form></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.admin>
