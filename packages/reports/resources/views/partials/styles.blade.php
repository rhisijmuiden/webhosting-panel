<style>
.rp-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(300px, 380px); gap: 16px; align-items: start; }
@media (max-width: 1100px) { .rp-grid { grid-template-columns: 1fr; } }
.rp-paper { background: #F6F7FB; border-radius: var(--radius-lg); padding: 24px 12px; display: flex; justify-content: center; overflow-x: auto; }
:root[data-theme="dark"] .rp-paper { background: #0E172A; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .rp-paper { background: #0E172A; } }
.rp-months { display: flex; flex-wrap: wrap; gap: 6px; }
.rp-months a { padding: 6px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; text-decoration: none; color: var(--ink); background: var(--surface-sunken); }
.rp-months a[aria-current="page"] { background: var(--nacht); color: #fff; }
:root[data-theme="dark"] .rp-months a[aria-current="page"] { background: var(--ink); color: var(--nacht); }
.rp-side { display: grid; gap: 16px; }
.rp-who { display: flex; flex-wrap: wrap; gap: 6px; }
.rp-who span { font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--surface-sunken); }
.rp-actions { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }
.rp-actions form { margin: 0; }
.rp-summary { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 12px; color: var(--ink-muted); }
.rp-summary b { color: var(--ink); font-weight: 700; }
</style>
