{{-- Website mail of one WordPress install (admin and client): sender, deliverability, test message, recent mail. --}}
@include('wordpress::mail.partials.styles')
@php
    $r = fn ($name) => route($area.'.wordpress.'.$name, $install);
    $on = (bool) ($status['enabled'] ?? $setting->enabled);
    $staging = (bool) ($status['staging'] ?? false);
    [$local, $domain] = array_pad(explode('@', $sender, 2), 2, '');
    $dkim = ($status['dkim'][$senderDomain] ?? null) ?? ($check['dkim'] ?? false);
    $smtp = (bool) ($status['smtp'] ?? false);
    $mark = fn (?bool $ok) => $ok === null ? ['is-neutral', '–'] : ($ok ? ['is-ok', '✓'] : ['is-warn', '!']);
@endphp
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / <a href="{{ $r('show') }}">{{ $install->label() }}</a> / {{ __('Website mail') }}</div>
        <div class="actions"><h1>{{ __('Website mail') }}</h1>
            @if ($staging)<x-ui.pill status="warn">{{ __('Held back (staging)') }}</x-ui.pill>
            @elseif ($on)<x-ui.pill status="ok">{{ __('Through this server') }}</x-ui.pill>
            @else<x-ui.pill status="neutral">{{ __('Not through this server') }}</x-ui.pill>@endif
        </div>
        <p class="sub">{{ __('Contact forms, web shop orders and password mails of :site.', ['site' => $install->label()]) }}</p>
    </div>
</div>

@if (session('status'))<div class="alert alert-ok" role="status"><x-icon name="check" />{{ session('status') }}</div>@endif
@foreach ($errors->all() as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
@if ($agentError)<div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('The server could not be asked how this website sends mail: :error', ['error' => $agentError]) }}</div>@endif
@if ($staging)
    <div class="alert alert-warn" role="status"><x-icon name="info" /><div><strong>{{ __('This is a staging copy') }}</strong>
        <p class="small">{{ __('Mail from a staging copy is held back, so customers and orders on the copy never mail real people. It stays that way; the live website sends its own mail.') }}</p></div></div>
@endif
@if (! empty($status['copied']))
    <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('The mail settings in this website were copied from another website and do nothing here. Save below to set this website up.') }}</div>
@endif
@if ($smtpPlugins->isNotEmpty())
    <div class="alert alert-warn" role="status"><x-icon name="info" /><div><strong>{{ __('A mail plugin is active: :plugins', ['plugins' => $smtpPlugins->pluck('title')->implode(', ')]) }}</strong>
        <p class="small">{{ $on ? __('Mail that WordPress sends goes through this server anyway. You can deactivate the plugin.') : __('This website may send through Microsoft 365 or a mail service on purpose, so it was not switched over automatically.') }}</p></div></div>
@endif

<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('Sending')">
        <p class="muted small">{{ __('WordPress hands every message to the mail server of this server. It is sent from an address of this website and signed with DKIM, so it lands in the inbox instead of the spam folder. Also when WP-cron runs on the server.') }}</p>
        @if ($canEdit && ! $staging)
        <form method="POST" action="{{ $r('mail.update') }}" class="form" x-data="{ on: @js((bool) old('enabled', $on)) }">
            @csrf @method('PUT')
            <div class="seg" role="radiogroup" aria-label="{{ __('Website mail') }}">
                <label><input type="radio" name="enabled" value="1" x-model.boolean="on" x-bind:value="1" @checked(old('enabled', $on))><span>{{ __('Through this server (recommended)') }}</span></label>
                <label><input type="radio" name="enabled" value="0" x-model.boolean="on" @checked(! old('enabled', $on))><span>{{ __('Leave it to WordPress') }}</span></label>
            </div>
            <div x-show="on" class="form">
                <div class="wpm-from">
                    <x-ui.field name="from_local" :label="__('Sender address')" :value="$local" autocomplete="off" spellcheck="false" maxlength="64" />
                    <span class="wpm-at" aria-hidden="true">@</span>
                    <x-ui.field name="from_domain" type="select" :label="__('Domain')">
                        @foreach ($domains as $d)<option value="{{ $d }}" @selected(old('from_domain', $domain) === $d)>{{ $d }}</option>@endforeach
                    </x-ui.field>
                </div>
                <x-ui.field name="from_name" :label="__('Sender name')" :value="$setting->from_name" :placeholder="__('Name of the website')" maxlength="120"
                    :help="__('Used when a plugin does not give a name of its own.')" />
                <input type="hidden" name="force_from" value="0">
                <label class="check"><input type="checkbox" name="force_from" value="1" @checked(old('force_from', $setting->force_from))>
                    <span>{{ __('Always send from this website') }}<small>{{ __('A form that sends "from" the visitor is sent from the address above, with the visitor as reply address. Mail from other domains would fail SPF and DMARC.') }}</small></span></label>
            </div>
            <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
        </form>
        @else
            <dl class="kv"><dt>{{ __('Sender address') }}</dt><dd class="mono">{{ $on ? $sender : '–' }}</dd></dl>
        @endif
        <div class="wpm-route" aria-label="{{ __('Route of a message') }}">
            <span>WordPress</span><i>→</i><span>{{ $on ? __('Mail server :port', ['port' => $status['port'] ?? 10587]) : 'PHP mail()' }}</span><i>→</i><span>{{ $on && $dkim ? 'DKIM' : __('no DKIM') }}</span><i>→</i><span>{{ __('Recipient') }}</span>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Deliverability of :domain', ['domain' => $senderDomain])">
        <ul class="wpm-checks">
            @php [$c, $i] = $mark($status ? $smtp : null); @endphp
            <li class="{{ $c }}"><span class="wpm-ico" aria-hidden="true">{{ $i }}</span><div><strong>{{ __('Mail server accepts website mail') }}</strong>
                <p class="small muted">{{ $smtp ? __('SMTP on 127.0.0.1, only reachable from this server.') : __('Not available yet: update the server from Info & updates.') }}</p></div></li>
            @php [$c, $i] = $mark((bool) $dkim); @endphp
            <li class="{{ $c }}"><span class="wpm-ico" aria-hidden="true">{{ $i }}</span><div><strong>{{ __('DKIM key') }}</strong>
                <p class="small muted">{{ $dkim ? __('Messages from :domain are signed (selector whp).', ['domain' => $senderDomain]) : __('No key for :domain yet: messages go out unsigned.', ['domain' => $senderDomain]) }}</p></div></li>
            @if ($check !== null)
                @php [$c, $i] = $mark($check['spf']); @endphp
                <li class="{{ $c }}"><span class="wpm-ico" aria-hidden="true">{{ $i }}</span><div><strong>{{ __('This server in SPF') }}</strong>
                    <p class="small muted">{{ $check['spf'] ? __('SPF allows this server to send for :domain.', ['domain' => $senderDomain]) : __('Turn on "Websites on this server send mail for this domain" in the mail settings of :domain.', ['domain' => $senderDomain]) }}</p></div></li>
                @php [$c, $i] = $mark(($check['dns_managed'] ?? false) ? $check['dns'] === 'ok' : null); @endphp
                <li class="{{ $c }}"><span class="wpm-ico" aria-hidden="true">{{ $i }}</span><div><strong>{{ __('DNS records') }}</strong>
                    <p class="small muted">{{ ($check['dns_managed'] ?? false) ? ($check['dns'] === 'ok' ? __('DKIM and SPF are published in DNS.') : __('Not all records are published yet. Check the DNS tab.')) : __('DNS is managed elsewhere: publish the DKIM and SPF records shown in the mail settings.') }}</p></div></li>
            @endif
        </ul>
        @if ($siteMailUrl)<p style="margin-top:12px"><a class="btn btn-sm" href="{{ $siteMailUrl }}"><x-icon name="settings" />{{ __('Mail settings of the domain') }}</a></p>@endif
    </x-ui.card>
</section>

<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('Send a test message')">
        @if ($staging)
            <p class="muted small">{{ __('A staging copy does not send mail.') }}</p>
        @elseif ($canEdit)
        <form method="POST" action="{{ $r('mail.test') }}" class="form">
            @csrf
            <x-ui.field name="to" type="email" :label="__('Send to')" :value="old('to', $setting->last_test_to ?: $defaultTo)" required autocomplete="email" />
            <p class="small muted">{{ __('WordPress sends it with its own mail function, with all plugins active: the same route a contact form takes.') }}</p>
            <div><x-ui.button variant="primary" icon="send">{{ __('Send test message') }}</x-ui.button></div>
        </form>
        @endif
        @if ($setting->last_test_at)
            <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
            <p class="small"><strong>{{ __('Last test') }}</strong> {{ $setting->last_test_at->diffForHumans() }} · {{ $setting->last_test_to }}
                <x-ui.pill :status="$setting->last_test_ok ? 'ok' : 'bad'">{{ $setting->last_test_ok ? __('Sent') : __('Failed') }}</x-ui.pill></p>
            @if ($setting->last_test_queue_id)<p class="small muted">{{ __('Queue ID') }} <span class="mono">{{ $setting->last_test_queue_id }}</span>@if ($area === 'admin' && Route::has('admin.mail.log.index')) · <a href="{{ route('admin.mail.log.index', ['q' => $setting->last_test_queue_id]) }}">{{ __('Find in the mail log') }}</a>@endif</p>@endif
            @if ($setting->last_test_error)<p class="small mono">{{ $setting->last_test_error }}</p>@endif
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Recent mail')" flush>
        @php $log = $status['log'] ?? []; @endphp
        @if (empty($log))
            <x-ui.empty icon="mail">{{ $on ? __('Nothing sent yet since website mail was switched on.') : __('Shown once WordPress sends through this server.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('When') }}</th><th>{{ __('To') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Status') }}</th></x-slot:head>
            @foreach ($log as $m)
                <tr>
                    <td class="small" style="white-space:nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($m['time'])->diffForHumans() }}</td>
                    <td class="small">{{ implode(', ', $m['to']) ?: '–' }}</td>
                    <td class="small">{{ $m['subject'] ?: '–' }}@if ($m['error'])<div class="muted mono">{{ $m['error'] }}</div>@endif</td>
                    <td><x-ui.pill :status="$m['ok'] ? 'ok' : 'bad'">{{ $m['ok'] ? __('Sent') : __('Failed') }}</x-ui.pill></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</section>
