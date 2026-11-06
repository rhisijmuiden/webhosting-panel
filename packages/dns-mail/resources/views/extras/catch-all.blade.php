{{-- Catch-all of the domain: what happens to mail for addresses that do not exist. --}}
@php
    $catchAll = $domain->aliases->first->isCatchAll();
    $targets = $catchAll?->targets ?? [];
    $boxAddresses = $domain->mailboxes->mapWithKeys(fn ($b) => [$b->id => $b->local_part . '@' . $domain->domain]);
    $toBox = count($targets) === 1 ? $boxAddresses->search($targets[0]) : false;
    $mode = old('mode', $catchAll === null ? 'off' : ($toBox !== false ? 'mailbox' : 'forward'));
@endphp
<x-ui.card :title="__('Catch-all')">
    <x-slot:actions>
        @if ($catchAll)<x-ui.pill status="info">{{ __('On') }}</x-ui.pill>@else<x-ui.pill status="off">{{ __('Off') }}</x-ui.pill>@endif
    </x-slot:actions>
    <p class="muted small" style="margin-top:0">{{ __('What happens to mail for addresses at :domain that do not exist, such as a typo or an old address.', ['domain' => $domain->domain]) }}</p>
    <form method="POST" action="{{ $r('catchall') }}" class="form" x-data="{ mode: @js($mode) }">
        @csrf @method('PUT')
        <div class="seg" role="radiogroup" aria-label="{{ __('Catch-all') }}">
            <label><input type="radio" name="mode" value="off" x-model="mode" @checked($mode === 'off')><span>{{ __('Refuse') }}</span></label>
            <label><input type="radio" name="mode" value="mailbox" x-model="mode" @checked($mode === 'mailbox') @disabled($domain->mailboxes->isEmpty())><span>{{ __('Deliver to a mailbox') }}</span></label>
            <label><input type="radio" name="mode" value="forward" x-model="mode" @checked($mode === 'forward')><span>{{ __('Forward') }}</span></label>
        </div>
        <p class="small muted" x-show="mode === 'off'" @if ($mode !== 'off') style="display:none" @endif>{{ __('Recommended. The sender gets a bounce right away, so nothing gets lost unnoticed.') }}</p>
        <div x-show="mode === 'mailbox'" @if ($mode !== 'mailbox') style="display:none" @endif>
            <x-ui.field name="mailbox_id" type="select" :label="__('Mailbox')">
                @foreach ($boxAddresses as $id => $addr)<option value="{{ $id }}" @selected((int) old('mailbox_id', $toBox) === $id)>{{ $addr }}</option>@endforeach
            </x-ui.field>
        </div>
        <div x-show="mode === 'forward'" @if ($mode !== 'forward') style="display:none" @endif>
            <x-ui.field name="targets" :label="__('Forward to')" :value="$toBox === false ? implode(', ', $targets) : ''" :placeholder="'jan@' . $domain->domain . ', jan@gmail.com'" :help="__('One or more addresses, separated by commas.')" />
        </div>
        <div class="alert alert-warn" role="note" x-show="mode !== 'off'" @if ($mode === 'off') style="display:none" @endif><x-icon name="shield" />
            <div class="small">{{ __('A catch-all also receives spam sent to made-up addresses. Existing mailboxes and forwardings keep getting their own mail.') }}</div>
        </div>
        <div><button type="submit" class="btn btn-dark"><x-icon name="check" />{{ __('Save catch-all') }}</button></div>
    </form>
</x-ui.card>
