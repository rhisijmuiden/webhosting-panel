/* Webhosting Panel UI behaviour. Loaded before Alpine, so components exist when it starts. */
(function () {
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  let i18n = null;
  const t = (key) => {
    if (i18n === null) {
      try { i18n = JSON.parse(document.querySelector('meta[name="panel-i18n"]')?.content || '{}'); } catch (e) { i18n = {}; }
    }
    return i18n[key] || key;
  };

  async function request(url, method = 'GET', body = null) {
    const res = await fetch(url, {
      method,
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf(),
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : null,
    });
    let data = {};
    try { data = await res.json(); } catch (e) { data = {}; }
    if (!res.ok) {
      const err = new Error(data?.error?.message || data?.message || ('HTTP ' + res.status));
      err.status = res.status;
      throw err;
    }
    return data;
  }

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  document.addEventListener('alpine:init', () => {
    // Theme: light, dark or follow the system. Stored per browser.
    window.Alpine.store('theme', {
      value: (() => { try { return localStorage.getItem('whp-theme') || 'system'; } catch (e) { return 'system'; } })(),
      set(v) {
        this.value = v;
        try { localStorage.setItem('whp-theme', v); } catch (e) { /* private mode */ }
        applyTheme(v);
      },
    });

    window.Alpine.data('infoDialog', (cfg) => ({
      cfg,
      loading: false,
      info: null,
      check: null,
      error: null,
      job: null,
      jobLog: [],
      jobStatus: null,
      channel: cfg.channel,
      async open() {
        this.$refs.dialog.showModal();
        if (!this.info) await this.load();
      },
      close() { this.$refs.dialog.close(); },
      async load() {
        this.loading = true; this.error = null;
        try { this.info = await request(cfg.infoUrl); this.channel = this.info.channel || cfg.channel; }
        catch (e) { this.error = e.message; }
        finally { this.loading = false; }
      },
      async checkUpdates() {
        this.loading = true; this.error = null; this.check = null;
        try { this.check = await request(cfg.checkUrl, 'POST', { channel: this.channel }); }
        catch (e) { this.error = e.message; }
        finally { this.loading = false; }
      },
      async runUpdate() {
        if (!confirm(t('update_confirm'))) return;
        await this.startJob(cfg.updateUrl, {});
      },
      async switchChannel() {
        if (this.channel === this.info?.channel) return;
        if (!confirm(t('channel_confirm').replace(':channel', this.channel))) { this.channel = this.info?.channel; return; }
        await this.startJob(cfg.channelUrl, { channel: this.channel });
      },
      async startJob(url, body) {
        this.error = null; this.jobLog = []; this.jobStatus = 'running';
        try {
          const res = await request(url, 'POST', body);
          this.job = res.job;
          await this.poll();
        } catch (e) { this.error = e.message; this.jobStatus = null; }
      },
      async poll() {
        // The panel restarts during an update; keep asking until it answers again.
        for (let i = 0; i < 600; i++) {
          await sleep(2000);
          try {
            const job = await request(cfg.jobUrl.replace('__JOB__', this.job));
            this.jobLog = job.log || [];
            this.jobStatus = job.status;
            if (job.status !== 'running') {
              if (job.status === 'succeeded') { await sleep(1500); window.location.reload(); }
              else { this.error = job.error?.message || t('update_failed'); }
              return;
            }
          } catch (e) {
            this.jobStatus = 'restarting';
          }
        }
      },
    }));

    window.Alpine.data('qr', (text) => ({
      init() {
        if (typeof window.qrcode !== 'function') return;
        const qr = window.qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        this.$el.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
      },
    }));
  });

  function applyTheme(v) {
    if (v === 'system') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', v);
  }
  try { applyTheme(localStorage.getItem('whp-theme') || 'system'); } catch (e) { /* ignore */ }

  // Forms with data-confirm ask before they submit.
  document.addEventListener('submit', (e) => {
    const msg = e.target.getAttribute('data-confirm');
    if (msg && !confirm(msg)) e.preventDefault();
  });
})();
