<x-layouts.admin :title="__('Firewall')">
    <div class="page-head"><div><h1>{{ __('Firewall') }}</h1><p class="sub">{{ __('nftables. Everything that is not open below is blocked.') }}</p></div></div>
    @if ($error)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $error }}</div>@endif
    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Add a rule')">
            <form method="POST" action="{{ route('admin.firewall.store') }}" class="form" x-data="{ type: @js(old('type', 'open_port')) }">
                @csrf
                <div class="seg" role="radiogroup" aria-label="{{ __('Rule type') }}">
                    <label><input type="radio" name="type" value="open_port" x-model="type"><span>{{ __('Open a port') }}</span></label>
                    <label><input type="radio" name="type" value="block_ip" x-model="type"><span>{{ __('Block an address') }}</span></label>
                </div>
                <div class="form-grid" x-show="type === 'open_port'">
                    <x-ui.field name="port" type="number" min="1" max="65535" :label="__('Port')" placeholder="3306" />
                    <x-ui.field name="protocol" type="select" :label="__('Protocol')"><option value="tcp">TCP</option><option value="udp">UDP</option></x-ui.field>
                </div>
                <div x-show="type === 'block_ip'" x-cloak><x-ui.field name="address" :label="__('IP address or range')" placeholder="198.51.100.0/24" /></div>
                <x-ui.field name="comment" :label="__('Comment')" />
                <div><button type="submit" class="btn btn-dark">{{ __('Add rule') }}</button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Always open')">
            <p class="muted small">{{ __('These ports are opened by the installer and cannot be closed from the panel.') }}</p>
            <div class="actions">@foreach ($protected as $p)<span class="pill pill-neutral">TCP {{ $p }}</span>@endforeach</div>
        </x-ui.card>
    </section>
    <x-ui.card :title="__('Your rules')" flush>
        @if (empty($rules))
            <x-ui.empty icon="firewall">{{ __('No extra rules.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Rule') }}</th><th>{{ __('Comment') }}</th><th>{{ __('Added') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($rules as $r)
                <tr>
                    <td>@if ($r['type'] === 'open_port')<x-ui.pill status="ok">{{ __('Open') }}</x-ui.pill> <span class="mono">{{ strtoupper($r['protocol']) }} {{ $r['port'] }}</span>@else<x-ui.pill status="bad">{{ __('Blocked') }}</x-ui.pill> <span class="mono">{{ $r['address'] }}</span>@endif</td>
                    <td>{{ $r['comment'] ?: '–' }}</td>
                    <td class="small muted">{{ isset($r['created_at']) ? \Illuminate\Support\Carbon::createFromTimestamp($r['created_at'])->diffForHumans() : '–' }}</td>
                    <td class="num"><form method="POST" action="{{ route('admin.firewall.destroy', $r['id']) }}" data-confirm="{{ __('Remove this rule?') }}">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" type="submit" aria-label="{{ __('Remove') }}"><x-icon name="trash" /></button></form></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.admin>
