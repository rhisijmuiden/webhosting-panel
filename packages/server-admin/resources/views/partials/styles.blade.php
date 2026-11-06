<style>
.sv-tabs { display: flex; gap: 4px; flex-wrap: wrap; background: var(--surface-sunken); border: 1px solid var(--line); border-radius: var(--radius-md); padding: 4px; width: fit-content; max-width: 100%; }
.sv-tabs a { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border-radius: 8px; font-size: 14px; font-weight: 600; color: var(--ink-muted); text-decoration: none; }
.sv-tabs a[aria-current="page"] { background: var(--nav-active-bg); color: var(--nav-active-ink); }
.sv-tabs a:hover:not([aria-current]) { color: var(--ink); }
.sv-stack { display: flex; flex-direction: column; gap: 16px; }
.sv-hero { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
@media (max-width: 1100px) { .sv-hero { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .sv-hero { grid-template-columns: 1fr; } }
.sv-tile { display: flex; flex-direction: column; gap: 6px; padding: 18px; border-radius: var(--radius-lg); background: var(--surface-raised); border: 1px solid var(--line); border-top: 3px solid var(--tc, var(--line)); min-width: 0; }
.sv-tile .k { font-size: 12.5px; font-weight: 600; color: var(--ink-muted); }
.sv-tile .v { font-size: 26px; font-weight: 800; font-stretch: 125%; line-height: 1.15; overflow-wrap: anywhere; }
.sv-tile .h { font-size: 12.5px; color: var(--ink-muted); overflow-wrap: anywhere; }
.sv-ok { --tc: var(--status-online, #2E9E5B); } .sv-warn { --tc: var(--status-warning, #D9A21B); } .sv-bad { --tc: var(--status-offline, #C23434); } .sv-info { --tc: #5B8DEF; }
.sv-reasons { margin: 6px 0 0; padding-left: 18px; font-size: 13.5px; }
.sv-cvss { display: inline-flex; align-items: center; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; background: var(--surface-sunken); }
.sv-cvss.crit { background: #C23434; color: #fff; } .sv-cvss.high { background: #E0661F; color: #fff; } .sv-cvss.med { background: #D9A21B; color: #1b1b1b; }
.sv-log { max-height: 460px; }
.sv-phase { display: flex; flex-wrap: wrap; gap: 6px; list-style: none; margin: 0; padding: 0; }
.sv-phase li { padding: 4px 12px; border-radius: 999px; font-size: 12.5px; font-weight: 600; background: var(--surface-sunken); color: var(--ink-muted); }
.sv-phase li.done { background: var(--ok-bg); color: var(--ok-ink); }
.sv-phase li.now { background: var(--nav-active-bg); color: var(--nav-active-ink); }
.sv-days { display: flex; flex-wrap: wrap; gap: 6px; }
.sv-days label { position: relative; }
.sv-days input { position: absolute; opacity: 0; inset: 0; }
.sv-days span { display: inline-grid; place-items: center; min-width: 46px; min-height: 36px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--line); font-size: 13px; font-weight: 700; color: var(--ink-muted); cursor: pointer; }
.sv-days input:checked + span { background: var(--nav-active-bg); color: var(--nav-active-ink); border-color: transparent; }
.sv-days input:focus-visible + span { outline: 2px solid var(--lucht); }
.sv-task { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 16px 24px; align-items: start; }
@media (max-width: 900px) { .sv-task { grid-template-columns: 1fr; } }
.sv-task .desc { font-size: 13.5px; color: var(--ink-muted); margin: 4px 0 0; }
.sv-inline { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.sv-inline .field { min-width: 120px; flex: 1 1 120px; }
.sv-danger { border-color: color-mix(in srgb, #C23434 40%, var(--line)); }
.sv-timeline { list-style: none; margin: 0; padding: 0; }
.sv-timeline li { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 4px 12px; align-items: center; padding: 12px 20px; border-top: 1px solid var(--line-soft, var(--line)); }
.sv-timeline li:first-child { border-top: 0; }
.sv-timeline .meta { grid-column: 2 / 4; font-size: 12.5px; color: var(--ink-muted); }
.sv-timeline a { color: inherit; font-weight: 700; text-decoration: none; } .sv-timeline a:hover { text-decoration: underline; }
@media (max-width: 560px) { .sv-timeline li { grid-template-columns: auto minmax(0, 1fr); } .sv-timeline li > :last-child:not(.meta) { grid-column: 2; } }
</style>
