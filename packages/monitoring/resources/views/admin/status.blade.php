<x-layouts.admin :title="__('Status page')">
    @include('monitoring::partials.styles')
    <div class="page-head">
        <div><h1>{{ __('Monitoring') }}</h1><p class="sub">{{ __('What customers see on the status page, and messages about maintenance or known problems.') }}</p></div>
        <div class="actions"><a class="btn" href="{{ route('status.public') }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open the status page') }}</a></div>
    </div>
    @include('monitoring::partials.tabs')
    @unless ($o->get('status_page'))<div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('The public status page is switched off. Customers still see it in their panel.') }}</div>@endunless

    <div class="bento">
        <x-ui.card class="span-7" :title="__('Platform, last 30 days')" flush>
            @foreach ($components as $key => $c)
                <div class="mo-comp">
                    <strong>{{ $c['label'] }}</strong>
                    <span class="mo-state {{ $c['status'] }}">{{ \Rhis\Monitoring\Severity::label($c['status']) }}@if ($c['uptime'] !== null) · {{ rtrim(rtrim(number_format($c['uptime'], 2), '0'), '.') }}%@endif</span>
                    <div class="bars">@include('monitoring::partials.bars', ['days' => $c['days'], 'size' => 'sm'])</div>
                </div>
            @endforeach
        </x-ui.card>
        <x-ui.card class="span-5" :title="__('New message')">
            <form method="POST" action="{{ route('admin.monitoring.notices.store') }}" class="form">@csrf
                <x-ui.field name="title" :label="__('Title')" required placeholder="{{ __('Planned maintenance on the mail server') }}" />
                <x-ui.field name="body" type="textarea" :label="__('Message')" rows="3" />
                <x-ui.field name="level" type="select" :label="__('Kind')">
                    <option value="maintenance">{{ __('Planned maintenance') }}</option><option value="info">{{ __('Information') }}</option>
                    <option value="degraded">{{ __('Slower or partly unavailable') }}</option><option value="outage">{{ __('Outage') }}</option>
                </x-ui.field>
                <fieldset class="form" style="border:0;padding:0;margin:0;gap:8px"><legend class="small" style="font-weight:600;margin-bottom:6px">{{ __('Affects') }}</legend>
                    @foreach ($components as $key => $c)<label class="check"><input type="checkbox" name="components[]" value="{{ $key }}"><span>{{ $c['label'] }}</span></label>@endforeach
                </fieldset>
                <div class="form-grid">
                    <x-ui.field name="starts_at" type="datetime-local" :label="__('From')" />
                    <x-ui.field name="ends_at" type="datetime-local" :label="__('Until')" />
                </div>
                <div><x-ui.button variant="dark" icon="plus">{{ __('Place message') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Messages')" flush>
        @if ($notices->isEmpty())
            <x-ui.empty icon="info">{{ __('No messages.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Title') }}</th><th>{{ __('Kind') }}</th><th>{{ __('When') }}</th><th>{{ __('State') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($notices as $n)
                <tr>
                    <td><strong>{{ $n->title }}</strong>@if ($n->body)<span class="small muted" style="display:block">{{ \Illuminate\Support\Str::limit($n->body, 90) }}</span>@endif</td>
                    <td class="small">{{ __(ucfirst($n->level)) }}</td>
                    <td class="small">{{ $n->starts_at?->isoFormat('D MMM HH:mm') ?? $n->created_at->isoFormat('D MMM HH:mm') }}@if ($n->ends_at) – {{ $n->ends_at->isoFormat('D MMM HH:mm') }}@endif</td>
                    <td>@if ($n->resolved_at)<x-ui.pill status="ok">{{ __('Solved') }}</x-ui.pill>@elseif ($n->ends_at && $n->ends_at->isPast())<x-ui.pill status="off">{{ __('Ended') }}</x-ui.pill>@elseif ($n->isUpcoming())<x-ui.pill status="info">{{ __('Planned') }}</x-ui.pill>@else<x-ui.pill status="warn">{{ __('Shown') }}</x-ui.pill>@endif</td>
                    <td class="num" style="white-space:nowrap">
                        @unless ($n->resolved_at)<form method="POST" action="{{ route('admin.monitoring.notices.resolve', $n) }}" style="display:inline">@csrf<x-ui.button size="sm" icon="check">{{ __('Solved') }}</x-ui.button></form>@endunless
                        <form method="POST" action="{{ route('admin.monitoring.notices.destroy', $n) }}" style="display:inline" data-confirm="{{ __('Remove this message?') }}">@csrf @method('DELETE')<x-ui.button size="sm" variant="danger" icon="trash"><span class="sr-only">{{ __('Remove') }}</span></x-ui.button></form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.admin>
