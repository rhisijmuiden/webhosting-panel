{{-- One smart update: outcome, what changed, the screenshots side by side, the log. --}}
@php
    use Rhis\WordPress\SmartUpdates\Models\UpdatePolicy;
    $tz = config('server-admin.timezone') ?: config('app.timezone');
    $tone = match ($run->status) { 'updated', 'up_to_date' => 'ok', 'rolled_back', 'notified' => 'warn', 'failed' => 'bad', default => 'info' };
    $icon = match ($run->status) { 'updated', 'up_to_date' => 'check', 'rolled_back' => 'shield', 'notified' => 'mail', 'failed' => 'close', default => 'refresh' };
    $title = match ($run->status) {
        'running' => __('Updating :site…', ['site' => $install->label()]),
        'updated' => __('Updated, the website looks the same'),
        'rolled_back' => __('Rolled back, the website kept working'),
        'notified' => __('Updates are waiting'),
        'up_to_date' => __('Everything was up to date'),
        default => __('The update failed'),
    };
    $text = match ($run->status) {
        'running' => __('Screenshots, restore point, update and comparison. This takes a few minutes; the page refreshes by itself.'),
        'updated' => __('The screenshots after the update match the ones from before, so the update was kept.'),
        'rolled_back' => ($run->reasonLabel() ?? '').'. '.__('The restore point from just before the update was put back.'),
        'notified' => __('This site is set to "notify only": nothing was changed.'),
        'up_to_date' => __('There was nothing to update for this setting.'),
        default => $run->error ?: __('The update could not be finished.'),
    };
    $changes = $run->changes();
    $viewports = ['desktop' => __('Desktop'), 'mobile' => __('Mobile')];
    $img = fn ($name) => route($area.'.wordpress.smart.runs.image', [$run, $name]);
@endphp
<div class="su-page">
<div class="page-head">
    <div>
        <p class="small" style="margin:0 0 4px"><a href="{{ route($area.'.wordpress.smart.index', ['tab' => 'history']) }}">{{ __('Smart updates') }}</a> / <a href="{{ route($area.'.wordpress.smart.install', $install) }}">{{ $install->label() }}</a></p>
        <h1>{{ __('Update of :date', ['date' => $run->started_at?->timezone($tz)->isoFormat('D MMMM YYYY HH:mm')]) }}</h1>
        <p class="sub">{{ $run->trigger === 'manual' ? __('Started by hand') . ($run->started_by ? ' ('.$run->started_by.')' : '') : __('Scheduled in the maintenance window') }} · {{ UpdatePolicy::modeLabel($run->mode) }}</p>
    </div>
    <div class="actions">
        <x-ui.button icon="external" :href="$install->url()" target="_blank" rel="noopener">{{ __('Open website') }}</x-ui.button>
        <x-ui.button icon="settings" :href="route($area.'.wordpress.smart.install', $install)">{{ __('Settings') }}</x-ui.button>
    </div>
</div>

<div class="su-banner {{ $tone }}" role="status" @if ($run->status === 'running') x-data x-init="setInterval(() => fetch(@js(route($area.'.wordpress.smart.runs.status', $run)), {headers: {Accept: 'application/json'}}).then(r => r.json()).then(d => { if (d.status !== 'running') location.reload() }), 6000)" @endif>
    <span @class(['ic', 'spin' => $run->status === 'running'])><x-icon :name="$icon" /></span>
    <div><h2>{{ $title }}</h2><p>{{ $text }}</p></div>
</div>

@if ($run->status !== 'running')
<section class="grid grid-stats">
    <div class="card stat"><span class="label">{{ $run->status === 'notified' ? __('Updates waiting') : __('Updated') }}</span><strong class="value">{{ count($changes) }}</strong><span class="hint">{{ __('WordPress, plugins and themes') }}</span></div>
    <div class="card stat"><span class="label">{{ __('Largest visual difference') }}</span><strong class="value">{{ $run->max_score !== null ? number_format($run->max_score, 1).'%' : '–' }}</strong><span class="hint">{{ __('Limit :limit%', ['limit' => $run->threshold]) }}</span></div>
    <div class="card stat"><span class="label">{{ __('Website status') }}</span><strong class="value">{{ $run->health ? ($run->health['before'] ?? '?').' → '.($run->health['after'] ?? '?') : '–' }}</strong><span class="hint">{{ __('HTTP before → after') }}</span></div>
    <div class="card stat"><span class="label">{{ __('Duration') }}</span><strong class="value">{{ $run->duration() ?? '–' }}</strong><span class="hint">{{ $run->finished_at?->timezone($tz)->isoFormat('HH:mm') }}</span></div>
</section>
@endif

@if ($changes)
<x-ui.card :title="$run->status === 'notified' ? __('Waiting updates') : ($run->status === 'rolled_back' ? __('Tried, then put back') : __('What was updated'))" flush>
    <x-ui.table class="su-changes">
        <x-slot:head><th><span class="sr-only">{{ __('Type') }}</span></th><th>{{ __('Part') }}</th><th>{{ __('Version') }}</th></x-slot:head>
        @foreach ($changes as $c)
            <tr>
                <td><span class="su-type" title="{{ ['core' => 'WordPress', 'plugin' => __('Plugin'), 'theme' => __('Theme')][$c['type']] }}"><x-icon :name="['core' => 'wordpress', 'plugin' => 'apps', 'theme' => 'palette'][$c['type']]" /></span></td>
                <td class="strong">{{ $c['name'] }} <span class="muted small">{{ ['core' => 'WordPress', 'plugin' => __('Plugin'), 'theme' => __('Theme')][$c['type']] }}</span></td>
                <td class="mono">{{ $c['from'] ?? '?' }}<span class="su-arrow">→</span>{{ $c['to'] ?? '?' }}</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
@endif

@if ($run->pages)
<div class="su-shots">
    @foreach ($run->pages as $p)
        @php
            $imgs = $p['images'] ?? [];
            $views = array_values(array_filter(['slider' => isset($imgs['before'], $imgs['after']) ? __('Slider') : null, 'before' => isset($imgs['before']) ? __('Before') : null,
                'after' => isset($imgs['after']) ? __('After') : null, 'diff' => isset($imgs['diff']) ? __('Differences') : null, 'restored' => isset($imgs['restored']) ? __('Put back') : null]));
            $keys = array_keys(array_filter(['slider' => isset($imgs['before'], $imgs['after']), 'before' => isset($imgs['before']), 'after' => isset($imgs['after']), 'diff' => isset($imgs['diff']), 'restored' => isset($imgs['restored'])]));
            $start = isset($imgs['diff']) && ($p['score'] ?? 0) > ($run->threshold ?? 10) ? 'diff' : ($keys[0] ?? 'before');
        @endphp
        <x-ui.card class="su-shot su-shot-{{ $p['viewport'] }}" x-data="{ view: '{{ $start }}', pos: 50 }">
            <x-slot:title>{{ $p['page'] === '/' ? __('Home page') : $p['page'] }} · {{ $viewports[$p['viewport']] ?? $p['viewport'] }}</x-slot:title>
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
                <div class="seg" role="radiogroup" aria-label="{{ __('View') }}">
                    @foreach ($keys as $i => $k)
                        <label><input type="radio" value="{{ $k }}" x-model="view"><span>{{ $views[$i] }}</span></label>
                    @endforeach
                </div>
                @include('wordpress::smart-updates.partials.score', ['score' => $p['score'] ?? null, 'threshold' => $run->threshold ?? 10])
            </div>
            @if (isset($imgs['before'], $imgs['after']))
                <div x-show="view === 'slider'" x-cloak>
                    <div class="su-view"><div class="su-compare">
                        <img src="{{ $img($imgs['after']) }}" alt="{{ __('After the update') }}" loading="lazy">
                        <div class="top" :style="'clip-path: inset(0 ' + (100 - pos) + '% 0 0)'"><img src="{{ $img($imgs['before']) }}" alt="{{ __('Before the update') }}" loading="lazy"></div>
                        <div class="handle" :style="'left: ' + pos + '%'"></div>
                        <span class="tag" style="left:10px">{{ __('Before') }}</span><span class="tag" style="right:10px">{{ __('After') }}</span>
                    </div></div>
                    <label class="sr-only" for="su-pos-{{ $loop->index }}">{{ __('Slide between before and after') }}</label>
                    <input id="su-pos-{{ $loop->index }}" class="su-range" type="range" min="0" max="100" x-model="pos">
                </div>
            @endif
            @foreach (['before', 'after', 'diff', 'restored'] as $k)
                @isset($imgs[$k])
                    <div x-show="view === '{{ $k }}'" @if ($k !== $start) x-cloak @endif class="su-view"><img src="{{ $img($imgs[$k]) }}" alt="{{ $k }}" loading="lazy"></div>
                @endisset
            @endforeach
            <div class="su-legend" x-show="view === 'diff'" x-cloak>
                <span><i style="background:rgba(230,40,60,.6)"></i>{{ __('Changed') }}</span>
                <span><i style="background:rgba(120,130,150,.45)"></i>{{ __('Moves by itself, not counted') }}</span>
            </div>
            @if (($p['restored_score'] ?? null) !== null)
                <p class="muted small" style="margin:10px 0 0">{{ __('After putting it back: :score% different from before.', ['score' => number_format($p['restored_score'], 1)]) }}</p>
            @endif
            @if ($p['blank'] ?? false)
                <p class="small" style="margin:10px 0 0;color:var(--status-offline)">{{ __('The page was empty after the update.') }}</p>
            @endif
        </x-ui.card>
    @endforeach
</div>
@elseif (! empty($run->notes['visual_errors']) && $run->status !== 'running')
    <div class="alert alert-warn"><x-icon name="info" /><div>{{ __('No screenshots were compared:') }} {{ implode(' · ', array_slice($run->notes['visual_errors'], 0, 3)) }}</div></div>
@endif

@if ($run->log)
<x-ui.card :title="__('Log')">
    <details @if ($run->status === 'failed') open @endif><summary class="small" style="cursor:pointer;color:var(--accent)">{{ __('Show the log') }}</summary>
        <pre class="su-log">{{ $run->log }}</pre></details>
</x-ui.card>
@endif
</div>
