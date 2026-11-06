/* Restore from an uploaded backup zip: upload in chunks, check, choose, restore. Served by the backups package. */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('backupUpload', (cfg) => ({
        cfg, step: 'pick', file: null, id: null, sent: 0, error: null, info: null, busy: false, over: false,
        website: '', parts: ['files', 'databases', 'mail'], safety: '',
        get percent() { return this.file ? Math.floor(this.sent / this.file.size * 100) : 0; },
        async api(url, method = 'POST', body = null, raw = false) {
            const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' };
            if (!raw) headers['Content-Type'] = 'application/json';
            const res = await fetch(url, { method, headers, credentials: 'same-origin', body: raw ? body : (body ? JSON.stringify(body) : null) });
            let data = {}; try { data = await res.json(); } catch (e) { data = {}; }
            if (!res.ok) { const err = new Error(data.message || ('HTTP ' + res.status)); err.data = data; err.status = res.status; throw err; }
            return data;
        },
        choose(f) {
            this.error = null;
            if (!f) return;
            if (!/\.zip$/i.test(f.name)) { this.error = this.cfg.text.notZip; return; }
            if (f.size > this.cfg.max) { this.error = this.cfg.text.tooBig; return; }
            this.file = f; this.upload();
        },
        async upload() {
            this.step = 'upload'; this.sent = 0;
            try {
                const start = await this.api(this.cfg.start, 'POST', { name: this.file.name, size: this.file.size });
                this.id = start.id;
                while (this.sent < this.file.size) {
                    const chunk = this.file.slice(this.sent, this.sent + start.chunk_size);
                    let tries = 0;
                    for (;;) {
                        try {
                            const r = await this.api(`${this.cfg.base}/${this.id}/chunk?offset=${this.sent}`, 'POST', chunk, true);
                            this.sent = r.received; break;
                        } catch (e) {
                            if (e.status === 409 && e.data && typeof e.data.received === 'number') { this.sent = e.data.received; break; }
                            if (++tries >= 4) throw e;
                            await new Promise((r) => setTimeout(r, 1000 * tries));
                        }
                    }
                }
                this.step = 'check';
                const r = await this.api(`${this.cfg.base}/${this.id}/inspect`);
                this.info = r.backup;
                this.website = r.suggested_website ? String(r.suggested_website) : '';
                this.parts = ['files', 'databases', 'mail'].filter((p) => (this.info.contains || []).includes(p));
                this.step = 'choose';
            } catch (e) { this.error = e.message; this.step = 'pick'; this.id = null; }
        },
        async restore() {
            if (!this.website || this.parts.length === 0) return;
            if (!confirm(this.cfg.text.confirm)) return;
            this.busy = true; this.error = null;
            try {
                const r = await this.api(`${this.cfg.base}/${this.id}/restore`, 'POST', { website: Number(this.website), parts: this.parts, safety_target: this.safety ? Number(this.safety) : null });
                window.location = r.redirect;
            } catch (e) { this.error = e.message; this.busy = false; }
        },
        async cancel() {
            if (this.id) { try { await this.api(`${this.cfg.base}/${this.id}`, 'DELETE'); } catch (e) { /* already gone */ } }
            Object.assign(this, { step: 'pick', file: null, id: null, sent: 0, info: null, error: null });
        },
        size(b) { const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return (i ? b.toFixed(1) : b) + ' ' + u[i]; },
    }));
});
