<x-layouts.admin :title="__('Migration status')">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.migration.index') }}">{{ __('Migration') }}</a></div>
            <h1>{{ __('Migration status') }}</h1>
            <p class="sub">{{ __('Progress of every domain being moved, with the log of each step. This page updates by itself.') }}</p>
        </div>
        <div class="actions"><span class="live" data-mig-live @if (! $counts['running'] && ! $counts['queued']) hidden @endif>{{ __('Live') }}</span></div>
    </div>
    @include('migration::admin.partials.tabs')

    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach

    @if ($rows->isEmpty())
        <x-ui.card><x-ui.empty icon="migrate">{{ __('No migrations yet. Choose a source server and the domains to move.') }}</x-ui.empty>
            <p style="text-align:center"><a class="btn btn-dark" href="{{ route('admin.migration.index') }}"><x-icon name="migrate" />{{ __('Go to source servers') }}</a></p></x-ui.card>
    @else
        <x-ui.card>
            <div class="kpi-row mig-kpis">
                @foreach (['running' => __('Running'), 'queued' => __('Queued'), 'copied' => __('Ready for DNS switch'), 'failed' => __('Failed'), 'finished' => __('Switched or finished')] as $k => $label)
                    <div><span>{{ $label }}</span><strong data-mig-count="{{ $k }}" @class(['mig-bad' => $k === 'failed' && $counts[$k] > 0])>{{ $counts[$k] }}</strong></div>
                @endforeach
            </div>
        </x-ui.card>

        <section class="bento mig-status">
            <x-ui.card class="span-7" :title="__('Domains')" flush>
                <ul class="mig-list" data-mig-list>
                    @foreach ($rows as $r)
                        @php $i = $r['item']; $p = $r['progress']; @endphp
                        <li data-id="{{ $i->id }}" @class(['is-selected' => $selected?->id === $i->id])>
                            <a class="mig-row" href="{{ route('admin.migration.status', ['item' => $i->id]) }}" data-select="{{ $i->id }}">
                                <span class="mig-name"><strong>{{ $i->domain }}</strong><small class="muted">{{ $i->source?->name }}</small></span>
                                <span class="mig-prog">
                                    <span class="mig-stages" aria-hidden="true">@for ($s = 1; $s <= 5; $s++)<i @class(['done' => $s < $p['stage'] || $i->status === 'done', 'now' => $s === $p['stage'] && $i->status !== 'done', 'bad' => $s === $p['stage'] && $i->status === 'failed'])></i>@endfor</span>
                                    <span class="mig-bar-line" role="progressbar" aria-label="{{ $i->domain }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $p['percent'] }}"><span data-f="bar" @class(['bad' => $i->status === 'failed', 'ok' => in_array($i->status, ['live', 'done'], true)]) style="width: {{ $p['percent'] }}%"></span></span>
                                    <small><span data-f="step">{{ $p['step'] }}</span> · <span data-f="percent">{{ $p['percent'] }}%</span></small>
                                </span>
                                <span class="mig-state"><span data-f="pill"><x-ui.pill :status="$i->statusPill()">{{ $statusLabels[$i->status] ?? $i->status }}</x-ui.pill></span><small class="muted" data-f="updated">{{ $i->updated_at?->diffForHumans() }}</small></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.card class="span-5 mig-logcard">
                <div class="mig-loghead">
                    <div><span class="eyebrow">{{ __('Log') }}</span><h2 data-mig-logtitle>{{ $selected?->domain }}</h2></div>
                    @if ($selected)<a class="btn btn-sm" data-mig-open href="{{ route('admin.migration.items.show', $selected) }}">{{ __('Open migration') }}</a>@endif
                </div>
                @include('migration::admin.partials.log', ['log' => $log])
                <p class="small muted">{{ __('Lines from the server agent are shown in grey. The log stays available after the migration.') }}</p>
            </x-ui.card>
        </section>
    @endif

    @include('migration::admin.styles')
    @if ($rows->isNotEmpty())
    <script>
    (() => {
        const feed = @js(route('admin.migration.status.feed'));
        const itemUrl = @js(route('admin.migration.items.show', ['item' => '__ID__']));
        const pillClass = { ok: 'pill-ok', warn: 'pill-warn', bad: 'pill-bad', neutral: 'pill-neutral', info: 'pill-info' };
        let selected = @js($selected?->id);
        let timer;
        const logBox = () => document.querySelector('[data-mig-log]');
        const atBottom = (el) => el.scrollHeight - el.scrollTop - el.clientHeight < 24;
        const scrollDown = () => { const el = logBox(); if (el) el.scrollTop = el.scrollHeight; };
        scrollDown();

        function renderLog(lines) {
            const el = logBox();
            const stick = atBottom(el);
            el.replaceChildren(...(lines.length ? lines : [{ time: '', level: 'info', text: @js(__('Nothing logged yet.')), agent: false }]).map(l => {
                const row = document.createElement('div');
                row.className = 'mig-line is-' + l.level + (l.agent ? ' is-agent' : '');
                const t = document.createElement('time'); t.textContent = l.time || ''; t.title = l.date || '';
                const s = document.createElement('span'); s.textContent = l.text;
                row.append(t, s);
                return row;
            }));
            if (stick) scrollDown();
        }

        async function refresh() {
            clearTimeout(timer);
            let data;
            try {
                const res = await fetch(feed + (selected ? '?item=' + selected : ''), { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error(res.status);
                data = await res.json();
            } catch (e) { timer = setTimeout(refresh, 15000); return; }
            for (const [k, v] of Object.entries(data.counts)) {
                const el = document.querySelector(`[data-mig-count="${k}"]`); if (el) el.textContent = v;
            }
            const moving = data.counts.running + data.counts.queued > 0;
            document.querySelector('[data-mig-live]').hidden = !moving;
            const list = document.querySelector('[data-mig-list]');
            const order = data.items.map(i => i.id);
            const known = new Set([...list.children].map(li => +li.dataset.id));
            if (order.some(id => !known.has(id))) { location.reload(); return; }
            for (const it of data.items) {
                const li = list.querySelector(`li[data-id="${it.id}"]`);
                li.querySelector('[data-f="step"]').textContent = it.step;
                li.querySelector('[data-f="percent"]').textContent = it.percent + '%';
                li.querySelector('[data-f="updated"]').textContent = it.updated || '';
                const bar = li.querySelector('[data-f="bar"]');
                bar.style.width = it.percent + '%';
                bar.classList.toggle('bad', it.status === 'failed');
                bar.classList.toggle('ok', it.status === 'live' || it.status === 'done');
                bar.parentElement.setAttribute('aria-valuenow', it.percent);
                const pill = li.querySelector('[data-f="pill"] .pill');
                if (pill) { const dot = document.createElement('span'); dot.className = 'dot'; dot.setAttribute('aria-hidden', 'true'); pill.className = 'pill ' + (pillClass[it.pill] || 'pill-neutral'); pill.replaceChildren(dot, it.label); }
                li.querySelectorAll('.mig-stages i').forEach((dot, n) => {
                    const s = n + 1;
                    dot.className = (s < it.stage || it.status === 'done') ? 'done' : (s === it.stage ? (it.status === 'failed' ? 'now bad' : 'now') : '');
                });
                list.appendChild(li);
            }
            if (data.selected === selected) renderLog(data.log);
            timer = setTimeout(refresh, moving ? 3000 : 15000);
        }

        document.addEventListener('click', (e) => {
            const a = e.target.closest('[data-select]');
            if (!a || e.metaKey || e.ctrlKey) return;
            e.preventDefault();
            selected = +a.dataset.select;
            document.querySelectorAll('[data-mig-list] li').forEach(li => li.classList.toggle('is-selected', +li.dataset.id === selected));
            document.querySelector('[data-mig-logtitle]').textContent = a.querySelector('.mig-name strong').textContent;
            const open = document.querySelector('[data-mig-open]'); if (open) open.href = itemUrl.replace('__ID__', selected);
            history.replaceState(null, '', a.href);
            refresh().then(scrollDown);
        });
        timer = setTimeout(refresh, 3000);
    })();
    </script>
    @endif
</x-layouts.admin>
