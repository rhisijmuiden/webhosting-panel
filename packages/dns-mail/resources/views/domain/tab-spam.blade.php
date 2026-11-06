@php
    $rules = $domain->senderRules->groupBy('type');
    $messages = collect($quarantine['messages'] ?? []);
@endphp
@if (! $domain->hasLocalMail())
    <x-ui.card><x-ui.empty icon="shield">{{ __('Spam for this domain is filtered by its mail provider.') }}</x-ui.empty></x-ui.card>
@else
<x-ui.card :title="__('Quarantine')" flush>
    <x-slot:actions>
        <span class="muted small">{{ __('Kept for 30 days, then deleted automatically.') }}</span>
    </x-slot:actions>
    @error('quarantine')<p class="error" style="padding:0 20px">{{ $message }}</p>@enderror
    @if ($quarantineError)
        <x-ui.empty icon="shield">{{ __('The quarantine could not be loaded: :error', ['error' => $quarantineError]) }}</x-ui.empty>
    @elseif ($domain->mailboxes->isEmpty())
        <x-ui.empty icon="shield">{{ __('No mailboxes yet, so nothing in quarantine.') }}</x-ui.empty>
    @elseif ($messages->isEmpty())
        <x-ui.empty icon="check">{{ __('The quarantine is empty. Nothing suspicious was held back.') }}</x-ui.empty>
    @else
        <form id="q-delete" method="POST" action="{{ $r('quarantine.delete') }}" onsubmit="return confirm(@js(__('Delete the selected messages? The spam filter learns from them.')))">@csrf</form>
        <div x-data="{ all: false }">
        <x-ui.table>
            <x-slot:head>
                <th style="width:32px"><input type="checkbox" aria-label="{{ __('Select all') }}" x-model="all" @change="$root.querySelectorAll('input[form=q-delete]').forEach(c => c.checked = all)"></th>
                <th>{{ __('From') }} / {{ __('Subject') }}</th><th>{{ __('For') }}</th><th class="num">{{ __('Score') }}</th><th>{{ __('Received') }}</th><th class="num">{{ __('Actions') }}</th>
            </x-slot:head>
            @foreach ($messages as $m)
                <tr>
                    <td><input type="checkbox" form="q-delete" name="messages[]" value="{{ $m['user'] . '|' . $m['uid'] }}" aria-label="{{ __('Select') }}"></td>
                    <td style="max-width:360px">
                        <span class="strong" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $m['subject'] !== '' ? $m['subject'] : __('(no subject)') }}</span>
                        <span class="muted small" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $m['from'] }}</span>
                    </td>
                    <td class="small">{{ $m['user'] }}</td>
                    <td class="num">@if ($m['virus'])<x-ui.pill status="bad">{{ __('Virus') }}</x-ui.pill>@else<x-ui.pill :status="$m['score'] >= 15 ? 'bad' : 'warn'">{{ $m['score'] }}</x-ui.pill>@endif</td>
                    <td class="small muted">{{ $m['date'] }}</td>
                    <td class="num">
                        @unless ($m['virus'])
                        <details style="display:inline-block;text-align:left">
                            <summary class="btn btn-ghost btn-sm">{{ __('Release') }}</summary>
                            <form method="POST" action="{{ $r('quarantine.release') }}" class="form" style="padding:10px 0;min-width:240px">
                                @csrf
                                <input type="hidden" name="user" value="{{ $m['user'] }}"><input type="hidden" name="uid" value="{{ $m['uid'] }}"><input type="hidden" name="sender" value="{{ $m['from'] }}">
                                <label class="check"><input type="checkbox" name="allow_sender" value="1"><span>{{ __('Always accept this sender') }}</span></label>
                                <div><button class="btn btn-dark btn-sm" type="submit"><x-icon name="check" />{{ __('Deliver to inbox') }}</button></div>
                            </form>
                        </details>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        </div>
        <div class="actions" style="padding:12px 20px">
            <button type="submit" form="q-delete" class="btn btn-ghost btn-sm"><x-icon name="trash" />{{ __('Delete selected') }}</button>
            @if ($quarantine['truncated'] ?? false)<span class="muted small">{{ __('Showing the newest 500 messages.') }}</span>@endif
        </div>
    @endif
</x-ui.card>

<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('Spam filter')">
        <form method="POST" action="{{ $r('spam') }}" class="form" x-data="{ q: @js($domain->spam_quarantine_score !== null ? 'on' : 'off') }">
            @csrf @method('PUT')
            <p class="muted small">{{ __('Every message gets a spam score. Known spam sources, forged senders and viruses score high; from 15 a message is refused.') }}</p>
            <div class="field">
                <span class="label">{{ __('Very suspicious mail') }}</span>
                <div class="seg" role="radiogroup" aria-label="{{ __('Very suspicious mail') }}">
                    <label><input type="radio" name="quarantine" value="on" x-model="q"><span>{{ __('Hold in quarantine') }}</span></label>
                    <label><input type="radio" name="quarantine" value="off" x-model="q"><span>{{ __('Deliver to Junk') }}</span></label>
                </div>
            </div>
            <div x-show="q === 'on'">
                <x-ui.field name="spam_quarantine_score" type="number" min="6" max="50" :label="__('Quarantine from score')" :value="$domain->spam_quarantine_score ?? $defaultQuarantineScore"
                    :help="__('Lower catches more spam but also more good mail. 10 is a safe start.')" />
            </div>
            <input type="hidden" name="spam_junk" value="0">
            <label class="check"><input type="checkbox" name="spam_junk" value="1" @checked($domain->spam_junk)><span>{{ __('Move likely spam to the Junk folder') }}<small>{{ __('From score 6. When off, it arrives in the inbox with a spam label.') }}</small></span></label>
            <div><button type="submit" class="btn btn-dark btn-sm">{{ __('Save') }}</button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Always accept or block')">
        @foreach (['allow' => __('Always accept'), 'block' => __('Always to Junk')] as $type => $label)
            @if ($rules->has($type))
                <p class="label" style="margin:0 0 6px">{{ $label }}</p>
                <x-ui.table>
                    @foreach ($rules[$type] as $rule)
                        <tr>
                            <td><span class="mono small">{{ $rule->isDomain() ? '*@' . $rule->value : $rule->value }}</span></td>
                            <td class="num"><form method="POST" action="{{ $r('spam.rules.destroy', [$rule->id]) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" type="submit" aria-label="{{ __('Remove') }}"><x-icon name="trash" /></button></form></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        @endforeach
        <form method="POST" action="{{ $r('spam.rules.store') }}" class="form">
            @csrf
            <x-ui.field name="value" :label="__('Sender')" placeholder="nieuwsbrief@leverancier.nl, leverancier.nl" required :help="__('An address, or a domain for every sender at it.')" />
            <div class="seg" role="radiogroup" aria-label="{{ __('List') }}">
                <label><input type="radio" name="type" value="allow" checked><span>{{ __('Always accept') }}</span></label>
                <label><input type="radio" name="type" value="block"><span>{{ __('Always to Junk') }}</span></label>
            </div>
            <div><button type="submit" class="btn btn-dark btn-sm"><x-icon name="plus" />{{ __('Add') }}</button></div>
        </form>
    </x-ui.card>
</section>
@endif
