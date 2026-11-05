@php $lookups = $spf->lookups(); @endphp
<form method="POST" action="{{ $r('mail') }}" class="form" x-data="{ provider: @js(old('provider', $domain->provider)) }">
    @csrf @method('PUT')
    <x-ui.card :title="__('Where does mail for :domain go?', ['domain' => $domain->domain])">
        @include('dns-mail::partials.provider-choice', ['current' => old('provider', $domain->provider)])
        @if ($domain->hasLocalMail() && $domain->mailboxes->isNotEmpty())
            <p class="help" x-show="provider !== 'rhis'" x-cloak style="color:var(--status-warning)">{{ __('The :count mailboxes here stop receiving mail. Their mail stays on the server, so it can still be moved.', ['count' => $domain->mailboxes->count()]) }}</p>
        @endif
    </x-ui.card>

    <div x-show="provider === 'microsoft365'" x-cloak>
        @include('dns-mail::domain.m365-wizard')
    </div>

    <x-ui.card :title="__('SPF: who may send mail for this domain')" x-show="provider !== 'external'">
        <p class="muted small">{{ __('SPF tells other mail servers which servers may send mail for your domain. The servers of your mail provider and this web server are always included.') }}</p>
        <div class="field">
            <span class="label">{{ __('Record') }}</span>
            <code class="mono small" style="display:block;padding:10px 12px;border:1px solid var(--line, #dce1eb);border-radius:10px;word-break:break-all">{{ $spf->toString() }}</code>
            <span class="help">{{ trans_choice(':count of 10 DNS lookup used|:count of 10 DNS lookups used', $lookups, ['count' => $lookups]) }}</span>
            @foreach ($spf->warnings() as $w)<span class="error">{{ __($w) }}</span>@endforeach
        </div>
        <x-ui.field name="spf_extra" :label="__('Also allowed to send')" :value="$domain->spf_extra" placeholder="include:mailchimp.com include:_spf.google.com"
            :help="__('Newsletter tools, CRM or other senders. SPF mechanisms separated by spaces.')" />
        <div class="field">
            <span class="label">{{ __('Mail from any other server') }}</span>
            <div class="seg" role="radiogroup" aria-label="{{ __('Mail from any other server') }}">
                @foreach (['-' => __('Reject (-all)'), '~' => __('Mark as suspicious (~all)'), '?' => __('No opinion (?all)')] as $v => $label)
                    <label><input type="radio" name="spf_all" value="{{ $v }}" @checked(old('spf_all', $domain->spf_all) === $v)><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </div>
        <input type="hidden" name="server_sends" value="0">
        <label class="check" x-show="provider !== 'rhis'"><input type="checkbox" name="server_sends" value="1" @checked(old('server_sends', $domain->server_sends))><span>{{ __('Websites on this server send mail for this domain') }}<small>{{ __('Contact forms and web shops. Adds this server to SPF and signs that mail with DKIM.') }}</small></span></label>
        <input type="hidden" name="server_sends" value="1" x-bind:disabled="provider !== 'rhis'">
    </x-ui.card>

    <x-ui.card :title="__('DMARC: what receivers do with forged mail')" x-show="provider !== 'external'">
        <p class="muted small">{{ __('Start with "Monitor only", check the reports for two weeks, then tighten the policy.') }}</p>
        <div class="field">
            <span class="label">{{ __('Policy') }}</span>
            <div class="seg" role="radiogroup" aria-label="{{ __('Policy') }}">
                @foreach (['none' => __('Monitor only'), 'quarantine' => __('Send to spam'), 'reject' => __('Reject')] as $v => $label)
                    <label><input type="radio" name="dmarc_policy" value="{{ $v }}" @checked(old('dmarc_policy', $domain->dmarc_policy) === $v)><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </div>
        <x-ui.field name="dmarc_rua" type="email" :label="__('Reports to')" :value="$domain->dmarc_rua" :placeholder="__('Default from the server settings')" />
        <div class="field">
            <span class="label">{{ __('Record') }}</span>
            <code class="mono small" style="display:block;padding:10px 12px;border:1px solid var(--line, #dce1eb);border-radius:10px;word-break:break-all">{{ $dmarc->toString() }}</code>
            @foreach ($dmarc->externalReportDomains($domain->domain) as $rd)
                <span class="help">{{ __('Reports go to another domain. That domain needs a record :name to accept them.', ['name' => $domain->domain . '._report._dmarc.' . $rd]) }}</span>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Encrypted delivery (MTA-STS)')" x-show="provider !== 'external'">
        <p class="muted small">{{ __('Tells other mail servers to only deliver to you over a verified, encrypted connection.') }}</p>
        <div class="seg" role="radiogroup" aria-label="MTA-STS">
            @foreach (['' => __('Off'), 'testing' => __('Testing'), 'enforce' => __('Enforce')] as $v => $label)
                <label><input type="radio" name="mta_sts_mode" value="{{ $v }}" @checked((string) old('mta_sts_mode', $domain->mta_sts_mode) === $v)><span>{{ $label }}</span></label>
            @endforeach
        </div>
    </x-ui.card>

    @if ($area === 'admin')
        <x-ui.card :title="__('DNS')">
            <input type="hidden" name="dns_managed" value="0">
            <label class="check"><input type="checkbox" name="dns_managed" value="1" @checked(old('dns_managed', $domain->dns_managed))><span>{{ __('Publish records to Cloudflare automatically') }}<small>{{ __('Turn off when DNS for this domain is hosted elsewhere; the DNS tab then lists the records to set by hand.') }}</small></span></label>
            <input type="hidden" name="proxied" value="0">
            <label class="check"><input type="checkbox" name="proxied" value="1" @checked(old('proxied', $domain->proxied))><span>{{ __('Website through the Cloudflare proxy') }}<small>{{ __('Hides the server address and adds Cloudflare\'s cache and DDoS protection. Mail records are never proxied.') }}</small></span></label>
        </x-ui.card>
    @else
        <input type="hidden" name="dns_managed" value="{{ (int) $domain->dns_managed }}">
    @endif

    <div><button type="submit" class="btn btn-primary">{{ __('Save and publish') }}</button></div>
</form>

@if ($domain->provider !== 'external')
<x-ui.card :title="__('DKIM signing')">
    <p class="muted small">{{ __('Outgoing mail from this server is signed, so receivers can check it really came from you.') }}</p>
    @if ($domain->dkim_public_key)
        <p><x-ui.pill status="ok">{{ __('Key ready') }}</x-ui.pill> <span class="muted small">{{ __('Selector') }}: <span class="mono">whp</span></span></p>
        <form method="POST" action="{{ $r('dkim') }}" onsubmit="return confirm(@js(__('Replacing the key breaks signatures on mail in transit for a few minutes. Continue?')))">
            @csrf <input type="hidden" name="replace" value="1">
            <button type="submit" class="btn btn-ghost btn-sm"><x-icon name="refresh" />{{ __('Replace key') }}</button>
        </form>
    @else
        <p><x-ui.pill status="warn">{{ __('No DKIM key yet') }}</x-ui.pill></p>
        <form method="POST" action="{{ $r('dkim') }}">@csrf<button type="submit" class="btn btn-dark"><x-icon name="lock" />{{ __('Create key') }}</button></form>
    @endif
</x-ui.card>
@endif
