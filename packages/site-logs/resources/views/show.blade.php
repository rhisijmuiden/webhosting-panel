@php
    $v = fn (string $f) => @filemtime(public_path($f));
    $i18n = [
        'locale' => str_replace('_', '-', app()->getLocale()),
        'loading' => __('Loading…'),
        'failed' => __('The log could not be read.'),
        'empty' => __('This log is empty.'),
        'missing' => __('This log does not exist yet. It appears as soon as there is something to write.'),
        'no_match' => __('Nothing matches these filters.'),
        'truncated' => __('Only the most recent part of this file was searched. Narrow the search or pick an older file.'),
        'rotated' => __('The log was rotated: continuing in the new file.'),
        'current' => __('Current'),
        'today' => __('Today'),
        'paused' => __('Paused while you scroll'),
        'copied' => __('Copied'),
        'new_entries' => __(':count new'),
        'entries' => __(':count entries'),
        'updated' => __('Updated :time'),
        'requests' => __('Requests'),
        'entries_label' => __('Entries'),
        'errors' => __('Errors'),
        'warnings' => __('Warnings'),
        'avg_time' => __('Avg. response time'),
        'top_ips' => __('Top visitors (IP)'),
        'top_paths' => __('Most requested'),
        'not_found' => __('Not found (404)'),
        'tail_failed' => __('Live view stopped: the log could not be read.'),
        'live' => __('Live'),
        'live_view' => __('Live view'),
        'copy' => __('Copy line'),
        'levels' => ['error' => __('Error'), 'warning' => __('Warning'), 'notice' => __('Notice'), 'deprecated' => __('Deprecated'), 'info' => __('Info')],
    ];
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Logs').' · '.$website->domain">
    <link rel="stylesheet" href="{{ asset('assets/site-logs/site-logs.css') }}?v={{ $v('assets/site-logs/site-logs.css') }}">
    <script src="{{ asset('assets/site-logs/site-logs.js') }}?v={{ $v('assets/site-logs/site-logs.js') }}"></script>

    <div class="sl" x-data="siteLogs(@js($config + ['i18n' => $i18n]))" x-cloak>
        <div class="page-head">
            <div>
                <div class="crumbs small muted"><a href="{{ route($area.'.site-logs.index') }}">{{ __('Logs') }}</a> / {{ $website->domain }}</div>
                <h1>{{ $website->domain }}</h1>
                <div class="sub">{{ __('Search, filter and follow the logs of this website live.') }}</div>
            </div>
            <div class="actions">
                <a class="btn" href="{{ route($area.'.websites.show', $website) }}"><x-icon name="globe" />{{ __('Website') }}</a>
                <button type="button" class="btn" @click="download()" :disabled="!entries.length"><x-icon name="external" />{{ __('Download shown lines') }}</button>
            </div>
        </div>

        <div class="card sl-toolbar">
            <nav class="seg sl-tabs" aria-label="{{ __('Log') }}" role="tablist">
                @foreach (['access' => __('Access log'), 'error' => __('Error log'), 'php' => __('PHP errors'), 'fpm' => 'PHP-FPM'] as $key => $label)
                    <button type="button" role="tab" :aria-selected="log === '{{ $key }}'" :class="{ 'is-on': log === '{{ $key }}' }" @click="switchLog('{{ $key }}')">
                        <span>{{ $label }}<small x-text="sizeOf('{{ $key }}')"></small></span>
                    </button>
                @endforeach
            </nav>

            <div class="sl-controls">
                <label class="sl-search">
                    <x-icon name="search" />
                    <input class="input" type="search" x-model.debounce.400ms="q" @keydown.enter.prevent="reload()" placeholder="{{ __('Search… use -word to leave lines out') }}" aria-label="{{ __('Search') }}">
                </label>
                <select class="select" x-model="file" @change="reload()" aria-label="{{ __('File') }}">
                    <template x-for="f in filesFor(log)" :key="f.name">
                        <option :value="f.name" x-text="fileLabel(f)" :selected="f.name === file"></option>
                    </template>
                </select>
                <select class="select sl-lines" x-model.number="lines" @change="reload()" aria-label="{{ __('Lines') }}">
                    <option value="100">100 {{ __('lines') }}</option>
                    <option value="300">300 {{ __('lines') }}</option>
                    <option value="1000">1000 {{ __('lines') }}</option>
                </select>
                <button type="button" class="btn sl-live-btn" :class="{ 'is-on': live }" @click="toggleLive()" :disabled="!isCurrent()" :aria-pressed="live">
                    <span class="sl-pulse" aria-hidden="true"></span><span x-text="live ? i18n.live : i18n.live_view"></span>
                </button>
                <button type="button" class="btn" @click="reload()" :aria-busy="loading" title="{{ __('Refresh') }}"><x-icon name="refresh" /><span class="sr-only">{{ __('Refresh') }}</span></button>
            </div>

            <div class="sl-filters">
                <template x-if="log === 'access'">
                    <div class="sl-chips" role="group" aria-label="{{ __('Status') }}">
                        <span class="sl-chip-label">{{ __('Status') }}</span>
                        <template x-for="s in ['2xx','3xx','4xx','5xx']" :key="s">
                            <button type="button" class="sl-chip" :class="'st-' + s + (status.includes(s) ? ' is-on' : '')" @click="toggle(status, s)" :aria-pressed="status.includes(s)" x-text="s"></button>
                        </template>
                        <select class="select sl-method-select" x-model="method" @change="reload()" aria-label="{{ __('Method') }}">
                            <option value="">{{ __('All methods') }}</option>
                            <template x-for="m in ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS']" :key="m"><option :value="m" x-text="m"></option></template>
                        </select>
                    </div>
                </template>
                <template x-if="log !== 'access'">
                    <div class="sl-chips" role="group" aria-label="{{ __('Level') }}">
                        <span class="sl-chip-label">{{ __('Level') }}</span>
                        @foreach (['error' => __('Error'), 'warning' => __('Warning'), 'notice' => __('Notice'), 'deprecated' => __('Deprecated'), 'info' => __('Info')] as $key => $label)
                            <button type="button" class="sl-chip lv-{{ $key }}" :class="{ 'is-on': levels.includes('{{ $key }}') }" @click="toggle(levels, '{{ $key }}')" :aria-pressed="levels.includes('{{ $key }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                </template>
                <template x-if="ip">
                    <button type="button" class="sl-chip is-on sl-ip" @click="ip = ''; reload()">IP <span class="mono" x-text="ip"></span> <x-icon name="close" /></button>
                </template>
                <button type="button" class="btn btn-sm sl-reset" x-show="hasFilters()" @click="resetFilters()">{{ __('Clear filters') }}</button>
                <span class="sl-spacer"></span>
                <label class="check sl-order"><input type="checkbox" x-model="newestFirst"> {{ __('Newest first') }}</label>
            </div>
        </div>

        <section class="sl-insights" x-show="entries.length" aria-label="{{ __('Summary') }}">
            <div class="sl-kpis">
            <template x-for="k in kpis()" :key="k.label">
                <div class="card sl-kpi" :class="k.tone ? 'is-' + k.tone : ''">
                    <span class="label" x-text="k.label"></span>
                    <strong x-text="k.value"></strong>
                    <span class="hint" x-text="k.hint || ''"></span>
                </div>
            </template>
            </div>
            <div class="sl-tops" x-show="log === 'access'">
            <template x-if="log === 'access'">
                <div class="card sl-top">
                    <span class="label" x-text="i18n.top_ips"></span>
                    <ol>
                        <template x-for="row in top('ip')" :key="row.key">
                            <li><button type="button" class="mono" @click="ip = row.key; reload()" x-text="row.key"></button><span class="sl-bar" :style="'--w:' + row.pct + '%'"></span><span class="num" x-text="row.count"></span></li>
                        </template>
                    </ol>
                </div>
            </template>
            <template x-if="log === 'access'">
                <div class="card sl-top">
                    <span class="label" x-text="i18n.top_paths"></span>
                    <ol>
                        <template x-for="row in top('path')" :key="row.key">
                            <li><button type="button" class="mono" @click="q = row.key; reload()" x-text="row.key" :title="row.key"></button><span class="sl-bar" :style="'--w:' + row.pct + '%'"></span><span class="num" x-text="row.count"></span></li>
                        </template>
                    </ol>
                </div>
            </template>
            </div>
        </section>

        <div class="alert alert-info" x-show="notice" x-text="notice" role="status"></div>
        <div class="alert alert-bad" x-show="error" x-text="error" role="alert"></div>

        <div class="card card-flush sl-log">
            <div class="sl-log-head">
                <span class="small muted" x-text="summary()"></span>
                <span class="live" x-show="live && !paused">{{ __('Live') }}</span>
                <span class="small muted" x-show="live && paused" x-text="i18n.paused"></span>
                <button type="button" class="btn btn-sm" x-show="pending" @click="flush()" x-text="i18n.new_entries.replace(':count', pending)"></button>
            </div>
            <div class="sl-rows" x-ref="rows" @scroll.passive="onScroll()" :class="'is-' + log" role="log" aria-live="off">
                <template x-if="loading && !entries.length"><div class="empty">{{ __('Loading…') }}</div></template>
                <template x-if="!loading && !entries.length && !error"><div class="empty" x-text="emptyText()"></div></template>
                <template x-for="e in shown()" :key="e._id">
                    <div class="sl-row" :class="rowTone(e) + (open === e._id ? ' is-open' : '') + (e._new ? ' is-new' : '')">
                        <button type="button" class="sl-line" @click="open = open === e._id ? null : e._id" :aria-expanded="open === e._id">
                            <time class="sl-time" :datetime="e.time" x-text="fmtTime(e.time)"></time>
                            <template x-if="log === 'access'">
                                <span class="sl-main">
                                    <span class="sl-badge" :class="'st-' + statusClass(e.status)" x-text="e.status ?? '?'"></span>
                                    <span class="sl-method" x-text="e.method || ''"></span>
                                    <span class="sl-path" x-html="mark(e.path ?? e.raw)"></span>
                                </span>
                            </template>
                            <template x-if="log !== 'access'">
                                <span class="sl-main">
                                    <span class="sl-badge" :class="'lv-' + (e.level || 'info')" x-text="levelLabel(e.level)"></span>
                                    <span class="sl-path" x-html="mark(firstLine(e.message ?? e.raw))"></span>
                                </span>
                            </template>
                            <span class="sl-meta">
                                <span class="mono" x-show="e.ip" x-text="e.ip"></span>
                                <span x-show="log === 'access' && e.duration != null" x-text="fmtMs(e.duration)"></span>
                                <span x-show="log === 'access'" x-text="fmtBytes(e.bytes)"></span>
                                <span class="mono" x-show="e.file" x-text="shortFile(e.file, e.line)"></span>
                            </span>
                        </button>
                        <div class="sl-detail" x-show="open === e._id" x-transition.opacity>
                            <dl>
                                <template x-if="e.agent"><div><dt>{{ __('Browser') }}</dt><dd x-text="e.agent"></dd></div></template>
                                <template x-if="e.referer"><div><dt>{{ __('Came from') }}</dt><dd x-text="e.referer"></dd></div></template>
                                <template x-if="e.cache"><div><dt>{{ __('Page cache') }}</dt><dd x-text="e.cache"></dd></div></template>
                                <template x-if="e.request"><div><dt>{{ __('Request') }}</dt><dd class="mono" x-text="e.request"></dd></div></template>
                                <template x-if="e.file"><div><dt>{{ __('File') }}</dt><dd class="mono" x-text="e.file + (e.line ? ':' + e.line : '')"></dd></div></template>
                            </dl>
                            <pre class="sl-raw" x-html="mark(e.raw)"></pre>
                            <div class="sl-detail-actions">
                                <button type="button" class="btn btn-sm" x-show="e.ip" @click="ip = e.ip; reload()">{{ __('Only this IP') }}</button>
                                <button type="button" class="btn btn-sm" @click="copy(e)" x-text="copied === e._id ? i18n.copied : i18n.copy"></button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-dynamic-component>
