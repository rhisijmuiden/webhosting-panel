/* File manager and code editor (packages/files). Loaded before Alpine starts. */
(function () {
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const ARCHIVE = /\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|tar\.xz|txz)$/i;
  const TEXT = /\.(php|phtml|inc|html?|css|scss|less|js|mjs|cjs|jsx|ts|tsx|vue|json|map|md|markdown|txt|log|sql|xml|svg|ya?ml|py|sh|bash|ini|conf|cnf|twig|env|htaccess|htpasswd|csv|tsv|lock|gitignore|editorconfig|po|pot|rb|pl|go|rs|java|c|h|cpp|webmanifest|user\.ini)$/i;
  const IMAGE = /\.(png|jpe?g|gif|webp|avif|ico|bmp)$/i;

  async function api(url, method = 'GET', body = null) {
    const opts = {
      method,
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    };
    if (body instanceof FormData) {
      opts.body = body;
    } else if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    let data = {};
    try { data = await res.json(); } catch (e) { data = {}; }
    if (!res.ok) {
      const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
      const err = new Error(data?.error?.message || firstError || data?.message || ('HTTP ' + res.status));
      err.status = res.status;
      err.code = data?.error?.code;
      throw err;
    }
    return data;
  }

  const q = (url, params) => url + (url.includes('?') ? '&' : '?') + new URLSearchParams(params).toString();
  const join = (dir, name) => (dir ? dir + '/' : '') + name;
  const parent = (p) => p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '';
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  function size(bytes) {
    if (bytes === null || bytes === undefined) return '';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0; let n = bytes;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(n < 10 ? 1 : 0)) + ' ' + u[i];
  }

  function rwx(mode) {
    const m = parseInt(mode, 8) || 0;
    return [6, 3, 0].map((s) => ((m >> s) & 4 ? 'r' : '-') + ((m >> s) & 2 ? 'w' : '-') + ((m >> s) & 1 ? 'x' : '-')).join('');
  }

  document.addEventListener('alpine:init', () => {
    window.Alpine.data('fileManager', (cfg) => ({
      cfg,
      t: cfg.i18n,
      path: cfg.path || '',
      entries: [],
      protectedNames: [],
      docroot: 'public',
      freeBytes: null,
      truncated: false,
      loading: false,
      error: null,
      notice: null,
      selected: [],
      sort: { key: 'name', dir: 1 },
      showHidden: true,
      search: '',
      searchResults: null,
      dragging: false,
      uploads: [],
      overwrite: false,
      dialog: { kind: null, title: '', value: '', target: null, mode: '644', to: '', format: 'zip', overwrite: false, busy: false, error: null },
      job: null,
      menu: null,
      clipboard: null,

      init() {
        window.addEventListener('popstate', (e) => { this.path = e.state?.path ?? this.urlPath(); this.load(false); });
        window.addEventListener('beforeunload', (e) => { if (this.uploads.some((u) => u.status === 'uploading')) { e.preventDefault(); e.returnValue = ''; } });
        document.addEventListener('keydown', (e) => this.key(e));
        this.load(false);
      },

      urlPath() { return new URL(location.href).searchParams.get('path') || ''; },

      get crumbs() {
        const parts = this.path ? this.path.split('/') : [];
        return parts.map((name, i) => ({ name, path: parts.slice(0, i + 1).join('/') }));
      },

      get rows() {
        const list = this.searchResults ?? this.entries;
        const rows = list.filter((e) => this.showHidden || !e.name.startsWith('.'));
        const k = this.sort.key; const d = this.sort.dir;
        return rows.slice().sort((a, b) => {
          const ad = a.type === 'dir' || a.target_type === 'dir'; const bd = b.type === 'dir' || b.target_type === 'dir';
          if (ad !== bd) return ad ? -1 : 1;
          let x = a[k]; let y = b[k];
          if (k === 'name') { x = x.toLowerCase(); y = y.toLowerCase(); }
          return (x > y ? 1 : x < y ? -1 : 0) * d;
        });
      },

      get allSelected() { return this.rows.length > 0 && this.selected.length === this.rows.length; },
      get single() { return this.selected.length === 1 ? this.find(this.selected[0]) : null; },

      find(p) { return (this.searchResults ?? this.entries).find((e) => this.full(e) === p); },
      full(e) { return e.path ?? join(this.path, e.name); },
      isDir(e) { return e.type === 'dir' || e.target_type === 'dir'; },
      isProtected(e) { return !e.path && this.path === '' && this.protectedNames.includes(e.name); },
      isArchive(e) { return e && e.type === 'file' && ARCHIVE.test(e.name); },
      isText(e) { return e && e.type === 'file' && (TEXT.test(e.name) || !e.name.includes('.') || e.name.startsWith('.')) && e.size <= cfg.editorMax; },
      isImage(e) { return e && e.type === 'file' && IMAGE.test(e.name); },
      size, rwx,
      date(ts) { return ts ? new Date(ts * 1000).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''; },
      icon(e) {
        if (this.isDir(e)) return e.name === this.docroot && this.path === '' ? 'web' : 'folder';
        if (e.type === 'link') return 'link';
        if (this.isArchive(e)) return 'archive';
        if (this.isImage(e)) return 'image';
        if (/\.(php|html?|css|js|ts|json|vue|py|sh|sql|xml|ya?ml|twig)$/i.test(e.name)) return 'code';
        return 'file';
      },

      async load(push = true, path = null) {
        if (path !== null) this.path = path;
        this.loading = true; this.error = null; this.selected = []; this.searchResults = null; this.menu = null;
        try {
          const data = await api(q(cfg.urls.list, { path: this.path }));
          this.entries = data.entries; this.path = data.path;
          this.protectedNames = data.protected || []; this.docroot = data.docroot || 'public';
          this.freeBytes = data.free_bytes; this.truncated = data.truncated;
          if (push) history.pushState({ path: this.path }, '', q(cfg.base, this.path ? { path: this.path } : {}));
        } catch (e) {
          this.error = e.message;
          if (e.status === 404 && this.path) { this.path = parent(this.path); return this.load(push); }
        } finally {
          this.loading = false;
        }
      },

      open(e) {
        const p = this.full(e);
        if (this.isDir(e)) return this.load(true, p);
        if (this.isText(e)) { location.href = q(cfg.urls.edit, { path: p }); return; }
        if (this.isArchive(e)) return this.ask('extract', e);
        this.downloadPaths([p]);
      },

      toggle(e) {
        const p = this.full(e);
        this.selected = this.selected.includes(p) ? this.selected.filter((x) => x !== p) : [...this.selected, p];
      },
      toggleAll() { this.selected = this.allSelected ? [] : this.rows.map((e) => this.full(e)); },
      sortBy(key) { this.sort = { key, dir: this.sort.key === key ? -this.sort.dir : 1 }; },

      key(e) {
        if (this.dialog.kind || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
        if (e.key === 'Delete' && this.selected.length) { e.preventDefault(); this.ask('delete'); }
        if (e.key === 'F2' && this.single) { e.preventDefault(); this.ask('rename', this.single); }
        if ((e.ctrlKey || e.metaKey) && e.key === 'a') { e.preventDefault(); this.toggleAll(); }
        if (e.key === 'Backspace' && this.path) { e.preventDefault(); this.load(true, parent(this.path)); }
      },

      flash(msg) { this.notice = msg; setTimeout(() => { if (this.notice === msg) this.notice = null; }, 4000); },

      async runSearch() {
        if (this.search.trim().length < 2) { this.searchResults = null; return; }
        this.loading = true; this.error = null;
        try {
          const data = await api(q(cfg.urls.search, { q: this.search.trim(), path: this.path }));
          this.searchResults = data.results; this.selected = [];
        } catch (e) { this.error = e.message; } finally { this.loading = false; }
      },
      clearSearch() { this.search = ''; this.searchResults = null; },

      // ------------------------------------------------------------- dialogs
      ask(kind, e = null) {
        this.menu = null;
        const target = e ? this.full(e) : null;
        const sel = target ? [target] : this.selected.slice();
        const d = { kind, target, paths: sel, value: '', mode: '644', to: this.path, format: 'zip', overwrite: false, busy: false, error: null };
        if (kind === 'rename') d.value = e.name;
        if (kind === 'chmod') d.mode = (e || this.single)?.mode?.slice(-3) || '644';
        if (kind === 'compress') d.value = sel.length === 1 ? sel[0].split('/').pop() : 'archive';
        if (kind === 'extract') d.to = parent(target);
        this.dialog = d;
        this.$nextTick(() => { this.$refs.dialog.showModal(); this.$refs.dialog.querySelector('input:not([type=checkbox]):not([type=radio])')?.select(); });
      },
      closeDialog() { this.$refs.dialog.close(); this.dialog.kind = null; },
      modeBit(who, bit) { return (parseInt(this.dialog.mode, 8) >> (who * 3)) & bit; },
      setBit(who, bit, on) {
        let m = parseInt(this.dialog.mode, 8) || 0;
        m = on ? m | (bit << (who * 3)) : m & ~(bit << (who * 3));
        this.dialog.mode = m.toString(8).padStart(3, '0');
      },

      async submitDialog() {
        const d = this.dialog;
        d.busy = true; d.error = null;
        try {
          if (d.kind === 'mkdir' || d.kind === 'mkfile') {
            const r = await api(cfg.urls.mkdir, 'POST', { dir: this.path, name: d.value.trim(), type: d.kind === 'mkfile' ? 'file' : 'dir' });
            this.closeDialog();
            if (d.kind === 'mkfile') { location.href = q(cfg.urls.edit, { path: r.path }); return; }
          } else if (d.kind === 'rename') {
            await api(cfg.urls.rename, 'POST', { path: d.target, name: d.value.trim() });
            this.closeDialog();
          } else if (d.kind === 'delete') {
            const r = await api(cfg.urls.delete, 'POST', { paths: d.paths });
            this.closeDialog(); this.flash(this.t.deleted.replace(':count', r.deleted));
          } else if (d.kind === 'chmod') {
            await api(cfg.urls.chmod, 'POST', { paths: d.paths, mode: d.mode });
            this.closeDialog();
          } else if (d.kind === 'move' || d.kind === 'copy') {
            await api(cfg.urls[d.kind], 'POST', { paths: d.paths, to: d.to.replace(/^\/+|\/+$/g, '') });
            this.closeDialog();
          } else if (d.kind === 'compress') {
            const r = await api(cfg.urls.compress, 'POST', { paths: d.paths, dir: this.path, name: d.value.trim(), format: d.format });
            this.closeDialog(); await this.follow(r.job, this.t.compressing);
          } else if (d.kind === 'extract') {
            const r = await api(cfg.urls.extract, 'POST', { path: d.target, to: d.to.replace(/^\/+|\/+$/g, ''), overwrite: d.overwrite });
            this.closeDialog(); await this.follow(r.job, this.t.extracting);
          }
          await this.load(false);
        } catch (e) {
          d.error = e.message;
        } finally {
          d.busy = false;
        }
      },

      async follow(jobId, label) {
        this.job = { label, status: 'running', log: [], error: null };
        for (let i = 0; i < 3600; i++) {
          await sleep(i < 5 ? 400 : 1000);
          try {
            const s = await api(cfg.job.replace('__JOB__', jobId));
            this.job = { label, ...s };
            if (s.status !== 'running') break;
          } catch (e) { this.job.error = e.message; break; }
        }
        if (this.job.status === 'succeeded') { this.flash(this.t.done); this.job = null; }
      },

      // ----------------------------------------------------- copy and paste
      cut() { this.clipboard = { op: 'move', paths: this.selected.slice() }; this.flash(this.t.cut); },
      copy() { this.clipboard = { op: 'copy', paths: this.selected.slice() }; this.flash(this.t.copied); },
      async paste() {
        if (!this.clipboard) return;
        try {
          await api(cfg.urls[this.clipboard.op], 'POST', { paths: this.clipboard.paths, to: this.path });
          if (this.clipboard.op === 'move') this.clipboard = null;
          await this.load(false);
        } catch (e) { this.error = e.message; }
      },

      // ------------------------------------------------- download and upload
      downloadPaths(paths) {
        for (const p of paths) {
          const a = document.createElement('a');
          a.href = q(cfg.urls.download, { path: p }); a.download = '';
          document.body.appendChild(a); a.click(); a.remove();
        }
      },

      pick(folder) { (folder ? this.$refs.folderInput : this.$refs.fileInput).click(); },
      picked(ev) { this.queue([...ev.target.files].map((f) => ({ file: f, name: f.webkitRelativePath || f.name }))); ev.target.value = ''; },

      async dropped(ev) {
        this.dragging = false;
        const items = [...(ev.dataTransfer.items || [])].map((i) => i.webkitGetAsEntry?.()).filter(Boolean);
        if (!items.length) { this.queue([...ev.dataTransfer.files].map((f) => ({ file: f, name: f.name }))); return; }
        const out = [];
        const walk = async (entry, prefix) => {
          if (entry.isFile) {
            const file = await new Promise((res, rej) => entry.file(res, rej));
            out.push({ file, name: prefix + file.name });
          } else if (entry.isDirectory) {
            const reader = entry.createReader();
            let batch;
            do {
              batch = await new Promise((res, rej) => reader.readEntries(res, rej));
              for (const child of batch) await walk(child, prefix + entry.name + '/');
            } while (batch.length);
          }
        };
        for (const entry of items) await walk(entry, '');
        this.queue(out);
      },

      queue(files) {
        const dir = this.path;
        for (const f of files) {
          if (f.file.size > cfg.maxUpload) {
            this.uploads.push({ name: f.name, status: 'failed', error: this.t.too_large, progress: 0 });
            continue;
          }
          this.uploads.push({ id: crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2), file: f.file, name: f.name, dir, status: 'waiting', progress: 0, error: null });
        }
        this.pump();
      },

      async pump() {
        if (this.uploads.some((u) => u.status === 'uploading')) return;
        const next = this.uploads.find((u) => u.status === 'waiting');
        if (!next) { await this.load(false); return; }
        next.status = 'uploading';
        try {
          const total = Math.max(1, Math.ceil(next.file.size / cfg.chunk));
          for (let i = 0; i < total; i++) {
            const form = new FormData();
            form.append('upload_id', next.id); form.append('index', i); form.append('total', total);
            form.append('dir', next.dir); form.append('name', next.name); form.append('overwrite', this.overwrite ? '1' : '0');
            form.append('chunk', next.file.slice(i * cfg.chunk, (i + 1) * cfg.chunk), 'chunk');
            await api(cfg.urls.upload, 'POST', form);
            next.progress = Math.round(((i + 1) / total) * 100);
          }
          next.status = 'done';
        } catch (e) {
          next.status = 'failed'; next.error = e.message;
        }
        this.pump();
      },
      get uploadsActive() { return this.uploads.filter((u) => u.status === 'waiting' || u.status === 'uploading').length; },
      clearUploads() { this.uploads = this.uploads.filter((u) => u.status === 'waiting' || u.status === 'uploading'); },
    }));

    // ----------------------------------------------------------------- editor
    window.Alpine.data('codeEditor', (cfg) => ({
      cfg,
      t: cfg.i18n,
      editor: null,
      mtime: null,
      mode: null,
      dirty: false,
      saving: false,
      loading: true,
      error: null,
      conflict: false,
      savedAt: null,
      wrap: false,
      line: 1,
      col: 1,

      async init() {
        try { this.wrap = localStorage.getItem('whp-editor-wrap') === '1'; } catch (e) { /* private mode */ }
        window.addEventListener('beforeunload', (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = ''; } });
        try {
          const data = await api(q(cfg.urls.read, { path: cfg.path }));
          this.mtime = data.mtime; this.mode = data.mode;
          this.editor = window.WhpEditor.create(this.$refs.editor, {
            content: data.content, filename: cfg.path.split('/').pop(), wrap: this.wrap,
            onChange: () => { this.dirty = true; },
            onSave: () => this.save(),
            onCursor: (l, c) => { this.line = l; this.col = c; },
          });
          this.editor.focus();
        } catch (e) {
          this.error = e.message;
        } finally {
          this.loading = false;
        }
      },

      async save(force = false) {
        if (!this.editor || this.saving) return;
        this.saving = true; this.error = null;
        try {
          const r = await api(cfg.urls.write, 'PUT', { path: cfg.path, content: this.editor.getValue(), expected_mtime: force ? null : this.mtime });
          this.mtime = r.mtime; this.dirty = false; this.conflict = false;
          this.savedAt = new Date().toLocaleTimeString(document.documentElement.lang || undefined, { timeStyle: 'short' });
        } catch (e) {
          if (e.status === 409) this.conflict = true; else this.error = e.message;
        } finally {
          this.saving = false;
        }
      },
      toggleWrap() {
        this.wrap = !this.wrap; this.editor?.setWrap(this.wrap);
        try { localStorage.setItem('whp-editor-wrap', this.wrap ? '1' : '0'); } catch (e) { /* private mode */ }
      },
      reload() { this.dirty = false; location.reload(); },
    }));
  });
})();
