<x-layouts.client :title="__('Security').' · '.$website->domain">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('client.protection.index') }}">{{ __('Security') }}</a> / {{ $website->domain }}</div>
            <h1>{{ $website->domain }}</h1>
        </div>
        <a class="btn" href="{{ route('client.websites.show', $website) }}">{{ __('Website settings') }}</a>
    </div>
    @error('agent')<div class="alert alert-warn" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @include('security::client.ip-status')

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Protection')">
            <form method="POST" action="{{ route('client.protection.update', $website) }}" class="form">
                @csrf @method('PUT')
                <input type="hidden" name="enabled" value="0">
                <label class="check"><input type="checkbox" name="enabled" value="1" @checked($protection->enabled)><span>{{ __('Watch this website for attacks') }}</span></label>
                <p class="muted small">{{ __('Blocks scanners, exploit attempts and password guessing that show up in the logs of this website.') }}</p>
                <input type="hidden" name="wordpress" value="0">
                <label class="check"><input type="checkbox" name="wordpress" value="1" @checked($protection->wordpress)><span>{{ __('WordPress login protection') }}</span></label>
                <p class="muted small">{{ __('Blocks an address after too many failed logins on wp-login.php or xmlrpc.php. Only works while watching is on.') }}</p>
                <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Allowlist')">
            <p class="muted small">{{ __('Addresses you trust, such as your home or office. Failed logins from these addresses on this website and its mailboxes are not counted. At most :max addresses.', ['max' => $max]) }}</p>
            <form method="POST" action="{{ route('client.protection.allowlist.store', $website) }}" class="form">
                @csrf
                <div class="form-grid">
                    <x-ui.field name="address" :label="__('IP address')" :value="old('address')" :placeholder="$ip" required />
                    <x-ui.field name="comment" :label="__('Description')" :placeholder="__('Home')" />
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-dark">{{ __('Add') }}</button>
                    <button type="submit" class="btn" name="address" value="{{ $ip }}" formnovalidate>{{ __('Add my current IP (:ip)', ['ip' => $ip]) }}</button>
                </div>
            </form>
            @if ($protection->allowlist)
                <x-ui.table>
                    <x-slot:head><th>{{ __('Address') }}</th><th>{{ __('Description') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                    @foreach ($protection->allowlist as $e)
                        <tr>
                            <td class="mono">{{ $e['address'] }}</td>
                            <td>{{ $e['comment'] ?: '–' }}</td>
                            <td class="num"><form method="POST" action="{{ route('client.protection.allowlist.destroy', $website) }}">@csrf @method('DELETE')<input type="hidden" name="address" value="{{ $e['address'] }}"><button class="btn btn-sm" type="submit">{{ __('Remove') }}</button></form></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Blocked attacks, last 7 days')" flush>
        @if ($error)
            <div class="alert alert-warn" role="alert"><x-icon name="info" />{{ $error }}</div>
        @elseif (empty($activity))
            <x-ui.empty icon="shield">{{ __('No attacks on this website or its mailboxes in the last 7 days.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <x-slot:head><th>{{ __('When') }}</th><th>{{ __('Address') }}</th><th>{{ __('Country') }}</th><th>{{ __('What') }}</th><th>{{ __('Blocked') }}</th></x-slot:head>
                @foreach ($activity as $a)
                    <tr>
                        <td>{{ $a['at'] ? \Illuminate\Support\Carbon::parse($a['at'])->diffForHumans() : '–' }}</td>
                        <td class="mono">{{ $a['address'] }}</td>
                        <td>{{ $a['country'] ?? '–' }}</td>
                        <td class="small">{{ \Rhis\Security\Scenarios::label($a['scenario'] ?? '') }}</td>
                        <td>@if ($a['blocked_for'])<x-ui.pill status="bad">{{ __('Still :time', ['time' => $a['blocked_for']]) }}</x-ui.pill>@else<x-ui.pill status="neutral">{{ __('Released') }}</x-ui.pill>@endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.client>
