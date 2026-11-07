/* WordPress import wizard: upload the website zip and the database export in chunks (resumable). */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('wpImportUpload', (cfg) => ({
        cfg,
        f: Object.fromEntries(Object.entries(cfg.files).map(([k, v]) => [k, {
            name: v.name, size: v.size, sent: v.complete ? v.size : 0, complete: v.complete, busy: false, over: false, error: null,
        }])),
        get ready() { return Object.values(this.f).every((x) => x.complete) && !Object.values(this.f).some((x) => x.busy); },
        percent(kind) { const x = this.f[kind]; return x.size ? Math.floor(x.sent / x.size * 100) : 0; },
        async api(url, body, raw = false) {
            const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' };
            if (!raw) headers['Content-Type'] = 'application/json';
            const res = await fetch(url, { method: 'POST', headers, credentials: 'same-origin', body: raw ? body : JSON.stringify(body) });
            let data = {}; try { data = await res.json(); } catch (e) { data = {}; }
            if (!res.ok) { const err = new Error(data.message || ('HTTP ' + res.status)); err.data = data; err.status = res.status; throw err; }
            return data;
        },
        choose(kind, file) {
            const x = this.f[kind];
            x.error = null;
            if (!file) return;
            if (!new RegExp(this.cfg.files[kind].accept, 'i').test(file.name)) { x.error = this.cfg.text[kind + 'Type']; return; }
            if (file.size > this.cfg.max) { x.error = this.cfg.text.tooBig; return; }
            this.upload(kind, file);
        },
        async upload(kind, file) {
            const x = this.f[kind];
            Object.assign(x, { name: file.name, size: file.size, sent: 0, complete: false, busy: true });
            try {
                const start = await this.api(this.cfg.files[kind].start, { name: file.name, size: file.size });
                while (x.sent < file.size) {
                    const chunk = file.slice(x.sent, x.sent + start.chunk_size);
                    for (let tries = 0; ; tries++) {
                        try {
                            const r = await this.api(`${this.cfg.files[kind].chunk}?offset=${x.sent}`, chunk, true);
                            x.sent = r.received; break;
                        } catch (e) {
                            if (e.status === 409 && e.data && typeof e.data.received === 'number' && e.data.received >= 0) { x.sent = e.data.received; break; }
                            if (e.status === 422 || tries >= 4) throw e;
                            await new Promise((r) => setTimeout(r, 1000 * (tries + 1)));
                        }
                    }
                }
                x.complete = true;
            } catch (e) {
                x.error = e.message || this.cfg.text.failed; x.complete = false; x.sent = 0;
            } finally {
                x.busy = false;
            }
        },
        size(b) { const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return (i ? b.toFixed(1) : b) + ' ' + u[i]; },
    }));
    // Confirm step: the new address chosen at the top shows in every address row.
    window.Alpine.data('wpImportConfirm', (cfg) => ({ url: cfg.url }));
});
