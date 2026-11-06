/* Quick view (phone friendly): live search for customers and domains. Loaded before Alpine. */
(function () {
  // Tables become cards on a phone (mobile.css). Each cell gets the text of its
  // column header as data-label, so every table in the panel works without changes.
  function labelTables(root) {
    (root || document).querySelectorAll('table.table:not(.table-keep)').forEach((table) => {
      const heads = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
      if (!heads.length) return;
      table.querySelectorAll('tbody tr').forEach((tr) => {
        let col = 0;
        Array.from(tr.children).forEach((td) => {
          if (!td.hasAttribute('data-label')) td.setAttribute('data-label', heads[col] || '');
          // A select-checkbox column floats in the corner; the next cell becomes the title.
          if (col === 0 && td.querySelector('input[type=checkbox]') && td.textContent.trim() === '') td.classList.add('m-check');
          col += td.colSpan || 1;
        });
      });
    });
  }
  window.whpLabelTables = labelTables;
  document.addEventListener('DOMContentLoaded', () => {
    labelTables();
    // Tables that Alpine or fetch() fill in later.
    let pending = false;
    new MutationObserver(() => {
      if (pending) return;
      pending = true;
      requestAnimationFrame(() => { pending = false; labelTables(); });
    }).observe(document.body, { childList: true, subtree: true });
  });

  document.addEventListener('alpine:init', () => {
    window.Alpine.data('quickSearch', (url) => ({
      query: '',
      results: [],
      loading: false,
      seq: 0,
      async run() {
        const q = this.query.trim();
        if (q.length < 2) { this.results = []; return; }
        const mine = ++this.seq;
        this.loading = true;
        try {
          const res = await fetch(url + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
          });
          const data = res.ok ? await res.json() : { results: [] };
          if (mine === this.seq) this.results = data.results || [];
        } catch (e) {
          if (mine === this.seq) this.results = [];
        } finally {
          if (mine === this.seq) this.loading = false;
        }
      },
      clear() { this.query = ''; this.results = []; this.$refs.input.focus(); },
    }));

    // Copy a value (a new password) to the clipboard and show "Copied" for a moment.
    window.Alpine.data('copyValue', (value) => ({
      copied: false,
      async copy() {
        try { await navigator.clipboard.writeText(value); this.copied = true; setTimeout(() => { this.copied = false; }, 1800); } catch (e) { /* no clipboard */ }
      },
    }));
  });
})();
