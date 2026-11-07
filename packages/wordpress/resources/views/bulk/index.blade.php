@php
    use Rhis\WordPress\Bulk\BulkActions;
    use Rhis\WordPress\Bulk\Models\BulkRunItem;
@endphp
<x-layouts.admin :title="__('WordPress bulk actions')">
    @include('wordpress::bulk.styles')
    <script src="{{ asset('assets/wp-bulk.js') }}?v={{ @filemtime(public_path('assets/wp-bulk.js')) }}"></script>
    <form method="POST" action="{{ route('admin.wordpress.bulk.store') }}" id="wpb-form" class="wpb"
          x-data="wpBulk(@js(['sites' => $sites, 'actions' => $actionMeta, 'action' => $chosen, 'filter' => $initialFilter, 'component' => $initialComponent, 'old' => array_map('intval', (array) old('ids', []))]))"
          @submit="if (! ready) $event.preventDefault()">
        @csrf
        <div class="page-head">
            <div>
                <div class="crumbs"><a href="{{ route('admin.wordpress.index') }}">WordPress</a></div>
                <h1>{{ __('Bulk actions') }}</h1>
                <p class="sub">{{ __('Run one action on many WordPress sites at once: a few sites at a time, with the result per site.') }}</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="wpb-banner" role="alert"><x-icon name="info" /><div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
        @endif

        <x-ui.card>
            <x-slot:title><span class="wpb-step"><b>1</b>{{ __('Choose an action') }}</span></x-slot:title>
            @if (empty($actions))
                <x-ui.empty icon="wordpress">{{ __('No bulk actions are available.') }}</x-ui.empty>
            @endif
            @foreach ($grouped as $group => $list)
                <div class="wpb-group">
                    <h3>{{ BulkActions::groupLabel($group) }}</h3>
                    <div class="wpb-tiles" role="radiogroup" aria-label="{{ BulkActions::groupLabel($group) }}">
                        @foreach ($list as $key => $a)
                            <label @class(['wpb-tile', 'is-danger' => $a->dangerous()])>
                                <input type="radio" name="action" value="{{ $key }}" x-model="action" @checked($chosen === $key)>
                                <span class="ico"><x-icon :name="$a->icon()" /></span>
                                <span><strong>{{ $a->label() }}</strong>@if ($a->description())<small>{{ $a->description() }}</small>@endif</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @foreach ($actions as $key => $a)
                @php $fields = $a->fields(); @endphp
                @if ($fields)
                    <fieldset class="wpb-options" x-show="action === @js($key)" x-cloak :disabled="action !== @js($key)" @disabled($chosen !== $key)>
                        <legend>{{ __('Options') }}: {{ $a->label() }}</legend>
                        @foreach ($fields as $f)
                            @php
                                $name = "options[{$key}][{$f['name']}]";
                                $id = 'wpb-'.$key.'-'.$f['name'];
                                $value = old("options.{$key}.{$f['name']}", $prefill[$f['name']] ?? $f['default']);
                            @endphp
                            @switch($f['type'])
                                @case('note')
                                    <p class="note">{{ $f['label'] }}</p>
                                    @break
                                @case('checkbox')
                                    <label class="check"><input type="checkbox" name="{{ $name }}" value="1" @checked($errors->any() ? old("options.{$key}.{$f['name']}") : $f['default'])>
                                        <span>{{ $f['label'] }}@if ($f['help'])<small>{{ $f['help'] }}</small>@endif</span></label>
                                    @break
                                @case('select')
                                    <div class="field"><label for="{{ $id }}">{{ $f['label'] }}</label>
                                        <select id="{{ $id }}" name="{{ $name }}">
                                            @foreach ($f['options'] as $v => $l)<option value="{{ $v }}" @selected((string) $value === (string) $v)>{{ $l }}</option>@endforeach
                                        </select>
                                        @if ($f['help'])<span class="help">{{ $f['help'] }}</span>@endif
                                        @error("options.{$f['name']}")<span class="error">{{ $message }}</span>@enderror
                                    </div>
                                    @break
                                @default
                                    <div class="field"><label for="{{ $id }}">{{ $f['label'] }}</label>
                                        <input id="{{ $id }}" type="{{ $f['type'] === 'number' ? 'number' : 'text' }}" name="{{ $name }}" value="{{ $value }}"
                                               @if (! empty($f['placeholder'])) placeholder="{{ $f['placeholder'] }}" @endif
                                               @isset($f['min']) min="{{ $f['min'] }}" @endisset @isset($f['max']) max="{{ $f['max'] }}" @endisset
                                               @if ($f['type'] === 'text') list="wpb-components" autocomplete="off" @endif>
                                        @if ($f['help'])<span class="help">{{ $f['help'] }}</span>@endif
                                        @error("options.{$f['name']}")<span class="error">{{ $message }}</span>@enderror
                                    </div>
                            @endswitch
                        @endforeach
                    </fieldset>
                @endif
            @endforeach
            <datalist id="wpb-components">@foreach ($components->unique('name') as $c)<option value="{{ $c['name'] }}">{{ $c['title'] }}</option>@endforeach</datalist>
        </x-ui.card>

        <x-ui.card flush>
            <x-slot:title><span class="wpb-step"><b>2</b>{{ __('Choose the sites') }}</span></x-slot:title>
            <div class="wpb-chips" role="group" aria-label="{{ __('Quick filters') }}">
                @foreach (['all' => __('All'), 'outdated' => __('Outdated'), 'core' => __('Core update'), 'failed' => __('Last update failed'), 'unchecked' => __('Not checked')] as $k => $l)
                    <button type="button" class="wpb-chip" :aria-pressed="filter === @js($k)" @click="filter = @js($k)">{{ $l }} <span class="n" x-text="countFor(@js($k))"></span></button>
                @endforeach
            </div>
            <div class="wpb-filters">
                <label class="search"><x-icon name="search" /><input type="search" x-model.debounce.150ms="q" placeholder="{{ __('Domain or customer') }}" aria-label="{{ __('Search') }}"></label>
                <select x-model="customer" aria-label="{{ __('Customer') }}">
                    <option value="">{{ __('All customers') }}</option>
                    @foreach ($customers as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach
                </select>
                <select x-model="version" aria-label="{{ __('WordPress version') }}">
                    <option value="">{{ __('Every version') }}</option>
                    @foreach ($versions as $v)<option value="{{ $v }}">WordPress {{ $v }}</option>@endforeach
                </select>
                <select x-model="component" aria-label="{{ __('Plugin or theme') }}">
                    <option value="">{{ __('Any plugin or theme') }}</option>
                    <optgroup label="{{ __('Plugins') }}">@foreach ($components->where('type', 'plugin') as $c)<option value="plugin:{{ $c['name'] }}">{{ $c['title'] ?: $c['name'] }}</option>@endforeach</optgroup>
                    <optgroup label="{{ __('Themes') }}">@foreach ($components->where('type', 'theme') as $c)<option value="theme:{{ $c['name'] }}">{{ $c['title'] ?: $c['name'] }}</option>@endforeach</optgroup>
                </select>
                <select x-model="componentMode" x-show="component" x-cloak aria-label="{{ __('Plugin filter') }}">
                    <option value="has">{{ __('is installed') }}</option>
                    <option value="update">{{ __('has an update') }}</option>
                    <option value="missing">{{ __('is not installed') }}</option>
                </select>
                <select x-model="cron" aria-label="WP-cron">
                    <option value="">{{ __('WP-cron: any') }}</option>
                    <option value="system">{{ __('WP-cron: server') }}</option>
                    <option value="wordpress">{{ __('WP-cron: WordPress') }}</option>
                </select>
                <button type="button" class="btn btn-sm" x-show="filtered" x-cloak @click="resetFilters()">{{ __('Clear filters') }}</button>
            </div>
            <div class="wpb-selbar">
                <span class="grow">
                    <strong x-text="@js(__(':n of :t sites shown')).replace(':n', shown.length).replace(':t', sites.length)"></strong>
                    <span class="muted" x-show="hiddenSelected" x-cloak x-text="@js(__('(:n selected sites are hidden by the filters)')).replace(':n', hiddenSelected)"></span>
                </span>
                <button type="button" class="btn btn-sm" @click="selectShown()" :disabled="! shown.length"><x-icon name="check" />{{ __('Select all shown') }}</button>
                <button type="button" class="btn btn-sm" @click="selected = []" :disabled="! selected.length">{{ __('Clear selection') }}</button>
            </div>
            <template x-for="id in selected" :key="'h' + id"><input type="hidden" name="ids[]" :value="id"></template>
            @if ($sites->isEmpty())
                <x-ui.empty icon="wordpress">{{ __('No WordPress found yet. Sites are checked every night.') }}</x-ui.empty>
            @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th class="check"><input type="checkbox" :checked="allShownSelected" @change="$event.target.checked ? selectShown() : unselectShown()" aria-label="{{ __('Select all shown') }}"></th>
                        <th>{{ __('Website') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Version') }}</th><th>{{ __('Updates') }}</th><th class="wpb-hide-sm">WP-cron</th><th class="wpb-hide-sm">{{ __('Last update') }}</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="s in shown.slice(0, limit)" :key="s.id">
                            <tr :class="{ 'is-selected': isSelected(s.id) }" @click="if ($event.target.closest('a,input') === null) toggle(s.id)" style="cursor:pointer">
                                <td class="check"><input type="checkbox" :checked="isSelected(s.id)" @change="toggle(s.id)" :aria-label="s.label"></td>
                                <td><a class="strong" :href="s.url" x-text="s.label"></a>
                                    <template x-if="componentTags(s).length"><div class="wpb-tags"><template x-for="t in componentTags(s)"><span class="wpb-tag" :class="{ 'is-up': t.up }" x-text="t.name"></span></template></div></template>
                                </td>
                                <td x-text="s.customer || '–'"></td>
                                <td><span x-text="s.version || '–'"></span> <span class="pill pill-warn" x-show="s.core_update" x-text="@js(__(':v available')).replace(':v', s.core_update)"></span></td>
                                <td>
                                    <span class="pill pill-warn" x-show="s.outdated" x-text="s.outdated + ' ' + (s.outdated === 1 ? @js(__('update')) : @js(__('updates')))"></span>
                                    <span class="pill pill-ok" x-show="! s.outdated && s.checked">{{ __('Up to date') }}</span>
                                    <span class="pill pill-neutral" x-show="! s.checked">{{ __('Not checked yet') }}</span>
                                    <span class="pill pill-bad" x-show="s.error">{{ __('Error') }}</span>
                                </td>
                                <td class="wpb-hide-sm" x-text="s.cron === 'system' ? @js(__('Server')) : 'WordPress'"></td>
                                <td class="wpb-hide-sm">
                                    <span class="pill" :class="s.last_update === 'succeeded' ? 'pill-ok' : (s.last_update ? 'pill-bad' : 'pill-neutral')" x-show="s.last_update"
                                          x-text="({ succeeded: @js(__('Succeeded')), failed: @js(__('Failed')), rolled_back: @js(__('Rolled back')) })[s.last_update] || s.last_update"></span>
                                    <span class="muted" x-show="! s.last_update">–</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div style="padding:12px 20px" x-show="shown.length > limit" x-cloak>
                <button type="button" class="btn btn-sm" @click="limit += 200" x-text="@js(__('Show :n more')).replace(':n', Math.min(200, shown.length - limit))"></button>
            </div>
            <div x-show="! shown.length" x-cloak><x-ui.empty icon="search">{{ __('No site matches these filters.') }}</x-ui.empty></div>
            @endif
        </x-ui.card>

        @if ($runs->isNotEmpty())
            <x-ui.card :title="__('Recent bulk runs')" flush>
                <x-ui.table>
                    <x-slot:head><th>{{ __('Started') }}</th><th>{{ __('Action') }}</th><th>{{ __('Sites') }}</th><th>{{ __('Result') }}</th><th class="wpb-hide-sm">{{ __('By') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                    @foreach ($runs as $r)
                        @php $c = $r->counts(); @endphp
                        <tr>
                            <td>{{ $r->started_at?->diffForHumans() }}</td>
                            <td><strong>{{ $r->actionLabel() }}</strong>@if ($r->summary)<div class="muted small">{{ $r->summary }}</div>@endif</td>
                            <td>{{ $r->total }}</td>
                            <td>
                                <x-ui.pill :status="match ($r->status) { 'running' => 'info', 'paused' => 'warn', 'cancelled' => 'neutral', default => $c['failed'] ? 'bad' : 'ok' }">{{ $r->statusLabel() }}</x-ui.pill>
                                <span class="muted small">{{ __(':ok done, :failed failed, :skipped skipped', ['ok' => $c['succeeded'] + $c['attention'], 'failed' => $c['failed'], 'skipped' => $c['skipped']]) }}</span>
                            </td>
                            <td class="wpb-hide-sm">{{ $r->user_name ?? '–' }}</td>
                            <td class="num"><a class="btn btn-sm" href="{{ route('admin.wordpress.bulk.show', $r) }}">{{ __('Details') }}</a></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        <div class="wpb-dock" x-show="sites.length" x-cloak>
            <div class="what">
                <strong x-text="action ? actions[action].label : @js(__('Choose an action'))"></strong>
                <span x-text="selected.length ? @js(__(':n sites selected')).replace(':n', selected.length) : @js(__('No sites selected yet'))"></span>
            </div>
            <label>{{ __('At the same time') }}
                <select name="concurrency" x-model.number="concurrency">@foreach ([1, 2, 3, 4, 5, 8, 10] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select>
            </label>
            <label>{{ __('Pause after') }}
                <select name="stop_after_failures" x-model.number="stopAfter">
                    <option value="0">{{ __('never') }}</option>
                    @foreach ([1, 3, 5, 10] as $n)<option value="{{ $n }}">{{ trans_choice(':count failure|:count failures', $n) }}</option>@endforeach
                </select>
            </label>
            <button type="button" class="btn btn-primary" :disabled="! action || ! selected.length" @click="$refs.confirm.showModal()"><x-icon name="bolt" />{{ __('Run') }}</button>
        </div>

        <dialog class="modal" x-ref="confirm" aria-labelledby="wpb-confirm-title">
            <div class="modal-head"><h2 id="wpb-confirm-title" style="margin:0;font-size:18px">{{ __('Start this bulk action?') }}</h2></div>
            <div class="modal-body">
                <p style="margin:0"><strong x-text="action ? actions[action].label : ''"></strong>
                    <span x-text="@js(__('on :n sites, :c at the same time.')).replace(':n', selected.length).replace(':c', concurrency)"></span></p>
                <p class="muted small" style="margin:0" x-show="action && actions[action].perWebsite">{{ __('This action runs once per website, also when a website has more than one WordPress install.') }}</p>
                <p class="muted small" style="margin:0">{{ __('You can follow every site live and cancel at any time. Sites that are busy finish first.') }}</p>
                <template x-if="action && actions[action].dangerous">
                    <label class="field"><span class="label">{{ __('This is hard to undo. Type :word to confirm.', ['word' => __('confirm')]) }}</span>
                        <input type="text" name="confirm" autocomplete="off" x-model="confirmWord"></label>
                </template>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" @click="$refs.confirm.close()">{{ __('Back') }}</button>
                <button type="submit" class="btn btn-primary" @click="ready = true" :disabled="action && actions[action].dangerous && confirmWord.trim().toLowerCase() !== @js(mb_strtolower(__('confirm')))"><x-icon name="bolt" />{{ __('Start') }}</button>
            </div>
        </dialog>
    </form>

</x-layouts.admin>
