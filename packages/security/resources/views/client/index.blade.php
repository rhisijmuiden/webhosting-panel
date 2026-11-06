<x-layouts.client :title="__('Security')">
    <div class="page-head"><div><h1>{{ __('Security') }}</h1>
        <p class="sub">{{ __('The server watches the logs of your websites and mailboxes. An address that keeps guessing passwords is blocked automatically.') }}</p></div></div>
    @include('security::client.ip-status')
    <x-ui.card flush>
        @if ($websites->isEmpty())
            <x-ui.empty icon="globe">{{ __('You have no websites yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Protection') }}</th><th>{{ __('WordPress logins') }}</th><th>{{ __('Allowlisted') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($websites as $w)
                @php $p = $protections[$w->id] ?? null; $on = $p === null || $p->enabled; $wp = $on && ($p === null || $p->wordpress); @endphp
                <tr>
                    <td><a href="{{ route('client.protection.show', $w) }}">{{ $w->domain }}</a></td>
                    <td><x-ui.pill :status="$on ? 'ok' : 'warn'">{{ $on ? __('On') : __('Off') }}</x-ui.pill></td>
                    <td><x-ui.pill :status="$wp ? 'ok' : 'warn'">{{ $wp ? __('On') : __('Off') }}</x-ui.pill></td>
                    <td>{{ count($p?->allowlist ?? []) }}</td>
                    <td class="num"><a class="btn btn-sm" href="{{ route('client.protection.show', $w) }}">{{ __('Manage') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.client>
