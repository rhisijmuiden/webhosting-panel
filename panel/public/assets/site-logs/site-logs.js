/* Website log viewer (packages/site-logs). Alpine component "siteLogs". */
(() => {
  const MAX_KEEP = 3000;
  let seq = 0;

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const reEsc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  document.addEventListener('alpine:init', () => {
    window.Alpine.data('siteLogs', (cfg) => ({
      i18n: cfg.i18n,
      log: cfg.log,
      file: '',
      files: {},
      q: cfg.q || '',
      lines: 300,
      status: [],
      levels: [],
      ip: '',
      method: '',
      newestFirst: true,
      entries: [],
      meta: null,
      cursor: null,
      loading: false,
      error: '',
      notice: '',
      live: false,
      paused: false,
      pending: 0,
      buffer: [],
      open: null,
      copied: null,
      timer: null,
      request: 0,

      init() {
        this.$watch('q', () => this.reload());
        this.$watch('newestFirst', () => this.$nextTick(() => this.stickToEdge()));
        this.loadFiles();
        this.reload();
        document.addEventListener('visibilitychange', () => {
          if (document.hidden) this.stopTimer();
          else if (this.live) this.schedule(200);
        });
      },

      // ---------------------------------------------------------------- data

      async getJson(url, params) {
        const u = new URL(url, window.location.href);
        Object.entries(params).forEach(([k, v]) => {
          if (Array.isArray(v)) v.forEach((x) => u.searchParams.append(k + '[]', x));
          else if (v !== null && v !== undefined && v !== '') u.searchParams.set(k, v);
        });
        const res = await fetch(u, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        let body = null;
        try { body = await res.json(); } catch (e) { body = null; }
        if (!res.ok) throw new Error((body && body.message) || this.i18n.failed);
        return body;
      },

      async loadFiles() {
        try {
          const data = await this.getJson(cfg.filesUrl, {});
          this.files = data.logs || {};
          if (!this.file) this.file = this.currentName();
        } catch (e) { /* the reader shows its own error */ }
      },

      params(extra = {}) {
        return {
          log: this.log, file: this.isCurrent() ? '' : this.file, lines: this.lines, q: this.q.trim(),
          status: this.log === 'access' ? this.status : [], levels: this.log !== 'access' ? this.levels : [],
          ip: this.ip, method: this.log === 'access' ? this.method : '', ...extra,
        };
      },

      async reload() {
        const id = ++this.request;
        this.loading = true;
        this.error = '';
        this.notice = '';
        this.open = null;
        this.pending = 0;
        this.buffer = [];
        try {
          const data = await this.getJson(cfg.readUrl, this.params());
          if (id !== this.request) return;
          this.meta = data;
          this.cursor = data.cursor;
          this.entries = this.tag(data.entries || [], false);
          if (data.truncated) this.notice = this.i18n.truncated;
          this.$nextTick(() => this.stickToEdge(true));
        } catch (e) {
          if (id !== this.request) return;
          this.entries = [];
          this.meta = null;
          this.error = e.message;
        } finally {
          if (id === this.request) this.loading = false;
        }
        if (this.live) this.schedule();
      },

      async tail() {
        if (!this.live || !this.cursor || document.hidden) return;
        const id = this.request;
        try {
          const data = await this.getJson(cfg.readUrl, this.params({ inode: this.cursor.inode, offset: this.cursor.offset, lines: 1000 }));
          if (id !== this.request || !this.live) return;
          this.cursor = data.cursor;
          this.meta = { ...this.meta, size: data.size, modified: data.modified, exists: data.exists };
          if (data.rotated) this.notice = this.i18n.rotated;
          const fresh = this.tag(data.entries || [], true);
          if (fresh.length) {
            if (this.paused) {
              this.buffer.push(...fresh);
              this.pending = this.buffer.length;
            } else {
              this.append(fresh);
            }
          }
          this.schedule();
        } catch (e) {
          this.live = false;
          this.error = this.i18n.tail_failed + ' ' + e.message;
        }
      },

      tag(list, isNew) {
        return list.map((e) => ({ ...e, _id: ++seq, _new: isNew }));
      },

      append(fresh) {
        this.entries.push(...fresh);
        if (this.entries.length > MAX_KEEP) this.entries.splice(0, this.entries.length - MAX_KEEP);
        this.$nextTick(() => this.stickToEdge(true));
        setTimeout(() => fresh.forEach((e) => { e._new = false; }), 2500);
      },

      flush() {
        const fresh = this.buffer;
        this.buffer = [];
        this.pending = 0;
        this.paused = false;
        this.append(fresh);
      },

      schedule(ms) {
        this.stopTimer();
        this.timer = setTimeout(() => this.tail(), ms ?? cfg.interval * 1000);
      },

      stopTimer() {
        if (this.timer) clearTimeout(this.timer);
        this.timer = null;
      },

      toggleLive() {
        this.live = !this.live;
        this.paused = false;
        if (this.live) {
          this.error = '';
          this.schedule(300);
        } else {
          this.stopTimer();
          if (this.buffer.length) this.flush();
        }
      },

      // ---------------------------------------------------------------- scrolling

      edgeDistance() {
        const el = this.$refs.rows;
        if (!el) return 0;
        return this.newestFirst ? el.scrollTop : el.scrollHeight - el.clientHeight - el.scrollTop;
      },

      onScroll() {
        if (!this.live) return;
        const away = this.edgeDistance() > 40;
        if (away !== this.paused) {
          this.paused = away;
          if (!away && this.buffer.length) this.flush();
        }
      },

      stickToEdge(force) {
        const el = this.$refs.rows;
        if (!el || (this.paused && !force)) return;
        el.scrollTop = this.newestFirst ? 0 : el.scrollHeight;
      },

      // ---------------------------------------------------------------- filters

      switchLog(log) {
        if (log === this.log) return;
        this.log = log;
        this.file = this.currentName();
        this.status = [];
        this.levels = [];
        this.method = '';
        this.reload();
      },

      toggle(list, value) {
        const i = list.indexOf(value);
        if (i >= 0) list.splice(i, 1); else list.push(value);
        this.reload();
      },

      hasFilters() {
        return !!(this.q.trim() || this.status.length || this.levels.length || this.ip || this.method);
      },

      resetFilters() {
        this.status = [];
        this.levels = [];
        this.ip = '';
        this.method = '';
        if (this.q) this.q = ''; else this.reload();
      },

      filesFor(log) {
        const list = this.files[log] || [];
        return list.length ? list : [{ name: this.currentName(log), n: 0, size: 0 }];
      },

      currentName(log) {
        const list = this.files[log || this.log] || [];
        const cur = list.find((f) => f.n === 0);
        if (cur) return cur.name;
        return { access: 'access.log', error: 'error.log', php: 'php-error.log' }[log || this.log] || '';
      },

      isCurrent() {
        return !this.file || this.file === this.currentName();
      },

      // ---------------------------------------------------------------- display

      shown() {
        return this.newestFirst ? [...this.entries].reverse() : this.entries;
      },

      summary() {
        if (!this.meta) return '';
        const parts = [this.i18n.entries.replace(':count', this.entries.length.toLocaleString(this.i18n.locale))];
        if (this.meta.exists) parts.push(this.fmtBytes(this.meta.size));
        if (this.meta.modified) parts.push(this.i18n.updated.replace(':time', this.fmtTime(new Date(this.meta.modified * 1000).toISOString())));
        return parts.join(' · ');
      },

      emptyText() {
        if (this.meta && !this.meta.exists) return this.i18n.missing;
        return this.hasFilters() ? this.i18n.no_match : this.i18n.empty;
      },

      sizeOf(log) {
        const cur = (this.files[log] || []).find((f) => f.n === 0);
        return cur ? this.fmtBytes(cur.size) : '';
      },

      fileLabel(f) {
        if (f.n === 0) return this.i18n.current + ' · ' + f.name;
        const day = f.modified ? new Date(f.modified * 1000).toLocaleDateString(this.i18n.locale, { day: 'numeric', month: 'short' }) : '';
        return `${day} · ${f.name} (${this.fmtBytes(f.size)})`;
      },

      statusClass(s) {
        return s >= 200 && s < 600 ? Math.floor(s / 100) + 'xx' : 'other';
      },

      rowTone(e) {
        if (this.log === 'access') {
          if (e.status >= 500) return 'tone-bad';
          if (e.status >= 400) return 'tone-warn';
          return '';
        }
        return e.level === 'error' ? 'tone-bad' : e.level === 'warning' ? 'tone-warn' : '';
      },

      levelLabel(level) {
        return this.i18n.levels[level] || this.i18n.levels.info;
      },

      firstLine(s) {
        return String(s ?? '').split('\n')[0];
      },

      shortFile(file, line) {
        if (!file) return '';
        const parts = file.split('/');
        return parts.slice(-2).join('/') + (line ? ':' + line : '');
      },

      fmtTime(iso) {
        if (!iso) return '–';
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return iso;
        const sameDay = new Date().toDateString() === d.toDateString();
        const opts = sameDay ? { hour: '2-digit', minute: '2-digit', second: '2-digit' }
          : { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' };
        return d.toLocaleString(this.i18n.locale, opts);
      },

      fmtBytes(n) {
        n = Number(n || 0);
        if (n < 1024) return n + ' B';
        const u = ['KB', 'MB', 'GB', 'TB'];
        let i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return n.toLocaleString(this.i18n.locale, { maximumFractionDigits: n < 10 ? 1 : 0 }) + ' ' + u[i];
      },

      fmtMs(seconds) {
        const ms = seconds * 1000;
        return ms < 1000 ? Math.round(ms) + ' ms' : (ms / 1000).toLocaleString(this.i18n.locale, { maximumFractionDigits: 2 }) + ' s';
      },

      /** HTML-escaped text with the search words marked. */
      mark(text) {
        const safe = esc(text);
        const terms = this.q.split(/\s+/).filter((t) => t && !t.startsWith('-')).map((t) => esc(t)).filter((t) => t.length > 0);
        if (!terms.length) return safe;
        return safe.replace(new RegExp('(' + terms.map(reEsc).join('|') + ')', 'gi'), '<mark>$1</mark>');
      },

      // ---------------------------------------------------------------- insights

      kpis() {
        const n = this.entries.length;
        const fmt = (v) => v.toLocaleString(this.i18n.locale);
        if (this.log === 'access') {
          let c4 = 0; let c5 = 0; let c404 = 0; let rt = 0; let rtn = 0;
          this.entries.forEach((e) => {
            if (e.status >= 500) c5++; else if (e.status >= 400) c4++;
            if (e.status === 404) c404++;
            if (e.duration != null) { rt += e.duration; rtn++; }
          });
          const pct = (v) => (n ? Math.round((v / n) * 1000) / 10 : 0) + '%';
          return [
            { label: this.i18n.requests, value: fmt(n) },
            { label: '4xx', value: fmt(c4), hint: pct(c4) + (c404 ? ' · ' + this.i18n.not_found + ': ' + fmt(c404) : ''), tone: c4 ? 'warn' : '' },
            { label: '5xx', value: fmt(c5), hint: pct(c5), tone: c5 ? 'bad' : '' },
            { label: this.i18n.avg_time, value: rtn ? this.fmtMs(rt / rtn) : '–' },
          ];
        }
        const count = (l) => this.entries.filter((e) => e.level === l).length;
        const err = count('error'); const warn = count('warning');
        return [
          { label: this.i18n.entries_label, value: fmt(n) },
          { label: this.i18n.errors, value: fmt(err), tone: err ? 'bad' : '' },
          { label: this.i18n.warnings, value: fmt(warn), tone: warn ? 'warn' : '' },
        ];
      },

      top(field) {
        const counts = new Map();
        this.entries.forEach((e) => {
          let key = e[field];
          if (!key) return;
          if (field === 'path') key = key.split('?')[0];
          counts.set(key, (counts.get(key) || 0) + 1);
        });
        const rows = [...counts.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5);
        const max = rows.length ? rows[0][1] : 1;
        return rows.map(([key, count]) => ({ key, count, pct: Math.round((count / max) * 100) }));
      },

      // ---------------------------------------------------------------- actions

      async copy(e) {
        try {
          await navigator.clipboard.writeText(e.raw);
          this.copied = e._id;
          setTimeout(() => { if (this.copied === e._id) this.copied = null; }, 1500);
        } catch (err) { /* clipboard not allowed */ }
      },

      download() {
        const text = this.entries.map((e) => e.raw).join('\n') + '\n';
        const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `${cfg.domain}-${this.file || this.log}-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '')}.log`.replace(/\.log-/, '-');
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      },
    }));
  });
})();
