/*
 * Statistics page: long-term graphs with uPlot (vendor/uplot, MIT).
 * - every [data-chart] loads its series from the data URL for the current period
 * - drag across a graph to zoom in (opens that period), double-click to zoom out
 * - the "per customer or website" graph has its own controls and a legend
 *   in which every series can be switched off and on
 */
(function () {
    'use strict';

    const page = document.querySelector('.stats-page');
    if (!page) return;

    const url = page.dataset.statsUrl;
    const range = JSON.parse(page.dataset.statsRange || '{}');
    const t = JSON.parse(page.dataset.statsI18n || '{}');
    const locale = t.locale || document.documentElement.lang || 'en';
    const charts = [];

    // ---- formatting -------------------------------------------------------
    const nf = (d) => new Intl.NumberFormat(locale, { maximumFractionDigits: d });
    function scaled(v, base, units, digits) {
        let i = 0;
        let n = Math.abs(v);
        while (n >= base && i < units.length - 1) { n /= base; i++; }
        return nf(n < 10 && i > 0 ? digits : 0).format(Math.sign(v) * n) + ' ' + units[i];
    }
    const fmt = {
        percent: (v) => nf(1).format(v) + '%',
        bytes: (v) => scaled(v, 1024, ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], 1),
        // Network samples are bytes per second; show bits per second like a switch does.
        bps: (v) => scaled(v * 8, 1000, ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s', 'Tbit/s'], 1),
        count: (v) => (Math.abs(v) >= 10000 ? new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(v) : nf(0).format(v)),
        number: (v) => nf(2).format(v),
    };
    const value = (unit, v) => (v == null ? '–' : (fmt[unit] || fmt.number)(v));

    const dtf = (o) => new Intl.DateTimeFormat(locale, o);
    const fTime = dtf({ hour: '2-digit', minute: '2-digit' });
    const fDay = dtf({ day: 'numeric', month: 'short' });
    const fMonth = dtf({ month: 'short', year: 'numeric' });
    const fFull = dtf({ weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    const fDate = dtf({ weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

    function xValues(u, splits, ai, space, incr) {
        return splits.map((s) => {
            const d = new Date(s * 1000);
            if (incr >= 28 * 86400) return fMonth.format(d);
            if (incr >= 86400) return fDay.format(d);
            if (d.getHours() === 0 && d.getMinutes() === 0) return fDay.format(d);
            return fTime.format(d);
        });
    }

    // ---- colors from the huisstijl tokens (light and dark) ----------------
    function css(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }
    function palette() {
        return {
            ink: css('--ink-muted', '#56627C'),
            grid: css('--line-soft', '#EDF0F6'),
            server: [css('--series-1', '#2D6BCF'), css('--series-2', '#E0533F')],
            cat: [1, 2, 3, 4, 5, 6, 7, 8].map((i) => css('--cat-' + i, '#888')),
            other: css('--cat-other', '#9AA3B5'),
        };
    }

    function seriesColor(colors, s, i, breakdown) {
        if (!breakdown) return colors.server[i] || colors.cat[i % 8];
        return s.slot === 'other' ? colors.other : colors.cat[(s.slot - 1) % 8];
    }

    // ---- tooltip ---------------------------------------------------------
    function tooltipPlugin(state) {
        let tip;
        return {
            hooks: {
                init: (u) => {
                    tip = document.createElement('div');
                    tip.className = 'stats-tip';
                    tip.hidden = true;
                    u.over.appendChild(tip);
                    u.over.addEventListener('mouseleave', () => { tip.hidden = true; });
                },
                setCursor: (u) => {
                    const i = u.cursor.idx;
                    if (i == null || u.cursor.left < 0) { tip.hidden = true; return; }
                    const ts = u.data[0][i];
                    const day = state.data.resolution === 'day';
                    let html = '<strong>' + (day ? fDate : fFull).format(new Date(ts * 1000)) + '</strong>';
                    const rows = [];
                    for (let k = 1; k < u.series.length; k++) {
                        if (!u.series[k].show) continue;
                        rows.push({ k, v: u.data[k][i] });
                    }
                    if (state.breakdown) rows.sort((a, b) => (b.v ?? -1) - (a.v ?? -1));
                    for (const r of rows) {
                        html += '<span><i class="swatch" style="background:' + u.series[r.k]._color + '"></i>' +
                            escapeHtml(u.series[r.k].label) + ' <b>' + value(state.data.unit, r.v) + '</b></span>';
                    }
                    tip.innerHTML = html;
                    tip.hidden = false;
                    const left = u.cursor.left;
                    const w = u.over.clientWidth;
                    tip.style.left = left > w * 0.6 ? '' : left + 14 + 'px';
                    tip.style.right = left > w * 0.6 ? w - left + 14 + 'px' : '';
                },
            },
        };
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // ---- zoom: a dragged selection or a double-click opens another period ----
    function go(from, to) {
        const now = Date.now() / 1000;
        to = Math.min(to, now);
        const q = new URLSearchParams(location.search);
        q.set('range', 'custom');
        q.set('from', new Date(from * 1000).toISOString());
        q.set('to', new Date(to * 1000).toISOString());
        location.search = q.toString();
    }

    function zoomOut(state) {
        const span = state.data.to - state.data.from;
        const mid = state.data.from + span / 2;
        go(mid - span * 1.5, mid + span * 1.5);
    }

    // ---- one graph ---------------------------------------------------------
    function build(state) {
        const el = state.plot;
        const colors = palette();
        const data = state.data;
        el.innerHTML = '';
        if (state.u) { state.u.destroy(); state.u = null; }

        const hasData = data.series.some((s) => s.values.some((v) => v != null));
        if (!data.x.length || !hasData) {
            el.innerHTML = '<p class="muted small chart-empty">' + escapeHtml(t.empty) + '</p>';
            renderLegend(state, []);
            return;
        }

        const series = [{}];
        data.series.forEach((s, i) => {
            const color = seriesColor(colors, s, i, state.breakdown);
            const single = !state.breakdown && data.series.length === 1;
            series.push({
                label: s.label,
                show: state.hidden ? !state.hidden.has(s.label) : true,
                stroke: color,
                width: 2,
                fill: single ? color + '1F' : undefined,
                points: { show: false },
                spanGaps: false,
                _color: color,
            });
        });

        const opts = {
            width: Math.max(200, el.clientWidth),
            height: el.clientHeight || 200,
            series,
            legend: { show: false },
            padding: [8, 24, 0, 0],
            cursor: { drag: { x: true, y: false, setScale: false }, points: { size: 7 } },
            select: { show: true },
            scales: {
                x: { time: true, range: () => [data.from, data.to] },
                y: { range: (u, min, max) => [0, data.max ? Math.max(data.max, max || 0) : (max > 0 ? max * 1.1 : 1)] },
            },
            axes: [
                { stroke: colors.ink, grid: { show: false }, ticks: { stroke: colors.grid }, values: xValues, font: '11px system-ui, sans-serif' },
                { stroke: colors.ink, grid: { stroke: colors.grid, width: 1 }, ticks: { show: false }, size: 70,
                  values: (u, splits) => splits.map((v) => value(data.unit, v)), font: '11px system-ui, sans-serif' },
            ],
            hooks: {
                setSelect: [(u) => {
                    if (u.select.width < 8) return;
                    const from = u.posToVal(u.select.left, 'x');
                    const to = u.posToVal(u.select.left + u.select.width, 'x');
                    u.setSelect({ left: 0, width: 0 }, false);
                    go(from, to);
                }],
            },
            plugins: [tooltipPlugin(state)],
        };

        state.u = new uPlot(opts, [data.x, ...data.series.map((s) => s.values)], el);
        state.u.over.addEventListener('dblclick', () => zoomOut(state));
        renderLegend(state, series.slice(1));
    }

    function renderLegend(state, series) {
        const box = state.legend;
        if (!box) return;
        box.innerHTML = '';
        if (!state.breakdown && series.length < 2) return;
        series.forEach((s, k) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'stats-key' + (s.show === false ? ' is-off' : '');
            b.setAttribute('aria-pressed', s.show === false ? 'false' : 'true');
            b.innerHTML = '<i class="swatch" style="background:' + s._color + '"></i><span>' + escapeHtml(s.label) + '</span>';
            if (state.breakdown) {
                b.addEventListener('click', () => toggle(state, k + 1, b));
            } else {
                b.disabled = true;
            }
            box.appendChild(b);
        });
        if (state.breakdown && series.length > 1) {
            for (const [label, show] of [[t.all, true], [t.none, false]]) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'stats-key stats-key-all';
                b.textContent = label;
                b.addEventListener('click', () => {
                    state.u && state.u.series.forEach((s, i) => { if (i) state.u.setSeries(i, { show }); });
                    state.hidden = new Set(show ? [] : series.map((s) => s.label));
                    box.querySelectorAll('.stats-key:not(.stats-key-all)').forEach((el) => {
                        el.classList.toggle('is-off', !show);
                        el.setAttribute('aria-pressed', String(show));
                    });
                });
                box.appendChild(b);
            }
        }
    }

    function toggle(state, i, button) {
        if (!state.u) return;
        const show = !state.u.series[i].show;
        state.u.setSeries(i, { show });
        const label = state.u.series[i].label;
        show ? state.hidden.delete(label) : state.hidden.add(label);
        button.classList.toggle('is-off', !show);
        button.setAttribute('aria-pressed', String(show));
    }

    function load(state, params) {
        state.plot.innerHTML = '<p class="muted small chart-empty">' + escapeHtml(t.loading) + '</p>';
        const q = new URLSearchParams(Object.assign({}, range, params));
        return fetch(url + '?' + q.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then((data) => { state.data = data; build(state); })
            .catch(() => { state.plot.innerHTML = '<p class="muted small chart-empty">' + escapeHtml(t.failed) + '</p>'; });
    }

    // ---- wire up -----------------------------------------------------------
    page.querySelectorAll('[data-chart]').forEach((fig) => {
        const state = { plot: fig.querySelector('[data-plot]'), legend: fig.querySelector('[data-legend]'), breakdown: false };
        charts.push(state);
        load(state, { chart: fig.dataset.chart });
    });

    const bd = page.querySelector('[data-breakdown]');
    if (bd) {
        const state = { plot: bd.querySelector('[data-plot]'), legend: bd.querySelector('[data-legend]'), breakdown: true, hidden: new Set() };
        charts.push(state);
        const metric = bd.querySelector('[name="bd-metric"]');
        const customer = bd.querySelector('[name="bd-customer"]');
        const by = () => bd.querySelector('[name="bd-by"]:checked').value;
        const remember = () => { try { localStorage.setItem('whp-stats-breakdown', JSON.stringify({ by: by(), metric: metric.value, customer: customer.value })); } catch (e) { /* private mode */ } };
        try {
            const saved = JSON.parse(localStorage.getItem('whp-stats-breakdown') || 'null');
            if (saved) {
                const radio = bd.querySelector('[name="bd-by"][value="' + saved.by + '"]');
                if (radio) radio.checked = true;
                if ([...metric.options].some((o) => o.value === saved.metric)) metric.value = saved.metric;
                if ([...customer.options].some((o) => o.value === saved.customer)) customer.value = saved.customer;
            }
        } catch (e) { /* ignore */ }
        const reload = () => {
            customer.hidden = by() !== 'website';
            state.hidden = new Set();
            remember();
            const params = { chart: 'breakdown', metric: metric.value, by: by() };
            if (by() === 'website' && customer.value) params.customer = customer.value;
            load(state, params);
        };
        bd.addEventListener('change', reload);
        reload();
    }

    // Keep graphs the width of their card, and redraw in the other theme.
    const ro = new ResizeObserver(() => charts.forEach((s) => s.u && s.u.setSize({ width: Math.max(200, s.plot.clientWidth), height: s.plot.clientHeight || 200 })));
    charts.forEach((s) => ro.observe(s.plot));
    const redraw = () => charts.forEach((s) => s.data && build(s));
    new MutationObserver(redraw).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', redraw);
})();
