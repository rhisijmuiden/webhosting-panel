<x-layouts.admin :title="__('Web application firewall')">
    @include('waf::partials.styles')
    <div class="page-head">
        <div>
            <h1>{{ __('Web application firewall') }}</h1>
            <p class="sub">{{ __('The firewall mode of every website. Sites set to "Server default" follow the setting on the Settings tab (now: :mode).', ['mode' => \Rhis\Waf\Http\AdminWafController::modeLabel($defaultMode)]) }}</p>
        </div>
        <form method="GET" action="{{ route('admin.waf.sites') }}" class="actions"><input class="input" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search domain') }}" aria-label="{{ __('Search domain') }}"></form>
    </div>
    @include('waf::partials.admin-tabs')
    @include('waf::partials.alerts')
    <x-ui.card flush>
        @if ($websites->isEmpty())
            <x-ui.empty icon="globe">{{ __('No websites found.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Mode') }}</th><th class="num">{{ __('Blocked, 7 days') }}</th><th class="num">{{ __('Detected, 7 days') }}</th><th class="num">{{ __('Exceptions') }}</th><th>{{ __('Change') }}</th></x-slot:head>
            @foreach ($websites as $w)
                @php $row = $rows[$w->id] ?? null; $mode = $waf->modeFor($row); $c = $counts[$w->id] ?? null; @endphp
                <tr>
                    <td><a class="strong" href="{{ route('admin.waf.site', $w) }}">{{ $w->domain }}</a></td>
                    <td class="small">{{ $w->customer?->displayName() }}</td>
                    <td>@include('waf::partials.mode-pill', ['mode' => $mode])@if ($row?->mode === null)<small class="muted block">{{ __('Server default') }}</small>@endif</td>
                    <td class="num">{{ number_format((int) ($c->blocked ?? 0)) }}</td>
                    <td class="num">{{ number_format((int) ($c->detected ?? 0)) }}</td>
                    <td class="num">{{ count($row?->exceptions ?? []) }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.waf.site.update', $w) }}" style="display:flex;gap:6px">
                            @csrf @method('PUT')
                            <select class="select" name="mode" aria-label="{{ __('Mode of :domain', ['domain' => $w->domain]) }}" onchange="this.form.requestSubmit()">
                                <option value="default" @selected($row?->mode === null)>{{ __('Server default') }}</option>
                                <option value="block" @selected($row?->mode === 'block')>{{ __('Blocking attacks') }}</option>
                                <option value="detect" @selected($row?->mode === 'detect')>{{ __('Detect only') }}</option>
                                <option value="off" @selected($row?->mode === 'off')>{{ __('Off') }}</option>
                            </select>
                            <noscript><button class="btn btn-sm" type="submit">{{ __('Save') }}</button></noscript>
                        </form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($websites->hasPages())<div style="padding:12px 20px">{{ $websites->links('partials.pagination') }}</div>@endif
        @endif
    </x-ui.card>
</x-layouts.admin>
