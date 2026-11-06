{{-- Import mail from another provider (IMAP), on the domain page. Data: $imports, $importAvailable (view composer). --}}
@php
    $bag = $errors->getBag('import');
    $presets = [
        'other' => ['label' => __('Other provider or own server'), 'host' => '', 'port' => 993, 'security' => 'ssl', 'note' => __('Use the IMAP server of your current provider, often imap.yourdomain.com or mail.yourdomain.com. A Plesk, DirectAdmin or cPanel server works too.')],
        'gmail' => ['label' => 'Gmail / Google Workspace', 'host' => 'imap.gmail.com', 'port' => 993, 'security' => 'ssl', 'note' => __('Google needs an app password (Google account > Security > App passwords) and IMAP switched on in the Gmail settings.')],
        'yahoo' => ['label' => 'Yahoo Mail', 'host' => 'imap.mail.yahoo.com', 'port' => 993, 'security' => 'ssl', 'note' => __('Yahoo needs an app password (Account security > Generate app password).')],
        'icloud' => ['label' => 'iCloud Mail', 'host' => 'imap.mail.me.com', 'port' => 993, 'security' => 'ssl', 'note' => __('Apple needs an app-specific password (appleid.apple.com > Sign-in and security).')],
        'kpn' => ['label' => 'KPN Mail', 'host' => 'imap.kpnmail.nl', 'port' => 993, 'security' => 'ssl', 'note' => ''],
        'ziggo' => ['label' => 'Ziggo', 'host' => 'imap.ziggo.nl', 'port' => 993, 'security' => 'ssl', 'note' => ''],
        'transip' => ['label' => 'TransIP', 'host' => 'imap.transip.email', 'port' => 993, 'security' => 'ssl', 'note' => ''],
    ];
    $securityLabels = ['ssl' => __('SSL/TLS (port 993)'), 'starttls' => __('STARTTLS (port 143)'), 'none' => __('None (not recommended)')];
    $testUrl = $r('imports.test');
    $num = fn ($n) => number_format((int) $n, 0, ',', app()->getLocale() === 'nl' ? '.' : ',');
@endphp

@if (! $importAvailable)
    <div class="alert alert-warn" role="status"><x-icon name="info" />
        <div><strong>{{ __('Mail import is not ready on this server yet.') }}</strong>
            <p class="small">{{ __('The next panel update installs it. Until then imports wait in the list.') }}</p></div>
    </div>
@endif

@if ($imports->isNotEmpty())
<x-ui.card :title="__('Imports')" flush>
    <x-ui.table>
        <x-slot:head><th>{{ __('From') }}</th><th>{{ __('Into') }}</th><th style="min-width:220px">{{ __('Progress') }}</th><th class="num">{{ __('Actions') }}</th></x-slot:head>
        @foreach ($imports as $import)
            <tr x-data="{ p: @js($import->toProgress()),
                    init() { if (this.p.active) this.poll() },
                    poll() { setTimeout(async () => {
                        try {
                            const res = await fetch(@js($r('imports.show', [$import->id])), { headers: { Accept: 'application/json' } });
                            if (res.ok) { const was = this.p.status; this.p = await res.json(); if (!this.p.active && was !== this.p.status) { window.location.reload(); return } }
                        } catch (e) {}
                        if (this.p.active) this.poll() }, 3000) } }">
                <td><span class="strong">{{ $import->username }}</span><br><span class="muted small">{{ $import->host }}:{{ $import->port }}</span></td>
                <td>{{ $import->mailbox->local_part . '@' . $domain->domain }}
                    <br><span class="muted small">{{ $import->started_at ? __('Started :time', ['time' => $import->started_at->diffForHumans()]) : __('Not started yet') }}@if ($import->runs > 1) · {{ trans_choice(':count run|:count runs', $import->runs) }}@endif</span></td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                        <x-ui.pill :status="$import->pill()"><span x-text="p.label">{{ $import->statusLabel() }}</span></x-ui.pill>
                        <span class="small muted" x-show="p.total" x-text="`${p.done.toLocaleString(document.documentElement.lang)} / ${(p.total ?? 0).toLocaleString(document.documentElement.lang)}`">@if ($import->total){{ $num($import->done) }} / {{ $num($import->total) }}@endif</span>
                    </div>
                    <div class="meter" x-show="p.active || p.status === 'done' || p.status === 'partial'">
                        <span class="bar" role="progressbar" aria-label="{{ __('Progress') }}" :aria-valuenow="p.percent" aria-valuemin="0" aria-valuemax="100"><span :style="`width: ${p.percent}%`" style="width: {{ $import->percent }}%"></span></span>
                        <span x-text="`${p.percent}%`">{{ $import->percent }}%</span>
                    </div>
                    @if (in_array($import->status, ['done', 'partial'], true) && $import->transferred !== null)
                        <div class="small muted">{{ __(':copied copied, :skipped already there, :errors errors', ['copied' => $num($import->transferred), 'skipped' => $num($import->skipped), 'errors' => (int) $import->errors]) }}</div>
                    @endif
                    @if ($import->error)<div class="small error" style="white-space:pre-line">{{ $import->error }}</div>@endif
                    <details style="margin-top:6px">
                        <summary class="small" style="cursor:pointer">{{ __('Log') }}</summary>
                        <div class="log" x-text="(p.log || []).join('\n') || @js(__('Nothing logged yet.'))" style="max-height:220px;margin-top:6px">{{ implode("\n", $import->log ?? []) }}</div>
                    </details>
                </td>
                <td class="num">
                    <div class="actions" style="justify-content:flex-end">
                        @if ($import->status === 'running')
                            <form method="POST" action="{{ $r('imports.stop', [$import->id]) }}" onsubmit="return confirm(@js(__('Stop this import? What was copied stays in the mailbox.')))">@csrf<button class="btn btn-ghost btn-sm" type="submit"><x-icon name="pause" />{{ __('Stop') }}</button></form>
                        @elseif (! $import->isActive())
                            <details>
                                <summary class="btn btn-ghost btn-sm"><x-icon name="refresh" />{{ __('Run again') }}</summary>
                                <form method="POST" action="{{ $r('imports.retry', [$import->id]) }}" class="form" style="margin-top:8px;text-align:left;min-width:220px">
                                    @csrf
                                    <div class="field"><label for="retry-{{ $import->id }}">{{ __('Password at :host', ['host' => $import->host]) }}</label>
                                        <input id="retry-{{ $import->id }}" type="password" name="password" class="input" autocomplete="off" required>
                                        @error('password', 'retry-' . $import->id)<span class="error">{{ $message }}</span>@enderror</div>
                                    <span class="help">{{ __('Copies only what is new since the last run.') }}</span>
                                    <button class="btn btn-dark btn-sm" type="submit">{{ __('Start') }}</button>
                                </form>
                            </details>
                        @endif
                        @if ($import->status !== 'running')
                            <form method="POST" action="{{ $r('imports.destroy', [$import->id]) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" type="submit" aria-label="{{ __('Remove from the list') }}"><x-icon name="trash" /></button></form>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
@endif

<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('Import a mailbox')">
        @if ($domain->mailboxes->isEmpty())
            <x-ui.empty icon="mail">{{ __('Create the mailbox to import into first.') }}
                <x-slot:action><a class="btn" href="{{ $r('show', ['tab' => 'mailboxes']) }}">{{ __('Mailboxes') }}</a></x-slot:action>
            </x-ui.empty>
        @else
        <form method="POST" action="{{ $r('imports.store') }}" class="form" x-ref="form"
              x-data="{ presets: @js($presets), preset: 'other', host: @js(old('host', '')), port: @js((int) old('port', 993)), security: @js(old('security', 'ssl')),
                        testing: false, test: null,
                        choose() { const p = this.presets[this.preset]; if (p.host) this.host = p.host; this.port = p.port; this.security = p.security; this.test = null },
                        secure() { this.port = this.security === 'ssl' ? 993 : 143 },
                        async check() {
                            this.testing = true; this.test = null;
                            try {
                                const res = await fetch(@js($testUrl), { method: 'POST', body: new FormData(this.$refs.form), headers: { Accept: 'application/json' } });
                                const d = await res.json();
                                this.test = { ok: res.ok && d.ok, message: d.message || Object.values(d.errors || {}).flat().join(' ') || @js(__('The test failed.')) };
                            } catch (e) { this.test = { ok: false, message: @js(__('The test failed.')) } }
                            this.testing = false } }">
            @csrf
            <div class="field">
                <label for="imp-preset">{{ __('Current provider') }}</label>
                <select id="imp-preset" class="select" x-model="preset" @change="choose()">
                    @foreach ($presets as $key => $p)<option value="{{ $key }}">{{ $p['label'] }}</option>@endforeach
                </select>
                <span class="help" x-text="presets[preset].note">{{ $presets['other']['note'] }}</span>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="imp-host">{{ __('IMAP server') }} <span aria-hidden="true">*</span></label>
                    <input id="imp-host" name="host" class="input" x-model="host" required placeholder="imap.example.com" autocomplete="off">
                    @if ($bag->has('host'))<span class="error">{{ $bag->first('host') }}</span>@endif
                </div>
                <div class="field">
                    <label for="imp-security">{{ __('Encryption') }}</label>
                    <select id="imp-security" name="security" class="select" x-model="security" @change="secure()">
                        @foreach ($securityLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="imp-user">{{ __('User name') }} <span aria-hidden="true">*</span></label>
                    <input id="imp-user" name="username" class="input" value="{{ old('username') }}" required autocomplete="off" placeholder="jan@example.com">
                    @if ($bag->has('username'))<span class="error">{{ $bag->first('username') }}</span>@endif
                </div>
                <div class="field">
                    <label for="imp-password">{{ __('Password') }} <span aria-hidden="true">*</span></label>
                    <input id="imp-password" name="password" type="password" class="input" required autocomplete="new-password">
                    @if ($bag->has('password'))<span class="error">{{ $bag->first('password') }}</span>@endif
                </div>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="imp-port">{{ __('Port') }}</label>
                    <input id="imp-port" name="port" type="number" min="1" max="65535" class="input" x-model.number="port" required>
                </div>
                <div class="field">
                    <label for="imp-box">{{ __('Import into') }} <span aria-hidden="true">*</span></label>
                    <select id="imp-box" name="mailbox_id" class="select" required>
                        @foreach ($domain->mailboxes as $box)<option value="{{ $box->id }}" @selected((int) old('mailbox_id') === $box->id)>{{ $box->local_part . '@' . $domain->domain }}</option>@endforeach
                    </select>
                    @if ($bag->has('mailbox_id'))<span class="error">{{ $bag->first('mailbox_id') }}</span>@endif
                </div>
            </div>
            <div class="alert" :class="test && test.ok ? 'alert-ok' : 'alert-bad'" role="status" x-show="test" style="display:none">
                <x-icon name="info" /><div class="small" x-text="test && test.message"></div>
            </div>
            <div class="actions">
                <button type="button" class="btn" @click="check()" :disabled="testing"><x-icon name="refresh" /><span x-text="testing ? @js(__('Testing…')) : @js(__('Test connection'))">{{ __('Test connection') }}</span></button>
                <button type="submit" class="btn btn-primary"><x-icon name="migrate" />{{ __('Start import') }}</button>
            </div>
        </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('How it works')">
        <ul class="checks">
            <li class="is-ok">{{ __('All folders are copied, with read status and dates. Sent, Drafts, Junk and Trash land in the matching folders here.') }}</li>
            <li class="is-ok">{{ __('Nothing is deleted at your current provider, and nothing that is already in the mailbox here.') }}</li>
            <li class="is-ok">{{ __('Run it again right after the switch to copy what arrived in the meantime. Messages that are already there are skipped.') }}</li>
            <li class="is-ok">{{ __('The password is only used during the import and is removed from the server afterwards.') }}</li>
            <li class="is-warn">{{ __('Microsoft 365 and Outlook.com no longer allow logging in with a password over IMAP, so they cannot be imported this way.') }}</li>
            <li class="is-warn">{{ __('Mind the storage of the mailbox: messages that do not fit are skipped and shown as errors.') }}</li>
        </ul>
    </x-ui.card>
</section>
