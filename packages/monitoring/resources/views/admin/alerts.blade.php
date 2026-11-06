<x-layouts.admin :title="__('Alerts')">
    @include('monitoring::partials.styles')
    <div class="page-head"><div><h1>{{ __('Monitoring') }}</h1><p class="sub">{{ __('Where alerts go. Every channel gets one message per check round with everything that is new.') }}</p></div></div>
    @include('monitoring::partials.tabs')

    <x-ui.card :title="__('Alert channels')" flush>
        @forelse ($channels as $ch)
            <div class="mo-channel">
                <span class="ico {{ $ch->type }}" aria-hidden="true">@if ($ch->type === 'teams')T @else<x-icon name="mail" />@endif</span>
                <div style="min-width:0">
                    <strong>{{ $ch->name }}</strong> @if (! $ch->enabled)<x-ui.pill status="off">{{ __('Off') }}</x-ui.pill>@endif
                    <div class="small muted" style="overflow-wrap:anywhere">{{ $ch->type === 'teams' ? 'Microsoft Teams' : __('Mail') }} · {{ $ch->maskedTarget() }} · {{ $ch->min_severity === 'critical' ? __('only critical') : __('warnings and critical') }}{{ $ch->recoveries ? ' · '.__('also when solved') : '' }} · {{ strtoupper($ch->locale) }}</div>
                    @if ($ch->last_error)<div class="small" style="color:var(--bad-ink)">{{ __('Last send failed: :error', ['error' => $ch->last_error]) }}</div>
                    @elseif ($ch->last_sent_at)<div class="small muted">{{ __('Last sent :time', ['time' => $ch->last_sent_at->diffForHumans()]) }}</div>@endif
                </div>
                <div class="acts">
                    <form method="POST" action="{{ route('admin.monitoring.channels.test', $ch) }}">@csrf<x-ui.button size="sm" icon="send">{{ __('Send test') }}</x-ui.button></form>
                    <form method="POST" action="{{ route('admin.monitoring.channels.update', $ch) }}">@csrf @method('PUT')<input type="hidden" name="toggle" value="1"><x-ui.button size="sm">{{ $ch->enabled ? __('Switch off') : __('Switch on') }}</x-ui.button></form>
                    <form method="POST" action="{{ route('admin.monitoring.channels.destroy', $ch) }}" data-confirm="{{ __('Remove :name?', ['name' => $ch->name]) }}">@csrf @method('DELETE')<x-ui.button size="sm" variant="danger" icon="trash"><span class="sr-only">{{ __('Remove') }}</span></x-ui.button></form>
                </div>
            </div>
        @empty
            <x-ui.empty icon="send">{{ __('No alert channels. Nobody gets an alert.') }}</x-ui.empty>
        @endforelse
    </x-ui.card>

    <div class="grid grid-2">
        <x-ui.card :title="__('Add mail addresses')">
            <form method="POST" action="{{ route('admin.monitoring.channels.store') }}" class="form">@csrf
                <input type="hidden" name="type" value="mail">
                <x-ui.field name="name" :label="__('Name')" :value="old('type') === 'mail' ? old('name') : ''" placeholder="{{ __('On-call') }}" required />
                <x-ui.field name="target" :label="__('Addresses')" :value="old('type') === 'mail' ? old('target') : ''" placeholder="beheer@rhis.nl, 0612345678@sms.example"
                    :help="__('Separated by commas. Leave empty to mail every administrator in their own language.')" />
                @include('monitoring::admin.channel-options')
                <div><x-ui.button variant="dark" icon="plus">{{ __('Add') }}</x-ui.button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Add Microsoft Teams')">
            <form method="POST" action="{{ route('admin.monitoring.channels.store') }}" class="form">@csrf
                <input type="hidden" name="type" value="teams">
                <x-ui.field name="name" :label="__('Name')" :value="old('type') === 'teams' ? old('name') : ''" placeholder="{{ __('Teams: Hosting') }}" required />
                <x-ui.field name="target" type="password" :label="__('Workflow web address')" placeholder="https://…logic.azure.com/workflows/…" required />
                <ol class="mo-steps">
                    <li>{{ __('In Teams, open the channel, click … and choose Workflows.') }}</li>
                    <li>{{ __('Pick "Post to a channel when a webhook request is received" and finish the steps.') }}</li>
                    <li>{{ __('Copy the web address it shows and paste it here.') }}</li>
                </ol>
                @include('monitoring::admin.channel-options')
                <div><x-ui.button variant="dark" icon="plus">{{ __('Add') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Customers')">
        <p class="small muted" style="margin:0">
            {{ $o->get('customer_alerts') ? __('Customers can choose under Status in their panel to get a mail when their website goes down and when it works again.') : __('Customers do not get alerts. Switch this on under Settings.') }}
        </p>
    </x-ui.card>
</x-layouts.admin>
