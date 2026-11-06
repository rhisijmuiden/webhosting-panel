{{-- Styles for the Node.js & Python screens only (kept in the package, so panel CSS stays untouched). --}}
<style>
.wa-runtime { display: inline-flex; align-items: center; gap: 8px; }
.wa-badge { display: inline-grid; place-items: center; width: 30px; height: 30px; border-radius: 9px; font: 800 11px/1 ui-monospace, Menlo, monospace; letter-spacing: -.02em; flex: none; }
.wa-badge.node { background: color-mix(in srgb, #5FA04E 16%, transparent); color: #3E7E2F; }
.wa-badge.python { background: color-mix(in srgb, #3776AB 16%, transparent); color: #2B5F8C; }
[data-theme="dark"] .wa-badge.node { color: #8BD07A; } [data-theme="dark"] .wa-badge.python { color: #8DB8E6; }
.input-group { display: flex; align-items: center; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); padding-left: 12px; }
.input-group:focus-within { border-color: var(--accent); }
.input-group > span { white-space: nowrap; }
.input-group .input { border: 0; background: transparent; padding-left: 2px; }
.wa-env { display: grid; gap: 8px; }
.wa-env-row { display: grid; grid-template-columns: minmax(120px, 1fr) minmax(160px, 2fr) auto; gap: 8px; align-items: center; }
.wa-presets { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.wa-presets button { font-size: 12px; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface-sunken); color: inherit; cursor: pointer; }
.wa-presets button:hover { border-color: var(--accent); }
.log.wa-log { max-height: 420px; min-height: 160px; }
.wa-log .t { color: #8C98B5; }
.wa-log .warn { color: #FFC56B; } .wa-log .err { color: #FF8A7A; }
.wa-meter { height: 6px; border-radius: 3px; background: var(--surface-sunken); overflow: hidden; margin-top: 4px; }
.wa-meter > span { display: block; height: 100%; background: var(--accent); border-radius: 3px; }
.wa-steps { margin: 0; padding-left: 20px; display: grid; gap: 6px; font-size: 14px; }
@media (max-width: 640px) { .wa-env-row { grid-template-columns: 1fr auto; } .wa-env-row .wa-val { grid-column: 1 / -1; grid-row: 2; } }
</style>
