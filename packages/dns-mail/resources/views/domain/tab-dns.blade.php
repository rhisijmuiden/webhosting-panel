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

@if (! $domain->dns_managed || ! $hasCloudflare)
    <x-ui.card :title="__('Records to set')">
        <p class="muted small">
            @if (! $hasCloudflare){{ __('Cloudflare is not connected, so DNS is not published automatically.') }}@else{{ __('DNS for this domain is managed elsewhere.') }}@endif
            {{ __('Add these records at your DNS provider.') }}
        </p>
        <x-ui.table>
            <x-slot:head><th>{{ __('Type') }}</th><th>{{ __('Name') }}</th><th>{{ __('Value') }}</th></x-slot:head>
            @foreach ($mailRecords as $rec)
                <tr><td>{{ $rec->type }}</td><td class="mono">{{ $rec->relativeName($domain->domain) }}</td><td class="mono small" style="word-break:break-all">{{ $show($rec) }}</td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
@elseif ($inspect['error'])
    <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('Cloudflare could not be read') }}</strong><p class="small">{{ $inspect['error'] }}</p></div></div>
@else
    @php $plan = $inspect['plan']; @endphp
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

    <x-ui.card :title="__('All records at Cloudflare')" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Type') }}</th><th>{{ __('Name') }}</th><th>{{ __('Value') }}</th><th>{{ __('Managed by') }}</th><th class="num"></th></x-slot:head>
            @foreach (collect($inspect['existing'])->sortBy(fn ($e) => $e->name . $e->type) as $e)
                <tr>
                    <td>{{ $e->type }}</td>
                    <td class="mono">{{ $e->relativeName($domain->domain) }}</td>
                    <td class="mono small" style="word-break:break-all">{{ $show($e) }}@if ($e->proxied) <x-ui.pill status="info" :dot="false">{{ __('Proxied') }}</x-ui.pill>@endif</td>
                    <td>@if ($e->isManaged())<x-ui.pill status="neutral"><x-icon name="lock" />{{ __('Panel') }}</x-ui.pill>@else<span class="muted small">{{ __('You') }}</span>@endif</td>
                    <td class="num">@unless ($e->isManaged())
                        <form method="POST" action="{{ $r('dns.records.destroy', [$e->id]) }}" onsubmit="return confirm(@js(__('Remove this record?')))">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" type="submit" aria-label="{{ __('Remove') }}"><x-icon name="trash" /></button></form>
                    @endunless</td>
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
            <label class="check" x-show="['A','AAAA','CNAME'].includes(type)"><input type="checkbox" name="proxied" value="1"><span>{{ __('Through the Cloudflare proxy') }}</span></label>
            <div><button type="submit" class="btn btn-dark"><x-icon name="plus" />{{ __('Add record') }}</button></div>
        </form>
    </x-ui.card>
@endif
