{{-- Styles for the database screens only (kept here, so panel CSS stays untouched). --}}
<style>
.db-page { display: flex; flex-direction: column; gap: 20px; }
.db-page td.num, .db-usage strong { white-space: nowrap; }
.db-name { display: flex; align-items: center; gap: 12px; }
.db-icon { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; flex: none; background: var(--info-bg); color: var(--info-ink); }
.db-icon svg { width: 17px; height: 17px; }
.db-name .mono { font-size: 13.5px; font-weight: 700; }
.db-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
.db-actions form { display: inline; }
@media (min-width: 721px) { .db-actions { flex-wrap: nowrap; } }
.db-tag { display: inline-flex; align-items: center; gap: 5px; font: 600 12px/1 ui-monospace, Menlo, monospace; padding: 5px 8px; border-radius: var(--radius-pill); background: var(--surface-sunken); border: 1px solid var(--line); }
.db-tag.ro { border-style: dashed; }
.db-tag b { font: 700 10px/1 var(--font); text-transform: uppercase; letter-spacing: .04em; color: var(--ink-muted); }
.db-prefix { display: flex; align-items: stretch; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); overflow: hidden; }
.db-prefix:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }
.db-prefix > span { display: flex; align-items: center; padding: 0 4px 0 12px; font: 600 13.5px/1 ui-monospace, Menlo, monospace; color: var(--ink-muted); background: var(--surface-sunken); border-right: 1px solid var(--line); padding-right: 10px; white-space: nowrap; }
.db-prefix .input { border: 0; border-radius: 0; background: transparent; box-shadow: none; font-family: ui-monospace, Menlo, monospace; min-width: 0; }
.db-secret { border: 1px solid color-mix(in srgb, var(--status-online) 40%, var(--line)); background: color-mix(in srgb, var(--status-online) 7%, var(--surface-raised)); }
.db-secret .kv dd { display: flex; align-items: center; gap: 8px; font-family: ui-monospace, Menlo, monospace; overflow-wrap: anywhere; }
.db-copy { border: 1px solid var(--line); background: var(--surface-raised); color: var(--ink-muted); border-radius: 8px; padding: 4px 8px; font: 600 12px/1 var(--font); cursor: pointer; flex: none; }
.db-copy:hover { color: var(--ink); border-color: var(--accent); }
.db-checks { display: grid; gap: 8px; max-height: 220px; overflow: auto; padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-sunken); }
.db-checks .check span { font-family: ui-monospace, Menlo, monospace; font-size: 13px; }
.db-pma { display: inline-flex; align-items: center; gap: 7px; }
.db-pma .pma-mark { width: 18px; height: 18px; border-radius: 5px; display: grid; place-items: center; font: 800 9px/1 var(--font); background: #F89C0E; color: #3A2400; }
.db-connect code { font-family: ui-monospace, Menlo, monospace; font-size: 12.5px; background: var(--surface-sunken); border: 1px solid var(--line); border-radius: 6px; padding: 2px 6px; }
.db-usage { min-width: 140px; }
.db-usage .bar { display: block; height: 6px; border-radius: 3px; background: var(--surface-sunken); overflow: hidden; margin-top: 6px; }
.db-usage .bar > span { display: block; height: 100%; background: var(--accent); border-radius: 3px; }
.db-usage .bar > span.full { background: var(--status-warning); }
.db-list-tags { display: flex; flex-wrap: wrap; gap: 6px; }
@media (max-width: 720px) {
    .db-hide-sm { display: none; }
    .db-actions .btn-text { display: none; }
}
</style>
