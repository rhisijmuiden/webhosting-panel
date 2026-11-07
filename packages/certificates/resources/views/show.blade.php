<x-dynamic-component :component="'layouts.'.$area" :title="__('SSL certificate').' · '.$website->domain">
    @include('certificates::partials.styles')
    @if ($cert->isBusy())<meta http-equiv="refresh" content="6">@endif
    @php
        $health = $cert->health();
        $days = $cert->daysLeft();
        $lifetime = $cert->not_before && $cert->not_after ? max(1, $cert->not_before->diffInDays($cert->not_after)) : 90;
        $websiteUrl = \Illuminate\Support\Facades\Route::has($area.'.websites.show') ? route($area.'.websites.show', $website) : null;
    @endphp
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.certificates.index') }}">{{ __('SSL certificates') }}</a> / @if ($websiteUrl)<a href="{{ $websiteUrl }}">{{ $website->domain }}</a>@else{{ $website->domain }}@endif</div>
            <div class="actions"><h1>{{ $website->domain }}</h1>
                <x-ui.pill :status="$cert->source === 'none' ? 'pending' : $health">{{ $cert->source === 'none' ? __('No certificate yet') : ($cert->isExpired() ? __('Expired') : ($health === 'ok' ? __('Valid') : __('Expires soon'))) }}</x-ui.pill></div>
        </div>
        <div class="actions">
            <form method="POST" action="{{ route($area.'.certificates.refresh', $website) }}">@csrf<button class="btn" type="submit"><x-icon name="refresh" />{{ __('Check again') }}</button></form>
            <a class="btn btn-primary" href="https://{{ $website->domain }}" target="_blank" rel="noopener">{{ __('Visit website') }}<x-icon name="external" /></a>
        </div>
    </div>

    @error('certificate')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @error('csr')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @foreach ((array) session('cert_warnings', []) as $w)<div class="alert alert-warn" role="status"><x-icon name="info" />{{ $w }}</div>@endforeach
    @if ($cert->isBusy())
        <div class="alert alert-info" role="status"><x-icon name="refresh" />{{ $cert->requested === 'wildcard'
            ? __('The wildcard certificate is being requested at Let\'s Encrypt via a DNS check at Cloudflare. This page refreshes by itself.')
            : __('A Let\'s Encrypt certificate is being requested. This page refreshes by itself.') }}</div>
    @elseif ($cert->status === 'failed' && $cert->last_error)
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><span><strong>{{ __('The last request failed.') }}</strong> {{ $cert->last_error }} {{ __('The website keeps its current certificate.') }}</span></div>
    @endif

    <section class="bento">
        <x-ui.card class="span-7" :title="__('Current certificate')">
            @if ($cert->source === 'none')
                <x-ui.empty icon="lock">{{ __('This website has no valid certificate yet. Let\'s Encrypt issues one as soon as the domain points to this server; it is retried every hour.') }}</x-ui.empty>
            @else
                <div class="cert-hero">
                    <x-ui.ring :value="$cert->isExpired() ? 0 : min(100, max(0, $days) / $lifetime * 100)" :tone="$health" :size="120">
                        <strong>{{ $cert->isExpired() ? 0 : $days }}</strong><small>{{ trans_choice('day left|days left', max(0, (int) $days)) }}</small>
                    </x-ui.ring>
                    <div>
                        <h3>{{ \Rhis\Certificates\Present::source($cert) }}</h3>
                        <p class="muted small" style="margin:0">
                            @if ($cert->renewsAutomatically()){{ __('Renewed automatically by the server, well before it expires.') }}
                            @elseif ($cert->source === 'custom'){{ __('Replace it yourself before it expires: you get an e-mail 30, 14, 7, 3 and 1 day(s) before.') }}
                            @else{{ __('Switches to Let\'s Encrypt automatically once the domain points to this server.') }}@endif
                        </p>
                        @if ($cert->source === 'wildcard' && $cert->wildcard_of && $cert->wildcard_of !== $website->domain)
                            <p class="small" style="margin:6px 0 0">{{ __('Uses the wildcard certificate of :domain.', ['domain' => $cert->wildcard_of]) }}</p>
                        @endif
                    </div>
                </div>
                <dl class="kv kv-tight" style="margin-top:18px">
                    <dt>{{ __('Valid for') }}</dt>
                    <dd><ul class="cert-names">@foreach ((array) $cert->names as $n)<li>{{ $n }}</li>@endforeach
                        @foreach ($uncovered as $n)<li class="is-missing" title="{{ __('Not covered by this certificate') }}">{{ $n }} ✕</li>@endforeach</ul></dd>
                    <dt>{{ __('Issued by') }}</dt><dd>{{ $cert->issuer ?: '–' }}@if ($cert->self_signed) <x-ui.pill status="warn">{{ __('Self-signed') }}</x-ui.pill>@endif</dd>
                    <dt>{{ __('Valid from') }}</dt><dd>{{ $cert->not_before?->format('d-m-Y H:i') ?? '–' }}</dd>
                    <dt>{{ __('Valid until') }}</dt><dd>{{ $cert->not_after?->format('d-m-Y H:i') ?? '–' }}</dd>
                    <dt>{{ __('Intermediate certificates') }}</dt><dd>{{ $cert->chain_length }}</dd>
                    <dt>{{ __('SHA-256 fingerprint') }}</dt><dd class="mono small" style="word-break:break-all">{{ $cert->fingerprint ?: '–' }}</dd>
                    <dt>{{ __('Last checked') }}</dt><dd>{{ $cert->checked_at?->diffForHumans() ?? '–' }}</dd>
                </dl>
                @if ($uncovered)
                    <p class="small" style="margin-top:10px">{{ __('Visitors on :names get a security warning: this certificate does not cover that address.', ['names' => implode(', ', $uncovered)]) }}</p>
                @endif
            @endif
        </x-ui.card>

        <x-ui.card class="span-5" :title="__('Certificate type')">
            <ul class="cert-options">
                <li @class(['cert-option', 'is-current' => $cert->source === 'letsencrypt'])>
                    <span class="cert-icon"><x-icon name="lock" /></span>
                    <div><strong>{{ __('Let\'s Encrypt') }}</strong><p>{{ __('Free, for the domain and www. Renewed automatically. The right choice for almost every website.') }}</p></div>
                    @if ($cert->source === 'letsencrypt')<x-ui.pill status="ok">{{ __('In use') }}</x-ui.pill>
                    @elseif ($canManage)
                        <form method="POST" action="{{ route($area.'.certificates.letsencrypt', $website) }}">@csrf<button class="btn btn-sm" type="submit" @disabled($cert->isBusy())>{{ __('Use') }}</button></form>
                    @endif
                </li>
                <li @class(['cert-option', 'is-current' => $cert->source === 'wildcard'])>
                    <span class="cert-icon"><x-icon name="globe" /></span>
                    <div><strong>{{ __('Wildcard (Let\'s Encrypt)') }}</strong>
                        <p>{{ __('One certificate for :domain and every subdomain (*.:domain). Checked through a DNS record at Cloudflare, renewed automatically.', ['domain' => $parentWildcard?->domain ?? $website->domain]) }}</p>
                        @if ($parentWildcard)<p>{{ __('The wildcard of :domain already covers this website.', ['domain' => $parentWildcard->domain]) }}</p>@endif
                        @if ($wildcardBlocker && $cert->source !== 'wildcard')<p class="small" style="color:var(--warn-ink)">{{ $wildcardBlocker }}</p>@endif
                    </div>
                    @if ($cert->source === 'wildcard')<x-ui.pill status="ok">{{ __('In use') }}</x-ui.pill>
                    @elseif ($canManage && ! $wildcardBlocker)
                        <form method="POST" action="{{ route($area.'.certificates.wildcard', $website) }}">@csrf<button class="btn btn-sm" type="submit" @disabled($cert->isBusy())>{{ $parentWildcard ? __('Use') : __('Request') }}</button></form>
                    @endif
                </li>
                <li @class(['cert-option', 'is-current' => $cert->source === 'custom'])>
                    <span class="cert-icon"><x-icon name="key" /></span>
                    <div><strong>{{ __('Own certificate') }}</strong><p>{{ __('A certificate you bought, for example EV/OV or from your organisation. Upload PEM files or a PFX file below.') }}</p></div>
                    @if ($cert->source === 'custom')<x-ui.pill status="ok">{{ __('In use') }}</x-ui.pill>
                    @elseif ($canManage)<a class="btn btn-sm cert-act" href="#upload">{{ __('Upload') }}</a>@endif
                </li>
            </ul>
        </x-ui.card>
    </section>

    @if ($canManage)
        <section class="bento" id="upload">
            <x-ui.card class="span-7" :title="$cert->source === 'custom' ? __('Replace own certificate') : __('Upload own certificate')">
                <form method="POST" action="{{ route($area.'.certificates.custom', $website) }}" enctype="multipart/form-data" class="form" x-data="{ format: '{{ old('format', 'pem') }}' }">
                    @csrf
                    <div class="seg cert-tabs" role="radiogroup" aria-label="{{ __('File format') }}">
                        <label><input type="radio" name="format" value="pem" x-model="format"><span>{{ __('PEM (text)') }}</span></label>
                        <label><input type="radio" name="format" value="pfx" x-model="format"><span>{{ __('PFX / PKCS#12') }}</span></label>
                    </div>
                    <div class="form" x-show="format === 'pem'">
                        <div class="field">
                            <label for="cert-certificate">{{ __('Certificate') }}</label>
                            <textarea id="cert-certificate" name="certificate" class="textarea cert-pem" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                            <span class="cert-or">{{ __('or choose a file (.crt, .pem, .cer)') }} <input type="file" name="certificate_file" accept=".crt,.pem,.cer,.txt"></span>
                        </div>
                        <div class="field">
                            <label for="cert-key">{{ __('Private key') }}</label>
                            <textarea id="cert-key" name="private_key" class="textarea cert-pem" spellcheck="false" autocomplete="off" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
                            <span class="cert-or">{{ __('or choose a file (.key, .pem)') }} <input type="file" name="private_key_file" accept=".key,.pem,.txt"></span>
                            @if ($cert->csr_key)<span class="help">{{ __('Leave empty to use the key of the CSR you made here on :date.', ['date' => $cert->csr_created_at?->format('d-m-Y')]) }}</span>@endif
                        </div>
                        <div class="field">
                            <label for="cert-chain">{{ __('Intermediate certificates (CA bundle)') }} <span class="muted">({{ __('optional') }})</span></label>
                            <textarea id="cert-chain" name="chain" class="textarea cert-pem" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----">{{ old('chain') }}</textarea>
                            <span class="cert-or">{{ __('or choose a file (.ca-bundle, .crt)') }} <input type="file" name="chain_file" accept=".crt,.pem,.cer,.ca-bundle,.txt"></span>
                        </div>
                        <x-ui.field name="passphrase" type="password" :label="__('Key password (only for an encrypted key)')" autocomplete="off" />
                    </div>
                    <div class="form" x-show="format === 'pfx'" x-cloak>
                        <div class="field">
                            <label for="cert-pfx">{{ __('PFX file') }}</label>
                            <input id="cert-pfx" type="file" name="pfx_file" accept=".pfx,.p12">
                            <span class="help">{{ __('Exported from Windows/IIS or your supplier: contains the certificate, the key and the chain.') }}</span>
                        </div>
                        <x-ui.field name="pfx_password" type="password" :label="__('PFX password')" autocomplete="off" />
                    </div>
                    <p class="muted small" style="margin:0">{{ __('The panel checks that the key belongs to the certificate, that it covers :domain and that the chain is complete, before anything changes on the server.', ['domain' => $website->domain]) }}</p>
                    <div><button type="submit" class="btn btn-dark"><x-icon name="lock" />{{ __('Check and install') }}</button></div>
                </form>
            </x-ui.card>

            <x-ui.card class="span-5" :title="__('Certificate signing request (CSR)')">
                @if ($cert->csr)
                    <p class="muted small">{{ __('Made on :date. Give this CSR to your certificate supplier, then upload the certificate you get back: the key stays in the panel.', ['date' => $cert->csr_created_at?->format('d-m-Y H:i')]) }}</p>
                    <textarea class="textarea cert-pem" readonly aria-label="CSR" onclick="this.select()">{{ $cert->csr }}</textarea>
                    <div class="actions" style="margin-top:12px">
                        <a class="btn btn-sm" href="{{ route($area.'.certificates.csr.download', $website) }}">{{ __('Download .csr') }}</a>
                        <form method="POST" action="{{ route($area.'.certificates.csr.destroy', $website) }}" onsubmit="return confirm(@js(__('Remove this CSR and its key? A certificate made for it can then no longer be installed.')))">@csrf @method('DELETE')<button class="btn btn-sm" type="submit"><x-icon name="trash" />{{ __('Remove') }}</button></form>
                    </div>
                @else
                    <p class="muted small">{{ __('Buying a certificate? Make the CSR here: the private key is created and kept safely in the panel, so you never have to send it anywhere.') }}</p>
                    <form method="POST" action="{{ route($area.'.certificates.csr', $website) }}" class="form">
                        @csrf
                        <div class="field"><span class="label">{{ __('Key type') }}</span>
                            <div class="seg" role="radiogroup" aria-label="{{ __('Key type') }}">
                                @foreach ($keyTypes as $k => $label)<label><input type="radio" name="key_type" value="{{ $k }}" @checked($k === 'ec')><span>{{ $label }}</span></label>@endforeach
                            </div>
                        </div>
                        <div class="cert-grid-2">
                            <x-ui.field name="organization" :label="__('Organisation')" :value="$website->customer?->company ?? null" />
                            <x-ui.field name="city" :label="__('City')" />
                            <x-ui.field name="state" :label="__('Province')" />
                            <x-ui.field name="country" :label="__('Country code')" value="NL" maxlength="2" />
                        </div>
                        <input type="hidden" name="www" value="0">
                        <label class="check"><input type="checkbox" name="www" value="1" checked><span>{{ __('Also for www.:domain', ['domain' => $website->domain]) }}</span></label>
                        <div><button type="submit" class="btn"><x-icon name="key" />{{ __('Make CSR') }}</button></div>
                    </form>
                @endif
            </x-ui.card>
        </section>
    @endif
</x-dynamic-component>
