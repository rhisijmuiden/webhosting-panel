/*
 * WordPress bulk actions (packages/wordpress, Bulk): the site picker with filters
 * and the live progress of a run. Loaded without defer, before Alpine starts.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('wpBulk', (init) => ({
        sites: init.sites, actions: init.actions, action: init.action, filter: init.filter,
        q: '', customer: '', version: '', cron: '', component: init.component || '', componentMode: 'has',
        selected: init.old || [], limit: 200, concurrency: 3, stopAfter: 0, confirmWord: '', ready: false,
        init() {
            if (this.action) this.concurrency = this.actions[this.action].concurrency;
            this.$watch('action', (a) => { if (a) { this.concurrency = this.actions[a].concurrency; this.stopAfter = a === 'update' ? 3 : 0; } });
            if (this.action === 'update') this.stopAfter = 3;
            this.$watch('shown', () => { this.limit = 200; });
        },
        matchQuick(s, f) {
            return f === 'outdated' ? s.outdated > 0 : f === 'core' ? !! s.core_update
                : f === 'failed' ? (s.last_update && s.last_update !== 'succeeded') : f === 'unchecked' ? ! s.checked : true;
        },
        matchComponent(s) {
            if (! this.component) return true;
            const [type, name] = this.component.split(':');
            const list = type === 'theme' ? s.themes : s.plugins;
            const has = list.includes(name) || list.includes(name + '*');
            return this.componentMode === 'missing' ? ! has : this.componentMode === 'update' ? list.includes(name + '*') : has;
        },
        matches(s) {
            const q = this.q.trim().toLowerCase();
            return (! q || s.label.toLowerCase().includes(q) || (s.customer || '').toLowerCase().includes(q))
                && (! this.customer || String(s.customer_id) === this.customer)
                && (! this.version || s.version === this.version)
                && (! this.cron || s.cron === this.cron)
                && this.matchComponent(s);
        },
        get shown() { return this.sites.filter((s) => this.matchQuick(s, this.filter) && this.matches(s)); },
        countFor(f) { return this.sites.filter((s) => this.matchQuick(s, f) && this.matches(s)).length; },
        get filtered() { return this.q || this.customer || this.version || this.cron || this.component || this.filter !== 'all'; },
        get hiddenSelected() { const ids = new Set(this.shown.map((s) => s.id)); return this.selected.filter((id) => ! ids.has(id)).length; },
        get allShownSelected() { const sel = new Set(this.selected); return this.shown.length > 0 && this.shown.every((s) => sel.has(s.id)); },
        isSelected(id) { return this.selected.includes(id); },
        toggle(id) { this.selected = this.isSelected(id) ? this.selected.filter((x) => x !== id) : [...this.selected, id]; },
        selectShown() { this.selected = [...new Set([...this.selected, ...this.shown.map((s) => s.id)])]; },
        unselectShown() { const ids = new Set(this.shown.map((s) => s.id)); this.selected = this.selected.filter((id) => ! ids.has(id)); },
        resetFilters() { this.q = ''; this.customer = ''; this.version = ''; this.cron = ''; this.component = ''; this.componentMode = 'has'; this.filter = 'all'; },
        componentTags(s) {
            if (! this.component) return [];
            const [type, name] = this.component.split(':');
            const list = type === 'theme' ? s.themes : s.plugins;
            return list.filter((p) => p.replace(/\*$/, '') === name).map((p) => ({ name: p.replace(/\*$/, ''), up: p.endsWith('*') }));
        },
    }));

    Alpine.data('wpBulkRun', (init) => ({
        run: init.payload.run, items: init.payload.items, url: init.url, show: 'all', open: {},
        startStatus: init.payload.run.status,
        init() { this.schedule(); },
        schedule() {
            if (this.run.finished) return;
            setTimeout(() => this.refresh(), this.run.status === 'running' ? 2000 : 6000);
        },
        async refresh() {
            try {
                const res = await fetch(this.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (res.ok) {
                    const data = await res.json();
                    this.run = data.run;
                    this.items = data.items;
                    if (data.run.status !== this.startStatus || data.run.finished) {
                        window.location.reload();
                        return;
                    }
                }
            } catch (e) { /* try again next round */ }
            this.schedule();
        },
        pct(...keys) {
            const t = this.run.counts.total || 1;
            return keys.reduce((n, k) => n + (this.run.counts[k] || 0), 0) * 100 / t;
        },
        get shownItems() {
            if (this.show === 'all') return this.items;
            if (this.show === 'done') return this.items.filter((i) => i.status === 'succeeded' || i.status === 'attention');
            if (this.show === 'waiting') return this.items.filter((i) => i.status === 'pending' || i.status === 'running');
            return this.items.filter((i) => i.status === this.show);
        },
        count(show) {
            const c = this.run.counts;
            return show === 'all' ? c.total : show === 'done' ? c.succeeded + c.attention : show === 'waiting' ? c.pending + c.running : (c[show] || 0);
        },
        pill(status) {
            return { succeeded: 'pill-ok', attention: 'pill-warn', failed: 'pill-bad', running: 'pill-info', pending: 'pill-neutral', skipped: 'pill-neutral', cancelled: 'pill-neutral' }[status] || 'pill-neutral';
        },
        duration(s) {
            if (s === null || s === undefined) return '';
            return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + String(s % 60).padStart(2, '0') + 's';
        },
        lines(details) {
            if (Array.isArray(details)) return details;
            return Object.entries(details || {}).map(([k, v]) => k + ': ' + v);
        },
    }));
});
