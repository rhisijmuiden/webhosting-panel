@once
<style>
.wpi .wpi-kpi-icon { display:inline-grid; place-items:center; width:28px; height:28px; border-radius:8px; background:var(--surface-sunken); color:var(--ink-muted); }
.wpi .wpi-kpi-icon svg { width:16px; height:16px; }
.wpi .wpi-kpi-icon.is-bad { background:var(--bad-bg); color:var(--bad-ink); }
.wpi .wpi-kpi-icon.is-warn { background:var(--warn-bg); color:var(--warn-ink); }
.wpi .wpi-kpi-icon.is-ok { background:var(--ok-bg); color:var(--ok-ink); }
.wpi .wpi-comp { border-left:4px solid var(--wpi-c, var(--line)); }
.wpi .wpi-comp.is-core { --wpi-c: #E0661F; }
.wpi .wpi-comp.is-plugin { --wpi-c: #D9A21B; }
.wpi .wpi-comp.has-malware { --wpi-c: #7A2E8E; }
.wpi .wpi-file { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:13px; word-break:break-all; }
.wpi .wpi-mal { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:11.5px; font-weight:700; background:#F3E8F7; color:#7A2E8E; }
:root[data-theme="dark"] .wpi .wpi-mal { background:#3A2142; color:#E3B8EF; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .wpi .wpi-mal { background:#3A2142; color:#E3B8EF; } }
.wpi tr.is-accepted td { opacity:.6; }
.wpi .wpi-log { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12px; white-space:pre-wrap; margin:6px 0 0; max-height:160px; overflow:auto; }
.wpi .wpi-how { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:16px; }
.wpi .wpi-how b { display:block; margin-bottom:4px; }
@media (max-width: 860px) { .wpi .wpi-how { grid-template-columns:1fr; } .wpi .bento > [class*="span-"] { grid-column: span 12; } }
@media (min-width: 861px) and (max-width: 1100px) { .wpi .bento > .span-3 { grid-column: span 6; } }
</style>
@endonce
