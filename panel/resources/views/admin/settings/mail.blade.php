<x-layouts.admin :title="__('Mail server (SMTP)')">
    <div class="page-head"><div><h1>{{ __('Mail server (SMTP)') }}</h1><p class="sub">{{ __('The server the panel sends its own e-mail through: notifications, password resets and reports.') }}</p></div></div>
    <form method="POST" action="{{ route('admin.settings.mail.update') }}" class="form" x-data="{ enabled: @js((bool) old('enabled', $settings['enabled'])), enc: @js(old('encryption', $settings['encryption'])), port: @js((string) old('port', $settings['port'])) }">
        @csrf @method('PUT')
        <x-ui.card>
            <div class="seg" role="radiogroup" aria-label="{{ __('Mail route') }}">
                <label><input type="radio" name="enabled" value="0" x-model.boolean="enabled" :checked="!enabled"><span>{{ __('Local mail server') }}</span></label>
                <label><input type="radio" name="enabled" value="1" x-model.boolean="enabled" :checked="enabled"><span>{{ __('Custom SMTP server') }}</span></label>
            </div>
            <p class="muted small" x-show="!enabled">{{ __('Mail goes to Postfix on this server, which sends it on through the relay host set during installation.') }}</p>
            <div x-show="enabled" x-cloak class="form">
                <div class="form-grid">
                    <x-ui.field name="host" :label="__('SMTP host')" :value="$settings['host']" placeholder="smtp.example.com" />
                    <x-ui.field name="port" type="number" min="1" max="65535" :label="__('Port')" :value="$settings['port']" x-model="port" />
                </div>
                <div class="field">
                    <span class="label">{{ __('Encryption') }}</span>
                    <div class="seg" role="radiogroup" aria-label="{{ __('Encryption') }}">
                        <label><input type="radio" name="encryption" value="starttls" x-model="enc" @change="if (port === '465' || port === '25') port = '587'"><span>STARTTLS</span></label>
                        <label><input type="radio" name="encryption" value="tls" x-model="enc" @change="port = '465'"><span>SSL/TLS</span></label>
                        <label><input type="radio" name="encryption" value="none" x-model="enc"><span>{{ __('None') }}</span></label>
                    </div>
                    <span class="help" x-show="enc === 'starttls'">{{ __('Port 587. The connection must switch to TLS before anything is sent.') }}</span>
                    <span class="help" x-show="enc === 'tls'">{{ __('Port 465. Encrypted from the first byte (implicit TLS).') }}</span>
                    <span class="help" x-show="enc === 'none'" style="color:var(--status-warning)">{{ __('Not encrypted. Only use this for a server on the same network.') }}</span>
                </div>
                <div class="form-grid">
                    <x-ui.field name="username" :label="__('Username')" :value="$settings['username']" autocomplete="off" />
                    <x-ui.field name="password" type="password" :label="__('Password')" autocomplete="new-password" :help="$settings['has_password'] ? __('A password is saved. Leave empty to keep it.') : __('Stored encrypted.')" />
                </div>
                @if ($settings['has_password'])<label class="check"><input type="checkbox" name="clear_password" value="1"><span>{{ __('Remove the saved password') }}</span></label>@endif
                <details>
                    <summary class="small" style="cursor:pointer;font-weight:600">{{ __('Advanced') }}</summary>
                    <div class="form-grid" style="margin-top:14px">
                        <x-ui.field name="ehlo_domain" :label="__('EHLO name')" :value="$settings['ehlo_domain']" :help="__('The name this server uses to introduce itself. Empty: the hostname.')" />
                        <x-ui.field name="timeout" type="number" min="3" max="120" :label="__('Timeout (seconds)')" :value="$settings['timeout']" />
                    </div>
                    <input type="hidden" name="verify_peer" value="0">
                    <label class="check" style="margin-top:12px"><input type="checkbox" name="verify_peer" value="1" @checked(old('verify_peer', $settings['verify_peer']))><span>{{ __('Verify the server certificate') }}<small>{{ __('Keep this on. Only turn it off for a server with a self-signed certificate.') }}</small></span></label>
                </details>
            </div>
            <input type="hidden" name="encryption" value="starttls" x-bind:disabled="enabled">
            <input type="hidden" name="verify_peer" value="1" x-bind:disabled="enabled">
        </x-ui.card>
        <x-ui.card :title="__('Sender')">
            <div class="form-grid">
                <x-ui.field name="from_address" type="email" :label="__('From address')" :value="$settings['from_address']" required />
                <x-ui.field name="from_name" :label="__('From name')" :value="$settings['from_name']" />
            </div>
        </x-ui.card>
        <div><button type="submit" class="btn btn-primary">{{ __('Save') }}</button></div>
    </form>

    <x-ui.card :title="__('Send a test mail')">
        <form method="POST" action="{{ route('admin.settings.mail.test') }}" class="actions" style="align-items:flex-end">
            @csrf
            <div style="flex:1;min-width:240px"><x-ui.field name="test_to" type="email" :label="__('Send to')" :value="auth()->user()->email" required /></div>
            <button type="submit" class="btn btn-dark"><x-icon name="send" />{{ __('Send test mail') }}</button>
        </form>
        <p class="muted small">{{ __('Uses the saved settings. Save first if you changed something.') }}</p>
    </x-ui.card>
</x-layouts.admin>
