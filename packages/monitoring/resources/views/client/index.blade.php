@php use Rhis\Monitoring\Severity; @endphp
<x-layouts.client :title="__('Status')">
    @include('monitoring::partials.styles')
    @php
        $down = $sites->filter(fn ($s) => $s['check']?->status === 'critical');
        $overall = Severity::worst(array_merge(array_column($components, 'status'), $down->isNotEmpty() ? ['critical'] : []));
    @endphp
    <div class="page-head">
        <div><h1>{{ __('Status') }}</h1><p class="sub">{{ __('We check your websites every few minutes, day and night.') }}</p></div>
        @if ($publicPage)<div class="actions"><a class="btn" href="{{ route('status.public') }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Public status page') }}</a></div>@endif
    </div>

    <div class="mo-banner {{ $overall }}" role="status">
        <span class="mo-pulse" aria-hidden="true"></span>
        <div><strong>{{ $down->isNotEmpty() ? trans_choice(':count of your websites does not answer|:count of your websites do not answer', $down->count()) : ($overall === 'ok' ? __('Everything works') : __('Some services are partly unavailable')) }}</strong>
            @if ($down->isNotEmpty())<div class="small">{{ __('We have been alerted and are looking into it.') }}</div>@endif</div>
    </div>

    <x-ui.card :title="__('Your websites')" flush>
        @if ($sites->isEmpty())
            <x-ui.empty icon="globe">{{ __('You have no websites yet.') }}</x-ui.empty>
        @else
            @foreach ($sites as $s)
                @php $c = $s['check']; $ssl = $s['ssl']; @endphp
                <div class="mo-comp">
                    <div style="min-width:0"><strong>{{ $s['website']->domain }}</strong>
                        <span class="small muted" style="display:block">
                            @if ($c && ($c->value['ms'] ?? null) !== null){{ __('Answers in :n ms', ['n' => $c->value['ms']]) }} · @endif
                            {{ __('30 days') }}: {{ $s['uptime30'] !== null ? rtrim(rtrim(number_format($s['uptime30'], 2), '0'), '.').'%' : '–' }}
                            @if ($ssl && ($ssl->value['days_left'] ?? null) !== null) · {{ __('Certificate valid for :n more days', ['n' => $ssl->value['days_left']]) }}@endif
                        </span></div>
                    <span class="mo-state {{ $s['website']->status !== 'active' ? 'unknown' : ($c?->status === 'critical' ? 'critical' : ($c ? 'ok' : 'unknown')) }}">
                        {{ $s['website']->status !== 'active' ? __('Not active') : ($c === null ? __('Not checked yet') : ($c->status === 'critical' ? __('Not reachable') : __('Online'))) }}</span>
                    <div class="bars">@include('monitoring::partials.bars', ['days' => $s['days'], 'size' => 'sm'])
                        <div class="mo-axis"><span>{{ __('90 days ago') }}</span><span>{{ __('Today') }}</span></div></div>
                </div>
            @endforeach
        @endif
    </x-ui.card>

    @if ($incidents->isNotEmpty())
        <x-ui.card :title="__('Interruptions in the last 30 days')" flush>
            <ul class="mo-incidents">
                @foreach ($incidents as $i)
                    <li><span class="mo-dot {{ $i->resolved_at ? '' : 'critical' }}" aria-hidden="true"></span>
                        <strong>{{ $i->title }}</strong>
                        @if ($i->resolved_at)<x-ui.pill status="ok">{{ __('Solved') }}</x-ui.pill>@else<x-ui.pill status="bad">{{ __('Ongoing') }}</x-ui.pill>@endif
                        <span class="meta">{{ $i->started_at->timezone(config('app.timezone'))->isoFormat('D MMM HH:mm') }} · {{ __('not reachable for :duration', ['duration' => $i->duration()]) }}</span></li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <h2 style="margin:8px 0 0;font-size:18px">{{ __('Our platform') }}</h2>
    @include('monitoring::partials.platform', ['barSize' => 'sm'])

    @if ($alertsAllowed)
    <x-ui.card :title="__('Mail me when my website goes down')" id="alerts">
        <form method="POST" action="{{ route('client.monitoring.alerts') }}" class="form">
            @csrf @method('PUT')
            <input type="hidden" name="enabled" value="0">
            <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $contact->enabled))>
                <span>{{ __('Send me a mail when one of my websites does not answer, and when it works again') }}<small>{{ __('Sent to :email.', ['email' => $customer->email]) }}</small></span></label>
            <x-ui.field name="extra_emails" :label="__('Also send to')" :value="implode(', ', $contact->extra_emails ?? [])" placeholder="webbouwer@example.nl"
                :help="__('For example your web developer. Up to 5 addresses, separated by commas.')" />
            <div><x-ui.button variant="dark">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
    @endif
</x-layouts.client>
