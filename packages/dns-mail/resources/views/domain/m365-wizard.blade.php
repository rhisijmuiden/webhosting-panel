@php
    $steps = [
        'verify' => filled($domain->m365_verification),
        'tenant' => filled($domain->m365_tenant),
        'dns' => $domain->provider === 'microsoft365' && $domain->dns_status === 'in_sync',
    ];
    $done = fn (bool $ok) => $ok ? '<span class="pill pill-ok"><span class="dot" aria-hidden="true"></span>' . e(__('Done')) . '</span>' : '';
@endphp
@if ($m365OrderUrl)
    <x-ui.card>
        <div class="actions" style="justify-content:space-between">
            <div><h2 style="margin:0">{{ __('No Microsoft 365 yet?') }}</h2>
                <p class="muted small" style="margin:4px 0 0">{{ __('Order your licences through :provider: set up for you, one invoice, and help when you need it.', ['provider' => \App\Support\Brand::provider()]) }}@if ($m365Price) <strong>{{ $m365Price }}</strong>@endif</p></div>
            <a class="btn btn-primary" href="{{ $m365OrderUrl }}" target="_blank" rel="noopener">{{ __('Order Microsoft 365') }}<x-icon name="external" /></a>
        </div>
    </x-ui.card>
@endif

<x-ui.card :title="__('Microsoft 365 setup')">
    <p class="muted small">{{ __('Follow these steps. We publish every record Microsoft asks for to Cloudflare for you.') }}</p>
    <ol class="form" style="padding-left:20px;margin:0">
        <li>
            <strong>{{ __('Add the domain in Microsoft 365') }}</strong> {!! $done($steps['verify']) !!}
            <p class="muted small">{{ __('In the Microsoft 365 admin center, go to Settings > Domains > Add domain and enter :domain. Choose "Add a TXT record" and copy the MS=ms… code.', ['domain' => $domain->domain]) }}
                <a href="https://admin.microsoft.com/#/Domains" target="_blank" rel="noopener">{{ __('Open the admin center') }}</a></p>
            <x-ui.field name="m365_verification" :label="__('Verification code')" :value="$domain->m365_verification" placeholder="MS=ms12345678" />
        </li>
        <li>
            <strong>{{ __('Your Microsoft 365 tenant') }}</strong> {!! $done($steps['tenant']) !!}
            <p class="muted small">{{ __('The initial domain of your organisation, for example contoso.onmicrosoft.com. Needed for the DKIM records.') }}</p>
            <x-ui.field name="m365_tenant" :label="__('Tenant')" :value="$domain->m365_tenant" placeholder="contoso.onmicrosoft.com" />
            <details>
                <summary class="small" style="cursor:pointer;font-weight:600">{{ __('Microsoft shows different values?') }}</summary>
                <p class="muted small">{{ __('Newer tenants can get other MX and DKIM values. Copy them from the admin center (MX) and the Defender portal (DKIM); empty fields use the standard values.') }}</p>
                <div class="form-grid">
                    <x-ui.field name="m365_mx_host" :label="__('MX host')" :value="$domain->m365_mx_host" :placeholder="str_replace('.', '-', $domain->domain) . '.mail.protection.outlook.com'" />
                    <x-ui.field name="m365_dkim_selector1" label="DKIM selector1 CNAME" :value="$domain->m365_dkim_selector1" />
                    <x-ui.field name="m365_dkim_selector2" label="DKIM selector2 CNAME" :value="$domain->m365_dkim_selector2" />
                </div>
                <input type="hidden" name="m365_intune" value="0">
                <label class="check"><input type="checkbox" name="m365_intune" value="1" @checked($domain->m365_intune)><span>{{ __('Device enrolment (Intune) records') }}</span></label>
            </details>
        </li>
        <li>
            <strong>{{ __('Save and publish') }}</strong> {!! $done($steps['dns']) !!}
            <p class="muted small">{{ __('We publish the verification code, MX, SPF, autodiscover and DKIM records. Then click Verify in the admin center; Cloudflare is usually live within a minute.') }}</p>
        </li>
        <li>
            <strong>{{ __('Turn on DKIM') }}</strong>
            <p class="muted small">{{ __('In the Microsoft Defender portal, open Email authentication > DKIM, select :domain and switch signing on.', ['domain' => $domain->domain]) }}
                <a href="https://security.microsoft.com/authentication?viewid=DKIM" target="_blank" rel="noopener">{{ __('Open Defender') }}</a></p>
        </li>
        <li>
            <strong>{{ __('Set up mail apps') }}</strong>
            <p class="muted small">{{ __('Users sign in with their Microsoft account in Outlook on the web, phone or computer. Autodiscover finds the settings.') }}</p>
        </li>
    </ol>
</x-ui.card>
