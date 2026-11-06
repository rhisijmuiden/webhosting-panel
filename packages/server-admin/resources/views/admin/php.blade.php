<x-layouts.admin :title="__('PHP versions')">
    @include('server-admin::partials.styles')
    <script src="{{ route('server-admin.assets.js') }}" defer></script>
    <div class="page-head">
        <div><p class="crumbs"><a href="{{ route('admin.server.index') }}">{{ __('Server management') }}</a></p>
            <h1>{{ __('PHP versions') }}</h1>
            <p class="sub">{{ __('Which PHP versions the server has, until when they get security updates, and what happens after that.') }}</p></div>
        <div class="actions">
            <x-ui.button :href="route('admin.server.maintenance')" icon="settings">{{ __('Settings') }}</x-ui.button>
        </div>
    </div>
    @include('server-admin::partials.tabs')

    @if (! $o->get('php_eol_enabled'))
        <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('Automatic moving is off. Websites stay on versions without security support until you move them.') }}</div>
    @endif

    <x-ui.card :title="__('Versions')" flush>
        <x-ui.table>
            <x-slot:head><th>PHP</th><th>{{ __('Websites') }}</th><th>{{ __('Security support until') }}</th><th>{{ __('Status') }}</th><th>{{ __('Then') }}</th><th></th></x-slot:head>
            @foreach ($rows as $r)
                <tr>
                    <td class="strong">{{ $r['version'] }}@if ($r['panel']) <x-ui.pill status="info" :dot="false">{{ __('Panel') }}</x-ui.pill>@endif
                        @unless ($r['installed'])<div class="muted small">{{ __('Not installed') }}</div>@endunless</td>
                    <td>{{ $r['sites'] }}</td>
                    <td>{{ $r['eol']?->isoFormat('LL') ?? __('Unknown') }}
                        @if ($r['days_left'] !== null && $r['days_left'] >= 0)<div class="muted small">{{ trans_choice(':count day left|:count days left', $r['days_left']) }}</div>@endif</td>
                    <td>@switch($r['state'])
                            @case('eol')<x-ui.pill status="bad">{{ __('No security updates') }}</x-ui.pill>@break
                            @case('ending')<x-ui.pill status="warn">{{ __('Ending soon') }}</x-ui.pill>@break
                            @case('supported')<x-ui.pill status="ok">{{ __('Supported') }}</x-ui.pill>@break
                            @default<x-ui.pill status="neutral">{{ __('Unknown') }}</x-ui.pill>
                        @endswitch</td>
                    <td class="small">@if ($r['state'] === 'supported' || ! $r['eol'])<span class="muted">–</span>
                        @elseif ($r['target'])
                            {{ __('Websites move to PHP :to on :date', ['to' => $r['target'], 'date' => $r['migrate_at']->isoFormat('LL')]) }}
                        @else
                            {{ __('A newer version is installed first') }}
                        @endif</td>
                    <td class="actions" style="justify-content:flex-end">
                        @if ($r['sites'] > 0 && $r['target'])
                            <form method="POST" action="{{ route('admin.server.php.migrate') }}">@csrf<input type="hidden" name="version" value="{{ $r['version'] }}">
                                <x-ui.button size="sm">{{ __('Move to :v now', ['v' => $r['target']]) }}</x-ui.button></form>
                        @endif
                        @if ($r['installed'] && $r['sites'] === 0 && ! $r['panel'])
                            <form method="POST" action="{{ route('admin.server.php.remove') }}">@csrf<input type="hidden" name="version" value="{{ $r['version'] }}">
                                <x-ui.button size="sm" data-confirm="{{ __('Remove PHP :v from the server?', ['v' => $r['version']]) }}">{{ __('Remove') }}</x-ui.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @php $missing = array_values(array_diff($facts['supported'] ?? [], $facts['installed'] ?? [])); @endphp
    @if ($missing)
        <x-ui.card :title="__('Install a PHP version')">
            <p class="small muted">{{ __('Installs the packages, the extensions the panel uses and its settings, with the same installer as a panel update.') }}</p>
            <div class="actions">
                @foreach ($missing as $v)
                    <form method="POST" action="{{ route('admin.server.php.install') }}">@csrf<input type="hidden" name="version" value="{{ $v }}">
                        <x-ui.button size="sm" icon="plus">PHP {{ $v }}</x-ui.button></form>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    @if ($failed)
        <x-ui.card :title="__('Websites that broke on a newer version')" flush>
            <x-ui.table>
                <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Tried') }}</th><th></th></x-slot:head>
                @foreach ($failed as $domain => $to)
                    <tr><td class="strong">{{ $domain }}</td><td>PHP {{ $to }}</td>
                        <td class="actions" style="justify-content:flex-end"><form method="POST" action="{{ route('admin.server.php.retry') }}">@csrf<input type="hidden" name="domain" value="{{ $domain }}">
                            <x-ui.button size="sm">{{ __('Try again next run') }}</x-ui.button></form></td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('History')" flush>
        @include('server-admin::partials.runs', ['runs' => $runs])
    </x-ui.card>
</x-layouts.admin>
