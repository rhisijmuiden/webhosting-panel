@php
    $show = function (\Rhis\DnsMail\Dns\DnsRecord $rec) use ($domain): string {
        if ($rec->type === 'SRV') {
            $s = $rec->srv ?? [];
            return sprintf('%d %d %d %s', $s['priority'] ?? 0, $s['weight'] ?? 0, $s['port'] ?? 0, $s['target'] ?? '');
        }
        return ($rec->type === 'MX' ? $rec->priority . ' ' : '') . $rec->content;
    };
    $actionLabels = ['create' => __('Add'), 'update' => __('Change'), 'delete' => __('Remove')];
    $actionPill = ['create' => 'ok', 'update' => 'warn', 'delete' => 'bad'];
@endphp

@if ($onboarding?->isPending())
    <div class="alert alert-warn" role="status"><x-icon name="refresh" /><div>
        <strong>{{ __('Waiting for the new nameservers') }}</strong>
        <p class="small">{{ __('Your records are ready at :provider Cloudflare. They go live once the nameservers at your registrar point to Cloudflare.', ['provider' => \App\Support\Brand::provider()]) }}</p>
        <a class="btn btn-sm" href="{{ $r('connect') }}">{{ __('Continue the DNS wizard') }}</a>
    </div></div>
@elseif ($onboarding?->isActive() && $onboarding->activated_at?->gt(now()->subDays(7)))
    <div class="alert alert-ok" role="status"><x-icon name="check" /><div>
        <strong>{{ __('Your DNS is now live at :provider Cloudflare', ['provider' => \App\Support\Brand::provider()]) }}</strong>
        <p class="small">{{ __('Since :date. Records for your website and mail are kept up to date automatically.', ['date' => $onboarding->activated_at->isoFormat('LLL')]) }}</p>
    </div></div>
@endif

@if ($hasCloudflare && $domain->canOnboard() && ! $onboarding?->isPending())
    <x-ui.card>
        <div class="actions" style="justify-content:space-between">
            <div><h2 style="margin:0">{{ __('Let :provider run your DNS', ['provider' => \App\Support\Brand::provider()]) }}</h2>
                <p class="muted small" style="margin:4px 0 0">{{ __('Keep your domain at your registrar and only change the nameservers. We then set every record for your website and mail automatically, on the fast Cloudflare network.') }}</p></div>
            <a class="btn btn-primary" href="{{ $r('connect') }}"><x-icon name="cloud" />{{ __('Start the DNS wizard') }}</a>
        </div>
    </x-ui.card>
@endif

@if (! $domain->dns_managed || ! $hasCloudflare)
    @php
        $check = $domain->dns_check;
        $byKey = collect($check['results'] ?? [])->keyBy(fn ($x) => $x['type'] . '|' . $x['name'] . '|' . $x['value']);
        $statusPill = ['ok' => 'ok', 'missing' => 'bad', 'wrong' => 'warn'];
        $statusLabel = ['ok' => __('Correct'), 'missing' => __('Add this record'), 'wrong' => __('Change this record')];
    @endphp
    <x-ui.card :title="__('Records to set at your DNS provider')">
        <x-slot:actions>
            @if ($check)
                @if ($check['ok'])<x-ui.pill status="ok">{{ __('Everything is set correctly') }}</x-ui.pill>
                @else<x-ui.pill status="warn">{{ trans_choice(':count record needs your attention|:count records need your attention', collect($check['results'])->where('status', '!=', 'ok')->count(), ['count' => collect($check['results'])->where('status', '!=', 'ok')->count()]) }}</x-ui.pill>@endif
            @endif
            <form method="POST" action="{{ $r('dns.check') }}">@csrf<button type="submit" class="btn btn-dark btn-sm"><x-icon name="refresh" />{{ __('Check now') }}</button></form>
        </x-slot:actions>
        <p class="muted small">
            @if (! $hasCloudflare){{ __('Cloudflare is not connected, so DNS is not published automatically.') }}@else{{ __('DNS for this domain is managed elsewhere.') }}@endif
            {{ __('Create these records in the control panel of your DNS provider. We check them against the public DNS every few hours; a change can take up to an hour to show.') }}
            @if ($check)<br>{{ __('Last checked :time.', ['time' => \Illuminate\Support\Carbon::parse($check['checked_at'])->diffForHumans()]) }}@endif
        </p>
        <x-ui.table>
            <x-slot:head><th>{{ __('Status') }}</th><th>{{ __('Type') }}</th><th>{{ __('Name') }}</th><th>{{ __('Value') }}</th></x-slot:head>
            @foreach ($allRecords as $rec)
                @php $res = $byKey[$rec->type . '|' . $rec->relativeName($domain->domain) . '|' . $rec->displayValue()] ?? null; @endphp
                <tr>
                    <td>@if ($res)<x-ui.pill :status="$statusPill[$res['status']]">{{ $statusLabel[$res['status']] }}</x-ui.pill>@else<span class="muted small">–</span>@endif</td>
                    <td>{{ $rec->type }}</td>
                    <td class="mono">{{ $rec->relativeName($domain->domain) }}</td>
                    <td class="mono small" style="word-break:break-all">{{ $show($rec) }}
                        @if ($res && $res['found'])<br><span class="muted">{{ __('Now set: :values', ['values' => implode(', ', $res['found'])]) }}</span>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        <p class="muted small">{{ __('Names are relative to :domain (@ is the domain itself). If your DNS provider offers a proxy, keep it off for mail.:domain and the other mail records.', ['domain' => $domain->domain]) }}</p>
    </x-ui.card>
@elseif ($inspect['error'])
    <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('Cloudflare could not be read') }}</strong><p class="small">{{ $inspect['error'] }}</p></div></div>
@else
    @php $plan = $inspect['plan']; @endphp
    @if ($domain->website)
        <x-ui.card :title="__('Cloudflare proxy for the website')">
            <form method="POST" action="{{ $r('dns.proxy') }}" class="actions" style="justify-content:space-between">
                @csrf @method('PUT')
                <label class="check" style="margin:0"><input type="hidden" name="proxied" value="0"><input type="checkbox" name="proxied" value="1" @checked($domain->proxied) onchange="this.form.requestSubmit()">
                    <span>{{ __('Website through the Cloudflare proxy') }}<small>{{ __('Hides the server address and adds Cloudflare\'s cache and DDoS protection for :domain and its www and alias names. mail.:domain and the other mail records always stay DNS only.', ['domain' => $domain->domain]) }}</small></span></label>
                <noscript><button type="submit" class="btn btn-sm">{{ __('Save') }}</button></noscript>
            </form>
        </x-ui.card>
    @endif
    <x-ui.card :title="$adopt ? __('Preview: take over existing records') : __('Changes waiting')">
        @if ($plan->isInSync())
            <p><x-ui.pill status="ok">{{ __('DNS is up to date') }}</x-ui.pill></p>
        @else
            @if ($plan->changes)
                <x-ui.table>
                    <x-slot:head><th></th><th>{{ __('Type') }}</th><th>{{ __('Name') }}</th><th>{{ __('Value') }}</th></x-slot:head>
                    @foreach ($plan->changes as $c)
                        <tr>
                            <td><x-ui.pill :status="$actionPill[$c->action]" :dot="false">{{ $actionLabels[$c->action] }}</x-ui.pill></td>
                            <td>{{ $c->record->type }}</td>
                            <td class="mono">{{ $c->record->relativeName($domain->domain) }}</td>
                            <td class="mono small" style="word-break:break-all">{{ $show($c->record) }}@if ($c->previous && $c->action === 'update' && $show($c->previous) !== $show($c->record))<br><span class="muted">{{ __('was') }}: {{ $show($c->previous) }}</span>@endif</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
            @if ($plan->conflicts)
                <div class="alert alert-warn" role="status" style="margin-top:12px"><x-icon name="info" /><div>
                    <strong>{{ __('These records already exist with other values') }}</strong>
                    <p class="small">{{ __('They were made by hand or by a previous host. The panel leaves them alone until you take them over.') }}</p>
                    <ul class="small">
                        @foreach ($plan->conflicts as $conflict)
                            <li>{{ __($conflict->messageKey()) }}
                                @foreach ($conflict->existing as $e)<span class="mono">{{ $e->type }} {{ $e->relativeName($domain->domain) }} {{ $show($e) }}</span>@if (! $loop->last), @endif @endforeach</li>
                        @endforeach
                    </ul>
                    <a class="btn btn-sm" href="{{ $r('show', ['tab' => 'dns', 'adopt' => 1]) }}">{{ __('Preview taking them over') }}</a>
                </div></div>
            @endif
            @if ($plan->changes)
                <form method="POST" action="{{ $r('dns.apply') }}" class="actions" style="margin-top:12px">
                    @csrf <input type="hidden" name="adopt" value="{{ $adopt ? 1 : 0 }}">
                    <button type="submit" class="btn btn-primary"><x-icon name="cloud" />{{ $adopt ? __('Take over and publish') : __('Publish changes') }}</button>
                    @if ($adopt)<a class="btn btn-ghost" href="{{ $r('show', ['tab' => 'dns']) }}">{{ __('Cancel') }}</a>@endif
                </form>
            @endif
        @endif
        <p class="muted small">{{ __('Records the panel manages are marked in Cloudflare. Records you add yourself are never changed.') }}</p>
    </x-ui.card>

    @if ($errors->edit->any())<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $errors->edit->first() }}</div>@endif
    <x-ui.card :title="__('All records at Cloudflare')" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Type') }}</th><th>{{ __('Name') }}</th><th>{{ __('Value') }}</th><th>{{ __('Cloudflare') }}</th><th>{{ __('Managed by') }}</th><th class="num"></th></x-slot:head>
            @foreach (collect($inspect['existing'])->sortBy(fn ($e) => $e->name . $e->type) as $e)
                @php
                    $own = ! $e->isManaged() && in_array($e->type, \Rhis\DnsMail\Http\Controllers\DomainActions::OWN_RECORD_TYPES, true);
                    $canProxy = \Rhis\DnsMail\Dns\DnsRecord::mayProxy($e->type, $e->name, $domain->domain);
                    $rel = $e->relativeName($domain->domain);
                @endphp
                <tr>
                    <td>{{ $e->type }}</td>
                    <td class="mono">{{ $rel }}</td>
                    <td class="mono small" style="word-break:break-all">{{ $show($e) }}</td>
                    <td>
                        @if (in_array($e->type, \Rhis\DnsMail\Dns\DnsRecord::PROXIABLE_TYPES, true))
                            @if ($own && $canProxy)
                                <form method="POST" action="{{ $r('dns.records.update', [$e->id]) }}">@csrf @method('PUT')
                                    <input type="hidden" name="type" value="{{ $e->type }}"><input type="hidden" name="name" value="{{ $rel }}">
                                    <input type="hidden" name="content" value="{{ $e->content }}"><input type="hidden" name="ttl" value="{{ in_array($e->ttl, [1, 60, 300, 3600, 86400], true) ? $e->ttl : 1 }}">
                                    <input type="hidden" name="proxied" value="{{ $e->proxied ? 0 : 1 }}">
                                    <button type="submit" class="btn btn-ghost btn-sm" title="{{ __('Switch the Cloudflare proxy on or off') }}">
                                        @if ($e->proxied)<x-ui.pill status="info">{{ __('Proxied') }}</x-ui.pill>@else<x-ui.pill status="neutral">{{ __('DNS only') }}</x-ui.pill>@endif
                                    </button>
                                </form>
                            @else
                                <span title="{{ $canProxy ? __('Set by the panel') : __('Mail and other non-web names always stay DNS only') }}">
                                    @if ($e->proxied)<x-ui.pill status="info">{{ __('Proxied') }}</x-ui.pill>@else<x-ui.pill status="neutral">{{ __('DNS only') }}</x-ui.pill>@endif
                                </span>
                            @endif
                        @endif
                    </td>
                    <td>@if ($e->isManaged())<x-ui.pill status="neutral"><x-icon name="lock" />{{ __('Panel') }}</x-ui.pill>@else<span class="muted small">{{ __('You') }}</span>@endif</td>
                    <td class="num">@if ($own)
                        <div class="actions" style="justify-content:flex-end">
                            <details>
                                <summary class="btn btn-ghost btn-sm" aria-label="{{ __('Edit') }}"><x-icon name="edit" /></summary>
                                <form method="POST" action="{{ $r('dns.records.update', [$e->id]) }}" class="form" style="min-width:280px;text-align:left;margin-top:8px">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="type" value="{{ $e->type }}">
                                    <div class="field"><label for="n-{{ $e->id }}">{{ __('Name') }}</label><input class="input" id="n-{{ $e->id }}" name="name" value="{{ $rel }}"></div>
                                    <div class="field"><label for="c-{{ $e->id }}">{{ __('Value') }}</label><input class="input" id="c-{{ $e->id }}" name="content" value="{{ $e->content }}" required></div>
                                    @if ($e->type === 'MX')<div class="field"><label for="p-{{ $e->id }}">{{ __('Priority') }}</label><input class="input" id="p-{{ $e->id }}" name="priority" type="number" min="0" max="65535" value="{{ $e->priority }}"></div>@endif
                                    <div class="field"><label for="t-{{ $e->id }}">TTL</label><select class="select" id="t-{{ $e->id }}" name="ttl">
                                        @foreach ([1 => __('Automatic'), 300 => '5 min', 3600 => __('1 hour'), 86400 => __('1 day')] as $v => $label)<option value="{{ $v }}" @selected($e->ttl === $v)>{{ $label }}</option>@endforeach
                                    </select></div>
                                    @if ($canProxy)<label class="check"><input type="hidden" name="proxied" value="0"><input type="checkbox" name="proxied" value="1" @checked($e->proxied)><span>{{ __('Through the Cloudflare proxy') }}</span></label>@endif
                                    <div><button type="submit" class="btn btn-dark btn-sm">{{ __('Save') }}</button></div>
                                </form>
                            </details>
                            <form method="POST" action="{{ $r('dns.records.destroy', [$e->id]) }}" onsubmit="return confirm(@js(__('Remove this record?')))">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" type="submit" aria-label="{{ __('Remove') }}"><x-icon name="trash" /></button></form>
                        </div>
                    @endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Add a record')">
        <form method="POST" action="{{ $r('dns.records.store') }}" class="form" x-data="{ type: @js(old('type', 'TXT')) }">
            @csrf
            <div class="form-grid">
                <x-ui.field name="type" type="select" :label="__('Type')" x-model="type">
                    @foreach (\Rhis\DnsMail\Http\Controllers\DomainActions::OWN_RECORD_TYPES as $t)<option value="{{ $t }}" @selected(old('type', 'TXT') === $t)>{{ $t }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="name" :label="__('Name')" placeholder="@" :help="__('@ for :domain itself, or a subdomain such as shop.', ['domain' => $domain->domain])" />
            </div>
            <x-ui.field name="content" :label="__('Value')" required />
            <div class="form-grid">
                <div x-show="type === 'MX'" x-cloak><x-ui.field name="priority" type="number" min="0" max="65535" :label="__('Priority')" :value="10" /></div>
                <x-ui.field name="ttl" type="select" label="TTL">
                    @foreach ([1 => __('Automatic'), 300 => '5 min', 3600 => __('1 hour'), 86400 => __('1 day')] as $v => $label)<option value="{{ $v }}">{{ $label }}</option>@endforeach
                </x-ui.field>
            </div>
            <label class="check" x-show="['A','AAAA','CNAME'].includes(type)"><input type="checkbox" name="proxied" value="1"><span>{{ __('Through the Cloudflare proxy') }}<small>{{ __('Only for websites. Names such as mail, smtp, imap, ftp and autodiscover always stay DNS only.') }}</small></span></label>
            @error('proxied')<p class="error">{{ $message }}</p>@enderror
            <div><button type="submit" class="btn btn-dark"><x-icon name="plus" />{{ __('Add record') }}</button></div>
        </form>
    </x-ui.card>
@endif
