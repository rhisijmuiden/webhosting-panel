{{-- Styles for the cron job screens only (kept in the package, so panel CSS stays untouched). --}}
<style>
.cj-page { display: grid; gap: 20px; }
.cj-page > .page-head { margin-bottom: 0; }
.cj-name { display: inline-flex; align-items: center; gap: 10px; }
.cj-badge { display: inline-grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; flex: none;
    background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent); }
.cj-badge svg { width: 17px; height: 17px; }
.cj-badge.off { background: var(--surface-sunken); color: var(--ink-muted); }
.cj-cmd { font: 12px/1.4 ui-monospace, Menlo, monospace; color: var(--ink-muted); max-width: 46ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
.cj-when { display: grid; gap: 2px; }
.cj-when .mono { font-size: 12px; color: var(--ink-muted); }
#f-schedule, #f-command { font-family: ui-monospace, Menlo, monospace; }
.cj-presets { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.cj-presets button { font-size: 12px; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface-sunken); color: inherit; cursor: pointer; }
.cj-presets button:hover, .cj-presets button.is-on { border-color: var(--accent); color: var(--accent); }
.cj-preview { font-size: 13px; margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: center; }
.cj-preview .next { color: var(--ink-muted); }
.cj-cron-help { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 4px; margin-top: 6px; font-size: 11px; color: var(--ink-muted); text-align: center; }
.cj-cron-help span { background: var(--surface-sunken); border-radius: 6px; padding: 3px 2px; }
.cj-runs .cj-out { margin: 8px 0 0; max-height: 360px; }
.cj-runs details summary { cursor: pointer; color: var(--accent); font-size: 13px; }
.cj-runs td { vertical-align: top; }
.cj-filter { display: flex; gap: 8px; align-items: center; }
.cj-filter .select { min-width: 220px; }
.cj-examples { margin: 0; padding-left: 18px; display: grid; gap: 4px; font-size: 13px; }
.cj-examples code { font-size: 12px; }
.input-group { display: flex; align-items: center; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); padding-left: 12px; }
.input-group:focus-within { border-color: var(--accent); }
.input-group > span { white-space: nowrap; }
.input-group .input { border: 0; background: transparent; padding-left: 2px; }
@media (max-width: 640px) { .cj-cmd { max-width: 28ch; } .cj-filter { flex-wrap: wrap; } .cj-filter .select { min-width: 0; width: 100%; } }
</style>
