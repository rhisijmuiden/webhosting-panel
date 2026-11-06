@php
    $labels = [
        'ssh' => [__('SSH and SFTP'), __('Logins on the SSH port, including SFTP accounts of websites.')],
        'mail' => [__('Mail (IMAP, POP3, SMTP)'), __('Failed mailbox logins in mail apps and when sending mail.')],
        'panel' => [__('Control panel'), __('Failed logins and two-factor codes on this panel.')],
        'wordpress' => [__('WordPress'), __('Failed logins on wp-login.php and xmlrpc.php of every WordPress site. Customers can switch it off per site.')],
    ];
@endphp
<x-layouts.admin :title="__('Login protection')">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.security.index') }}">{{ __('Security') }}</a> / {{ __('Login protection') }}</div>
            <h1>{{ __('Login protection') }}</h1>
            <p class="sub">{{ __('An address that fails to log in too often is blocked on every port of this server: first for a while, and permanently when it keeps trying.') }}</p>
        </div>
        <form method="POST" action="{{ route('admin.protection.apply') }}">@csrf<button class="btn" type="submit"><x-icon name="refresh" />{{ __('Send to server again') }}</button></form>
    </div>

    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @if ($last && ! $last['ok'] && ! $errors->has('agent'))
        <div class="alert alert-warn" role="alert"><x-icon name="info" />{{ __('The last change did not reach the server (:at): :error', ['at' => \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans(), 'error' => $last['error'] ?? '']) }}</div>
    @endif

    <form method="POST" action="{{ route('admin.protection.update') }}" class="form">
        @csrf @method('PUT')
        <x-ui.card :title="__('Thresholds per service')" flush>
            <x-ui.table>
                <x-slot:head>
                    <th>{{ __('Service') }}</th><th>{{ __('On') }}</th>
                    <th class="num">{{ __('Temporary block after') }}</th><th class="num">{{ __('Within (minutes)') }}</th><th class="num">{{ __('Block for (minutes)') }}</th>
                    <th class="num">{{ __('Permanent block after') }}</th><th class="num">{{ __('Within (hours)') }}</th>
                </x-slot:head>
                @foreach ($services as $name => $s)
                    @php $old = fn ($k) => old("services.$name.$k", $s[$k]); $num = fn ($k, $min, $max) => '<input class="input" style="width:6.5rem;text-align:right" type="number" min="'.$min.'" max="'.$max.'" required name="services['.$name.']['.$k.']" value="'.e($old($k)).'" aria-label="'.e($labels[$name][0].' '.$k).'"'.($errors->has("services.$name.$k") ? ' aria-invalid="true"' : '').'>'; @endphp
                    <tr>
                        <td><strong>{{ $labels[$name][0] }}</strong><br><span class="muted small">{{ $labels[$name][1] }}</span>
                            @foreach (['temp_after', 'window_minutes', 'ban_minutes', 'perm_after', 'perm_window_hours'] as $k)@error("services.$name.$k")<br><span class="error small">{{ $message }}</span>@enderror @endforeach</td>
                        <td><input type="hidden" name="services[{{ $name }}][enabled]" value="0">
                            <label class="check"><input type="checkbox" name="services[{{ $name }}][enabled]" value="1" @checked($old('enabled'))><span class="sr-only">{{ __('On') }}</span></label></td>
                        <td class="num">{!! $num('temp_after', 1, 1000) !!}</td>
                        <td class="num">{!! $num('window_minutes', 1, 1440) !!}</td>
                        <td class="num">{!! $num('ban_minutes', 1, 525600) !!}</td>
                        <td class="num">{!! $num('perm_after', 0, 10000) !!}</td>
                        <td class="num">{!! $num('perm_window_hours', 1, 720) !!}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <p class="muted small" style="padding:0 1.25rem 1rem">{{ __('Numbers are failed logins from one address. A temporary block is released automatically. Permanent block after 0 = never.') }}</p>
        </x-ui.card>
        <x-ui.card :title="__('Length of a permanent block')">
            <div class="form-grid">
                <x-ui.field type="number" min="0" max="3650" name="permanent_days" :value="old('permanent_days', $permanentDays)" :label="__('Days')" :help="__('0 = until you unblock it yourself.')" required />
            </div>
            <p class="muted small">{{ __('Blocked addresses are listed on the Security page, where you can unblock them.') }} <a href="{{ route('admin.security.index') }}">{{ __('Go to Security') }}</a></p>
            <div><button type="submit" class="btn btn-dark">{{ __('Save and apply') }}</button></div>
        </x-ui.card>
    </form>

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Allowlist for the whole server')">
            <p class="muted small">{{ __('These addresses are never blocked: not by login protection, not by attack detection and not by the shared CrowdSec blocklist. Use it for your own office and monitoring.') }}</p>
            <form method="POST" action="{{ route('admin.protection.allowlist.store') }}" class="form">
                @csrf
                <div class="form-grid">
                    <x-ui.field name="address" :label="__('IP address or range')" :placeholder="request()->ip()" required />
                    <x-ui.field name="comment" :label="__('Description')" :placeholder="__('Office')" />
                </div>
                <div><button type="submit" class="btn btn-dark">{{ __('Add') }}</button>
                    <span class="muted small">{{ __('Your current IP address:') }} <code>{{ request()->ip() }}</code></span></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Allowlisted')" flush>
            @if (empty($allowlist))
                <x-ui.empty icon="shield">{{ __('Nothing is allowlisted.') }}</x-ui.empty>
            @else
                <x-ui.table>
                    <x-slot:head><th>{{ __('Address') }}</th><th>{{ __('Description') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                    @foreach ($allowlist as $e)
                        <tr>
                            <td class="mono">{{ $e['address'] }}</td>
                            <td>{{ $e['comment'] ?: '–' }}</td>
                            <td class="num"><form method="POST" action="{{ route('admin.protection.allowlist.destroy') }}">@csrf @method('DELETE')<input type="hidden" name="address" value="{{ $e['address'] }}"><button class="btn btn-sm" type="submit">{{ __('Remove') }}</button></form></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Websites with their own settings')" flush>
        @if ($sites->isEmpty())
            <x-ui.empty icon="globe">{{ __('All websites use the full protection.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Protection') }}</th><th>{{ __('WordPress logins') }}</th><th>{{ __('Allowlisted') }}</th></x-slot:head>
                @foreach ($sites as $p)
                    <tr>
                        <td><a href="{{ route('admin.websites.show', $p->website) }}">{{ $p->website->domain }}</a></td>
                        <td>{{ $p->website->customer?->name ?? '–' }}</td>
                        <td><x-ui.pill :status="$p->enabled ? 'ok' : 'warn'">{{ $p->enabled ? __('On') : __('Off') }}</x-ui.pill></td>
                        <td><x-ui.pill :status="$p->enabled && $p->wordpress ? 'ok' : 'warn'">{{ $p->enabled && $p->wordpress ? __('On') : __('Off') }}</x-ui.pill></td>
                        <td class="mono small">{{ collect($p->allowlist ?? [])->pluck('address')->implode(', ') ?: '–' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.admin>
