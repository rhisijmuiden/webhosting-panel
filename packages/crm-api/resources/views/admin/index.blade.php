@php $pill = ['delivered' => 'ok', 'pending' => 'warn', 'retrying' => 'warn', 'failed' => 'bad']; @endphp
<x-layouts.admin :title="__('CRM API')">
    <div class="page-head"><div><h1>{{ __('CRM API') }}</h1><p class="sub">{{ __('Lets your CRM create customers and hosting, and receive events back.') }}</p></div></div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @if (session('new_token'))
        <div class="alert alert-warn" role="alert"><div>
            <strong>{{ __('Copy the token now') }}</strong>
            <p class="small">{{ __('It is shown only once. Put it in the CRM settings as the Bearer token.') }}</p>
            <p class="mono" style="word-break:break-all">{{ session('new_token') }}</p>
        </div></div>
    @endif

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Connection')">
            <dl class="kv">
                <dt>{{ __('Base URL') }}</dt><dd class="mono small">{{ $baseUrl }}</dd>
                <dt>{{ __('Specification') }}</dt><dd class="small">docs/api/crm-api.openapi.yaml</dd>
                <dt>{{ __('Webhook endpoints') }}</dt><dd>{{ $webhooks->count() }}</dd>
            </dl>
        </x-ui.card>
        <x-ui.card :title="__('New token')">
            <form method="POST" action="{{ route('admin.crm-api.tokens.store') }}" class="form">
                @csrf
                <x-ui.field name="name" :label="__('Name')" placeholder="RHIS CRM" required />
                <x-ui.field name="allowed_ips" :label="__('Allowed IP addresses (optional)')" placeholder="203.0.113.10, 2001:db8::/48" :help="__('Leave empty to allow every address.')" />
                <div><button type="submit" class="btn btn-dark">{{ __('Create token') }}</button></div>
            </form>
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Tokens')" flush>
        @if ($tokens->isEmpty())
            <x-ui.empty icon="shield">{{ __('No tokens yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Name') }}</th><th>{{ __('Token') }}</th><th>{{ __('Allowed from') }}</th><th>{{ __('Last used') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($tokens as $t)
                <tr>
                    <td><strong>{{ $t->name }}</strong></td>
                    <td class="mono small">{{ $t->prefix }}…</td>
                    <td class="small">{{ $t->allowed_ips ? implode(', ', $t->allowed_ips) : __('Everywhere') }}</td>
                    <td class="small muted">{{ $t->last_used_at ? $t->last_used_at->diffForHumans().' · '.$t->last_used_ip : __('Never') }}</td>
                    <td class="num"><form method="POST" action="{{ route('admin.crm-api.tokens.revoke', $t) }}" data-confirm="{{ __('Revoke this token? The CRM can no longer connect with it.') }}">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" type="submit">{{ __('Revoke') }}</button></form></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Webhook deliveries')" flush>
        @if ($deliveries->isEmpty())
            <x-ui.empty icon="send">{{ __('No webhooks sent yet. The CRM registers its endpoint through the API.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Event') }}</th><th>{{ __('Status') }}</th><th>{{ __('Attempts') }}</th><th>{{ __('Time') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($deliveries as $d)
                <tr>
                    <td class="mono small">{{ $d->event }}</td>
                    <td><x-ui.pill :status="$pill[$d->status] ?? 'neutral'">{{ __(ucfirst($d->status)) }}</x-ui.pill>@if ($d->last_error)<br><span class="small muted">{{ \Illuminate\Support\Str::limit($d->last_error, 80) }}</span>@endif</td>
                    <td>{{ $d->attempts }}</td>
                    <td class="small muted">{{ $d->created_at->diffForHumans() }}</td>
                    <td class="num">@if ($d->status === 'failed')<form method="POST" action="{{ route('admin.crm-api.deliveries.retry', $d) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Retry') }}</button></form>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.admin>
