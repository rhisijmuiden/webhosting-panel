{{-- $aliases, $area, $showWebsite --}}
@php
    $statusLabel = ['provisioning' => __('Setting up'), 'active' => __('Online'), 'failed' => __('Failed'), 'removing' => __('Removing')];
    $sslLabel = ['active' => __('SSL valid'), 'pending' => __('SSL pending'), 'off' => __('No SSL')];
    $canManage = $area === 'admin' || config('domain-aliases.customer_manage');
@endphp
<x-ui.table>
    <x-slot:head>
        <th>{{ __('Domain') }}</th>
        @if ($showWebsite)<th>{{ __('Website') }}</th>@endif
        <th>{{ __('Behaviour') }}</th><th>{{ __('Mail') }}</th><th>{{ __('Status') }}</th>
        <th class="num"><span class="sr-only">{{ __('Actions') }}</span></th>
    </x-slot:head>
    @foreach ($aliases as $a)
        <tr>
            <td>
                <a class="strong" href="http{{ $a->ssl_status === 'active' ? 's' : '' }}://{{ $a->domain }}" target="_blank" rel="noopener">{{ $a->domain }}</a>
                @if ($a->www)<div class="muted small">+ www.{{ $a->domain }}</div>@endif
            </td>
            @if ($showWebsite)
                <td class="small"><a href="{{ route($area.'.websites.show', $a->website) }}">{{ $a->website->domain }}</a>
                    @if ($area === 'admin')<div class="muted">{{ $a->website->customer?->displayName() }}</div>@endif</td>
            @endif
            <td>
                @if ($a->status === 'active')
                    <form method="POST" action="{{ route($area.'.domain-aliases.update', $a) }}">
                        @csrf @method('PUT')
                        <div class="seg seg-sm" role="radiogroup" aria-label="{{ __('Behaviour of :domain', ['domain' => $a->domain]) }}">
                            <label><input type="radio" name="mode" value="serve" @checked($a->mode === 'serve') onchange="this.form.submit()"><span>{{ __('Same website') }}</span></label>
                            <label><input type="radio" name="mode" value="redirect" @checked($a->mode === 'redirect') onchange="this.form.submit()"><span>{{ __('Redirect') }}</span></label>
                        </div>
                    </form>
                @else
                    <span class="small">{{ $a->mode === 'redirect' ? __('Redirect') : __('Same website') }}</span>
                @endif
            </td>
            <td>
                <form method="POST" action="{{ route($area.'.domain-aliases.update', $a) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="mail" value="0">
                    <label class="check check-sm" title="{{ __('Mail to any address @:alias arrives in the mailbox with the same name @:domain.', ['alias' => $a->domain, 'domain' => $a->website->domain]) }}">
                        <input type="checkbox" name="mail" value="1" @checked($a->mail) onchange="this.form.submit()" @disabled($a->isBusy())><span>{{ __('Forward') }}</span></label>
                </form>
            </td>
            <td>
                <x-ui.pill :status="$a->status">{{ $statusLabel[$a->status] ?? $a->status }}</x-ui.pill>
                @if ($a->status === 'active')<x-ui.pill :status="$a->ssl_status">{{ $sslLabel[$a->ssl_status] ?? $a->ssl_status }}</x-ui.pill>@endif
                @if ($a->status === 'failed' && $a->last_error)<div class="small mono muted">{{ \Illuminate\Support\Str::limit($a->last_error, 160) }}</div>@endif
                @if ($a->upgrade_order_id)<div class="small muted">{{ __('Upgrade ordered') }}</div>@endif
            </td>
            <td class="num">
                <div class="actions" style="justify-content:flex-end">
                    @if ($a->status === 'active' && $a->ssl_status !== 'active')
                        <form method="POST" action="{{ route($area.'.domain-aliases.ssl', $a) }}">@csrf<button class="btn btn-sm" type="submit" title="{{ __('Request certificate') }}"><x-icon name="lock" /><span class="sr-only">{{ __('Request certificate') }}</span></button></form>
                    @endif
                    @if ($a->status === 'active')
                        <a class="btn btn-sm" href="{{ route($area.'.domain-aliases.convert', $a) }}"><x-icon name="package" />{{ __('Make website') }}</a>
                    @endif
                    @if ($canManage && ! $a->isBusy())
                        <form method="POST" action="{{ route($area.'.domain-aliases.destroy', $a) }}" onsubmit="return confirm(@js(__('Remove :domain? Visitors and mail for this domain will no longer reach your website.', ['domain' => $a->domain])))">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-ghost" type="submit"><x-icon name="trash" /><span class="sr-only">{{ __('Remove') }}</span></button>
                        </form>
                    @endif
                </div>
            </td>
        </tr>
    @endforeach
</x-ui.table>
