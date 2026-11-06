@if ($domain->provider === 'microsoft365')
    <x-ui.card>
        <x-ui.empty icon="mail">{{ __('Mailboxes for this domain live in Microsoft 365. Manage them in the Microsoft 365 admin center; users read mail in Outlook.') }}
            <x-slot:action><a class="btn" href="https://admin.microsoft.com/#/users" target="_blank" rel="noopener">{{ __('Open the admin center') }}<x-icon name="external" /></a></x-slot:action>
        </x-ui.empty>
    </x-ui.card>
@elseif ($domain->provider === 'external')
    <x-ui.card><x-ui.empty icon="mail">{{ __('Mail for this domain is handled by another provider.') }}</x-ui.empty></x-ui.card>
@else
<x-ui.card :title="__('Mailboxes')" flush>
    <x-slot:actions>
        <span class="muted small">{{ $mailboxLimit === null ? trans_choice(':count mailbox|:count mailboxes', $mailboxCount, ['count' => $mailboxCount]) : __(':used of :max mailboxes in your package', ['used' => $mailboxCount, 'max' => $mailboxLimit]) }}</span>
    </x-slot:actions>
    @if ($domain->mailboxes->isEmpty())
        <x-ui.empty icon="mail">{{ __('No mailboxes yet.') }}</x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head><th>{{ __('Address') }}</th><th>{{ __('Storage') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Actions') }}</th></x-slot:head>
        @foreach ($domain->mailboxes as $box)
            <tr>
                <td><span class="strong">{{ $box->local_part . '@' . $domain->domain }}</span>@if ($box->display_name)<br><span class="muted small">{{ $box->display_name }}</span>@endif</td>
                <td style="min-width:160px">
                    @if ($box->usedPercent() !== null)<x-ui.meter :label="__('Storage')" :percent="$box->usedPercent()" />@endif
                    <span class="muted small">{{ $box->used_kb !== null ? number_format($box->used_kb / 1024, 0, ',', '.') . ' MB' : '–' }} / {{ $box->quota_mb ? number_format($box->quota_mb, 0, ',', '.') . ' MB' : __('unlimited') }}</span>
                </td>
                <td>@if ($box->enabled)<x-ui.pill status="ok">{{ __('Active') }}</x-ui.pill>@else<x-ui.pill status="off">{{ __('Login off') }}</x-ui.pill>@endif
                    @include('dns-mail::extras.autoreply-pill', ['reply' => $autoreplies[$box->id] ?? null])</td>
                <td class="num">
                    <div class="actions" style="justify-content:flex-end">
                        <a class="btn btn-ghost btn-sm" href="{{ $r('mailboxes.profile', [$box->id]) }}" title="{{ __('Profile for iPhone, iPad and Mac') }}">{{ __('Apple profile') }}</a>
                        <form method="POST" action="{{ $r('mailboxes.destroy', [$box->id]) }}" onsubmit="return confirm(@js(__('Remove :address? It stops receiving mail at once.', ['address' => $box->local_part . '@' . $domain->domain])))">
                            @csrf @method('DELETE')<button class="btn btn-ghost btn-sm" type="submit" aria-label="{{ __('Remove') }}"><x-icon name="trash" /></button>
                        </form>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="4" style="padding-top:0">
                    <details>
                        <summary class="small" style="cursor:pointer;font-weight:600">{{ __('Edit') }}</summary>
                        <form method="POST" action="{{ $r('mailboxes.update', [$box->id]) }}" class="form" style="margin-top:12px">
                            @csrf @method('PUT')
                            <div class="form-grid">
                                <x-ui.field name="display_name" :label="__('Name')" :value="$box->display_name" />
                                <x-ui.field name="quota_mb" type="number" min="0" :label="__('Storage (MB, 0 = unlimited)')" :value="$box->quota_mb" />
                                <x-ui.field name="password" type="password" :label="__('New password')" autocomplete="new-password" :help="__('Leave empty to keep the current password.')" />
                                <x-ui.field name="password_confirmation" type="password" :label="__('Repeat password')" autocomplete="new-password" />
                            </div>
                            <x-ui.field name="send_as" :label="__('May also send as')" :value="implode(', ', (array) $box->send_as)" :placeholder="'info@' . $domain->domain" :help="__('Other addresses at this domain this mailbox may send from.')" />
                            <input type="hidden" name="enabled" value="0">
                            <label class="check"><input type="checkbox" name="enabled" value="1" @checked($box->enabled)><span>{{ __('Login allowed') }}<small>{{ __('When off, the mailbox still receives mail but nobody can log in.') }}</small></span></label>
                            <div><button type="submit" class="btn btn-dark btn-sm">{{ __('Save') }}</button></div>
                        </form>
                    </details>
                    @include('dns-mail::extras.autoreply', ['reply' => $autoreplies[$box->id] ?? null])
                </td>
            </tr>
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>

<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('New mailbox')">
        <form method="POST" action="{{ $r('mailboxes.store') }}" class="form">
            @csrf
            <div class="field">
                <label for="f-local_part">{{ __('Address') }} <span aria-hidden="true">*</span></label>
                <div class="actions" style="flex-wrap:nowrap;gap:6px"><input id="f-local_part" name="local_part" class="input" value="{{ old('local_part') }}" required autocomplete="off" style="flex:1"><span class="muted">{{ '@' . $domain->domain }}</span></div>
                @error('local_part')<span class="error">{{ $message }}</span>@enderror
            </div>
            <x-ui.field name="display_name" :label="__('Name')" />
            <div class="form-grid">
                <x-ui.field name="password" type="password" :label="__('Password')" autocomplete="new-password" required :help="__('At least 10 characters.')" />
                <x-ui.field name="password_confirmation" type="password" :label="__('Repeat password')" autocomplete="new-password" required />
            </div>
            <x-ui.field name="quota_mb" type="number" min="0" :label="__('Storage (MB, 0 = unlimited)')" :value="2048" required />
            <div><button type="submit" class="btn btn-primary" @disabled($mailboxLimit !== null && $mailboxCount >= $mailboxLimit)><x-icon name="plus" />{{ __('Create mailbox') }}</button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Forwarding')">
        @if ($domain->aliases->reject->isCatchAll()->isNotEmpty())
            <x-ui.table>
                @foreach ($domain->aliases->reject->isCatchAll() as $alias)
                    <tr>
                        <td><span class="strong">{{ ($alias->isCatchAll() ? '*' : $alias->local_part) . '@' . $domain->domain }}</span><br><span class="muted small">→ {{ implode(', ', $alias->targets) }}</span></td>
                        <td class="num"><form method="POST" action="{{ $r('aliases.destroy', [$alias->id]) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" type="submit" aria-label="{{ __('Remove') }}"><x-icon name="trash" /></button></form></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
        <form method="POST" action="{{ $r('aliases.store') }}" class="form">
            @csrf
            <div class="field">
                <label for="f-alias">{{ __('Address') }}</label>
                <div class="actions" style="flex-wrap:nowrap;gap:6px"><input id="f-alias" name="local_part" class="input" placeholder="info" required style="flex:1"><span class="muted">{{ '@' . $domain->domain }}</span></div>
                <span class="help">{{ __('For all unknown addresses, use the catch-all below.') }}</span>
            </div>
            <x-ui.field name="targets" :label="__('Forward to')" :placeholder="'jan@' . $domain->domain . ', jan@gmail.com'" required :help="__('One or more addresses, separated by commas.')" />
            <div><button type="submit" class="btn btn-dark"><x-icon name="send" />{{ __('Save forwarding') }}</button></div>
        </form>
    </x-ui.card>
</section>

@include('dns-mail::extras.catch-all')

@include('dns-mail::domain.setup')
@endif
