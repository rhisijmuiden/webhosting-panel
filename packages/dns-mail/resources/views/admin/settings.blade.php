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
        <div><button type="submit" class="btn btn-primary">{{ __('Save') }}</button></div>
    </form>
    <x-ui.card :title="__('Test the Cloudflare connection')">
        <form method="POST" action="{{ route('admin.mail.settings.test') }}" class="actions">
            @csrf
            <button type="submit" class="btn btn-dark"><x-icon name="cloud" />{{ __('Test connection') }}</button>
        </form>
        @error('cloudflare_token')<p class="error">{{ $message }}</p>@enderror
    </x-ui.card>
</x-layouts.admin>
