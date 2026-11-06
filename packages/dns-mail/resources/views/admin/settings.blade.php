<x-layouts.admin :title="__('DNS & mail server')">
    <div class="page-head"><div><h1>{{ __('DNS & mail server') }}</h1><p class="sub">{{ __('The Cloudflare connection and the server details every domain\'s records are built from.') }}</p></div></div>
    @include('dns-mail::partials.warnings')
    <form method="POST" action="{{ route('admin.mail.settings.update') }}" class="form">
        @csrf @method('PUT')
        <x-ui.card :title="__('Cloudflare')">
            <x-slot:actions>
                @if ($hasToken)<x-ui.pill status="ok">{{ __('Token saved') }}</x-ui.pill>@else<x-ui.pill status="warn">{{ __('Not connected') }}</x-ui.pill>@endif
            </x-slot:actions>
            <x-ui.field name="cloudflare_token" type="password" :label="__('API token')" autocomplete="off"
                :help="($hasToken ? __('A token is saved. Leave empty to keep it.').' ' : '').__('Create a token with “Zone: Read” and “DNS: Edit” for the zones you want to manage.')" />
            <x-ui.field name="cloudflare_account_id" :label="__('Account ID')" :value="$values['cloudflare_account_id']" placeholder="0123456789abcdef0123456789abcdef"
                :help="__('Account new customer domains are added to by the DNS wizard. Empty: the only account the token can see. For the wizard the token also needs “Zone: Edit” and “Account Settings: Read”.')" />
            @if ($hasToken)<label class="check"><input type="checkbox" name="clear_token" value="1"><span>{{ __('Remove the saved token') }}</span></label>@endif
            <p class="muted small">{{ __('Records the panel creates are marked in Cloudflare. Records you or your customers add yourselves are never changed.') }}</p>
        </x-ui.card>
        <x-ui.card :title="__('Mail server')">
            <div class="form-grid">
                <x-ui.field name="mail_host" :label="__('Mail host name')" :value="$values['mail_host']" placeholder="mail.rhis.nl" required :help="__('The name mail apps and other servers connect to. Needs a valid certificate.')" />
                <x-ui.field name="webmail_host" :label="__('Webmail host name')" :value="$values['webmail_host']" placeholder="webmail.rhis.nl" :help="__('Every mail domain gets webmail.<domain> pointing here.')" />
                <x-ui.field name="ipv4" :label="__('Public IPv4')" :value="$values['ipv4']" placeholder="192.0.2.10" />
                <x-ui.field name="ipv6" :label="__('Public IPv6')" :value="$values['ipv6']" placeholder="2001:db8::10" />
            </div>
            <x-ui.field name="relay_spf_includes" :label="__('SPF includes of the relay host')" :value="$values['relay_spf_includes']" placeholder="spf.relay-provider.example"
                :help="__('Only when outgoing mail goes through a relay. Comma separated. Leave empty when the server sends directly.')" />
        </x-ui.card>
        <x-ui.card :title="__('Reports')">
            <div class="form-grid">
                <x-ui.field name="dmarc_rua" type="email" :label="__('DMARC reports to')" :value="$values['dmarc_rua']" placeholder="dmarc@rhis.nl" :help="__('Default for every domain; a domain can override it.')" />
                <x-ui.field name="tlsrpt_rua" type="email" :label="__('TLS reports to')" :value="$values['tlsrpt_rua']" placeholder="tls-reports@rhis.nl" :help="__('Other mail servers report delivery problems with encryption here (TLS-RPT).')" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Microsoft 365 through RHIS')">
            <p class="muted small">{{ __('Shown in the Microsoft 365 wizard, so customers can order licences from you.') }}</p>
            <div class="form-grid">
                <x-ui.field name="m365_order_url" type="url" :label="__('Order page')" :value="$values['m365_order_url']" placeholder="https://rhis.nl/shop/microsoft-365" />
                <x-ui.field name="m365_price" :label="__('Price text')" :value="$values['m365_price']" :placeholder="__('from € 6,20 per user per month')" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Spam filter')">
            <p class="muted small">{{ __('Every message is checked for spam and viruses (greylisting, sender reputation, Bayes learning, ClamAV). From score 6 it is marked as spam, from 15 it is refused.') }}</p>
            <x-ui.field name="default_quarantine_score" type="number" min="6" max="50" :label="__('Quarantine from score')" :value="$values['default_quarantine_score'] ?? 10"
                :help="__('Default for new domains. Mail with this score or higher goes to the quarantine, where the customer can release it.')" />
        </x-ui.card>
        <div><button type="submit" class="btn btn-primary">{{ __('Save') }}</button></div>
    </form>
    <x-ui.card :title="__('DANE for the mail host')">
        <x-slot:actions>
            @if ($daneOn)<x-ui.pill status="ok">{{ __('On') }}</x-ui.pill>@else<x-ui.pill status="off">{{ __('Off') }}</x-ui.pill>@endif
        </x-slot:actions>
        <p class="muted small">{{ __('A TLSA record at :name tells other mail servers which certificate to expect, so nobody can intercept mail with a fake one. It needs DNSSEC on the zone and a certificate that keeps its key at renewal.', ['name' => $daneRecord]) }}</p>
        @if ($dane = session('dane'))
            <dl class="kv" style="margin:12px 0">
                <dt>{{ __('Record') }}</dt><dd class="mono">{{ $dane['record'] }} TLSA {{ $dane['value'] }}</dd>
                <dt>DNSSEC</dt><dd>@if ($dane['dnssec'] === 'active')<x-ui.pill status="ok">{{ __('Active') }}</x-ui.pill>@else<x-ui.pill status="warn">{{ $dane['dnssec'] ?? __('unknown') }}</x-ui.pill>@endif</dd>
                @if ($dane['not_after'])<dt>{{ __('Certificate valid until') }}</dt><dd>{{ $dane['not_after'] }}</dd>@endif
            </dl>
            @foreach ($dane['problems'] as $problem)<p class="error">{{ $problem }}</p>@endforeach
        @endif
        @error('dane')<p class="error">{{ $message }}</p>@enderror
        <div class="actions">
            <form method="POST" action="{{ route('admin.mail.settings.dane') }}">@csrf<input type="hidden" name="action" value="check"><button class="btn btn-ghost" type="submit">{{ __('Check') }}</button></form>
            @if ($daneOn)
                <form method="POST" action="{{ route('admin.mail.settings.dane') }}" onsubmit="return confirm(@js(__('Withdraw the DANE record?')))">@csrf<input type="hidden" name="action" value="withdraw"><button class="btn btn-ghost" type="submit">{{ __('Withdraw') }}</button></form>
            @else
                <form method="POST" action="{{ route('admin.mail.settings.dane') }}">@csrf<input type="hidden" name="action" value="publish"><button class="btn btn-dark" type="submit"><x-icon name="shield" />{{ __('Publish DANE record') }}</button></form>
            @endif
        </div>
    </x-ui.card>
    <x-ui.card :title="__('Test the Cloudflare connection')">
        <form method="POST" action="{{ route('admin.mail.settings.test') }}" class="actions">
            @csrf
            <button type="submit" class="btn btn-dark"><x-icon name="cloud" />{{ __('Test connection') }}</button>
        </form>
        @error('cloudflare_token')<p class="error">{{ $message }}</p>@enderror
    </x-ui.card>
</x-layouts.admin>
