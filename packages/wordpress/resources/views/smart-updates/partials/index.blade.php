{{-- Smart updates overview, shared by the admin and client screens. --}}
@php
    use Rhis\WordPress\SmartUpdates\Models\UpdatePolicy;
    $tz = config('server-admin.timezone') ?: config('app.timezone');
    $tabUrl = fn ($t) => route($area.'.wordpress.smart.index', $t === 'sites' ? [] : ['tab' => $t]);
@endphp
<div class="su-page">
<div class="page-head">
    <div>
        <h1>{{ __('Smart updates') }}</h1>
        <p class="sub">{{ __('WordPress, plugins and themes are updated automatically at night. Screenshots from before and after the update are compared: if the website looks different or gives an error, it is put back straight away.') }}</p>
    </div>
    <div class="actions">
        <x-ui.button icon="wordpress" :href="route($area.'.wordpress.index')">{{ __('WordPress sites') }}</x-ui.button>
    </div>
</div>

@if (session('status'))<div class="alert alert-ok" role="status"><x-icon name="check" /><div>{{ session('status') }}</div></div>@endif
@if ($errors->any())<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $errors->first() }}</div></div>@endif

<section class="grid grid-stats">
    <div class="card stat"><span class="label">{{ __('Automatic updates') }}</span><strong class="value">{{ $summary['automatic'] }}<span class="muted" style="font-size:16px"> / {{ $summary['installs'] }}</span></strong>
        <span class="hint">{{ __(':a all updates, :s security only', ['a' => $summary['modes']['all'] ?? 0, 's' => $summary['modes']['security'] ?? 0]) }}</span></div>
    <div class="card stat"><span class="label">{{ __('Updated') }}</span><strong class="value">{{ $summary['updated'] }}</strong><span class="hint">{{ __('Last 7 days') }}</span></div>
    <a class="card stat" href="{{ route($area.'.wordpress.smart.index', ['tab' => 'history', 'status' => 'rolled_back']) }}"><span class="label">{{ __('Rolled back') }}</span><strong class="value" @if ($summary['rolled_back']) style="color:var(--status-warning)" @endif>{{ $summary['rolled_back'] }}</strong><span class="hint">{{ __('Last 7 days, website kept working') }}</span></a>
    <div class="card stat"><span class="label">{{ __('Next maintenance window') }}</span>
        <strong class="value" style="font-size:22px">{{ $summary['next_window'] ? $summary['next_window']->timezone($tz)->isoFormat('dd D MMM HH:mm') : '–' }}</strong>
        <span class="hint">{{ $summary['server_window'] ? __('Server maintenance window :w', ['w' => $summary['window']]) : __('Every night :w', ['w' => $summary['window']]) }}</span></div>
</section>

@include('wordpress::smart-updates.partials.steps')

<nav class="su-tabs" aria-label="{{ __('Smart updates') }}">
    @foreach (array_filter(['sites' => __('Websites'), 'history' => __('History'), 'settings' => $area === 'admin' ? __('Settings') : null]) as $t => $label)
        <a href="{{ $tabUrl($t) }}" @class(['btn', 'btn-sm', 'btn-dark' => $tab === $t, 'btn-ghost' => $tab !== $t]) @if ($tab === $t) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@if ($tab === 'sites')
<x-ui.card :title="__('Websites')" flush>
    <x-slot:actions>
        <a href="{{ route($area.'.wordpress.smart.index') }}" @class(['btn', 'btn-sm', 'btn-dark' => ! $filter, 'btn-ghost' => $filter])>{{ __('All') }}</a>
        @foreach (UpdatePolicy::MODES as $m)
            <a href="{{ route($area.'.wordpress.smart.index', ['mode' => $m]) }}" @class(['btn', 'btn-sm', 'btn-dark' => $filter === $m, 'btn-ghost' => $filter !== $m])>{{ UpdatePolicy::modeLabel($m) }}</a>
        @endforeach
    </x-slot:actions>
    @if ($installs->isEmpty())
        <x-ui.empty icon="wordpress">{{ $filter ? __('No sites with this setting.') : __('No WordPress found yet. Sites are checked every night.') }}</x-ui.empty>
    @else
    <form method="POST" action="{{ route($area.'.wordpress.smart.bulk') }}" x-data="{ n: 0 }" @change="n = $el.querySelectorAll('input[name=\'installs[]\']:checked').length">
        @csrf
        <x-ui.table>
            <x-slot:head>
                @if ($mayChange)<th style="width:36px"><label class="sr-only" for="su-all">{{ __('Select all') }}</label><input id="su-all" type="checkbox" @click="$el.closest('table').querySelectorAll('input[name=\'installs[]\']').forEach(c => c.checked = $el.checked)"></th>@endif
                <th>{{ __('Website') }}</th>
                @if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif
                <th>{{ __('Updates') }}</th><th>{{ __('Screenshot check') }}</th><th>{{ __('Last result') }}</th><th class="num"><span class="sr-only">{{ __('Actions') }}</span></th>
            </x-slot:head>
            @foreach ($installs as $i)
                @php $p = $policies[$i->id] ?? new UpdatePolicy(['wordpress_install_id' => $i->id]); $r = $last[$i->id] ?? null; @endphp
                <tr>
                    @if ($mayChange)<td><input type="checkbox" name="installs[]" value="{{ $i->id }}" aria-label="{{ $i->label() }}"></td>@endif
                    <td><a class="strong" href="{{ route($area.'.wordpress.smart.install', $i) }}">{{ $i->label() }}</a>
                        <span class="muted small" style="display:block">WordPress {{ $i->version ?? '?' }}@if ($i->isOutdated()) · {{ trans_choice(':count update|:count updates', $i->outdatedCount()) }}@endif</span></td>
                    @if ($area === 'admin')<td>{{ $i->website->customer?->displayName() ?? '–' }}</td>@endif
                    <td>@include('wordpress::smart-updates.partials.mode', ['policy' => $p])</td>
                    <td class="small">
                        @if (! in_array($p->effectiveMode(), ['all', 'security'], true))<span class="muted">–</span>
                        @elseif ($p->visualEnabled())<x-ui.pill status="ok">{{ __('On') }}</x-ui.pill> <span class="muted">{{ __('limit :limit%', ['limit' => $p->effectiveThreshold()]) }} · {{ trans_choice(':count page|:count pages', count($p->pageList())) }}</span>
                        @else<x-ui.pill>{{ __('Off') }}</x-ui.pill> <span class="muted">{{ __('health check only') }}</span>@endif
                    </td>
                    <td class="small">
                        @if ($r)
                            <a href="{{ route($area.'.wordpress.smart.runs.show', $r) }}"><x-ui.pill :status="$r->pill()">{{ $r->statusLabel() }}</x-ui.pill></a>
                            <span class="muted">{{ ($r->finished_at ?? $r->started_at)?->diffForHumans() }}</span>
                        @else
                            <span class="muted">{{ __('Not run yet') }}</span>
                        @endif
                    </td>
                    <td class="num">
                        <x-ui.button size="sm" variant="ghost" icon="settings" :href="route($area.'.wordpress.smart.install', $i)">{{ __('Settings') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($mayChange)
        <div class="su-bulk">
            <span class="muted small" x-text="n ? @js(__('Selected:')) + ' ' + n : @js(__('Select sites to change them together.'))">{{ __('Select sites to change them together.') }}</span>
            <label class="sr-only" for="su-bulk-mode">{{ __('Updates') }}</label>
            <select id="su-bulk-mode" name="mode" class="select">
                <option value="default">{{ __('Panel default (:mode)', ['mode' => UpdatePolicy::modeLabel($options->get('default_mode'))]) }}</option>
                @foreach (UpdatePolicy::MODES as $m)<option value="{{ $m }}">{{ UpdatePolicy::modeLabel($m) }}</option>@endforeach
            </select>
            <x-ui.button size="sm" variant="primary" icon="check" x-bind:disabled="! n">{{ __('Apply') }}</x-ui.button>
        </div>
        @endif
    </form>
    @endif
</x-ui.card>
@elseif ($tab === 'history')
<x-ui.card :title="__('History')" flush>
    <x-slot:actions>
        @foreach ([null => __('All'), 'updated' => __('Updated'), 'rolled_back' => __('Rolled back'), 'failed' => __('Failed'), 'notified' => __('Updates waiting')] as $s => $label)
            <a href="{{ route($area.'.wordpress.smart.index', array_filter(['tab' => 'history', 'status' => $s ?: null])) }}" @class(['btn', 'btn-sm', 'btn-dark' => request('status') === ($s ?: null), 'btn-ghost' => request('status') !== ($s ?: null)])>{{ $label }}</a>
        @endforeach
    </x-slot:actions>
    @if ($history->isEmpty())
        <x-ui.empty icon="clock">{{ __('No smart updates yet. They run in the next maintenance window.') }}</x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head>
            <th>{{ __('When') }}</th><th>{{ __('Website') }}</th>@if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif
            <th>{{ __('Result') }}</th><th>{{ __('Changes') }}</th><th>{{ __('Visual difference') }}</th><th class="num"><span class="sr-only">{{ __('Actions') }}</span></th>
        </x-slot:head>
        @foreach ($history as $r)
            <tr>
                <td class="small"><span title="{{ $r->started_at?->timezone($tz)->isoFormat('D MMM YYYY HH:mm') }}">{{ $r->started_at?->timezone($tz)->isoFormat('dd D MMM HH:mm') }}</span>
                    <span class="muted" style="display:block">{{ $r->trigger === 'manual' ? __('By hand') : __('Scheduled') }} · {{ UpdatePolicy::modeLabel($r->mode) }}</span></td>
                <td>{{ $r->install?->label() }}</td>
                @if ($area === 'admin')<td>{{ $r->install?->website?->customer?->displayName() ?? '–' }}</td>@endif
                <td><x-ui.pill :status="$r->pill()">{{ $r->statusLabel() }}</x-ui.pill>@if ($r->reasonLabel())<span class="muted small" style="display:block">{{ $r->reasonLabel() }}</span>@endif</td>
                <td>{{ $r->status === 'notified' ? trans_choice(':count waiting|:count waiting', $r->changeCount()) : trans_choice(':count part|:count parts', $r->changeCount()) }}</td>
                <td>@include('wordpress::smart-updates.partials.score', ['score' => $r->max_score, 'threshold' => $r->threshold ?? 10])</td>
                <td class="num"><x-ui.button size="sm" variant="ghost" :href="route($area.'.wordpress.smart.runs.show', $r)">{{ __('Details') }}</x-ui.button></td>
            </tr>
        @endforeach
    </x-ui.table>
    <div style="padding: 12px 16px">{{ $history->links() }}</div>
    @endif
</x-ui.card>
@else
    @include('wordpress::smart-updates.partials.settings')
@endif
</div>
