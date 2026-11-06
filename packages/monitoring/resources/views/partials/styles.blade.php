<style>
.mo-tabs { display: flex; gap: 4px; flex-wrap: wrap; background: var(--surface-sunken); border: 1px solid var(--line); border-radius: var(--radius-md); padding: 4px; width: fit-content; max-width: 100%; }
.mo-tabs a { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border-radius: 8px; font-size: 14px; font-weight: 600; color: var(--ink-muted); text-decoration: none; }
.mo-tabs a[aria-current="page"] { background: var(--nav-active-bg); color: var(--nav-active-ink); }
.mo-tabs a:hover:not([aria-current]) { color: var(--ink); }
.mo-tabs .count { display: inline-grid; place-items: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--koraal); color: var(--nacht); font-size: 11.5px; font-weight: 800; }
.mo-hero { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
@media (max-width: 1100px) { .mo-hero { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .mo-hero { grid-template-columns: 1fr; } }
.mo-tile { display: flex; flex-direction: column; gap: 6px; padding: 18px; border-radius: var(--radius-lg); background: var(--surface-raised); border: 1px solid var(--line); border-top: 3px solid var(--tc, var(--line)); min-width: 0; }
.mo-tile .k { font-size: 12.5px; font-weight: 600; color: var(--ink-muted); }
.mo-tile .v { font-size: 26px; font-weight: 800; font-stretch: 125%; line-height: 1.15; }
.mo-tile .h { font-size: 12.5px; color: var(--ink-muted); }
.mo-ok { --tc: var(--status-online); } .mo-warn { --tc: var(--status-warning); } .mo-bad { --tc: var(--status-offline); } .mo-info { --tc: #5B8DEF; }
.mo-banner { display: flex; align-items: center; gap: 16px; padding: 18px 22px; border-radius: var(--radius-lg); color: #fff; background: var(--status-online); }
.mo-banner.warning { background: #B87800; } .mo-banner.critical { background: #B42B2B; }
.mo-banner strong { font-size: 18px; font-weight: 800; font-stretch: 115%; }
.mo-banner .mo-pulse { width: 14px; height: 14px; border-radius: 50%; background: #fff; box-shadow: 0 0 0 0 rgba(255,255,255,.7); animation: mo-pulse 2s infinite; flex: none; }
@keyframes mo-pulse { 70% { box-shadow: 0 0 0 10px rgba(255,255,255,0); } 100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); } }
@media (prefers-reduced-motion: reduce) { .mo-banner .mo-pulse { animation: none; } }
.mo-bars { display: flex; gap: 2px; align-items: stretch; height: 30px; }
.mo-bars span { flex: 1 1 0; min-width: 2px; border-radius: 2px; background: var(--status-online); opacity: .9; }
.mo-bars span.none { background: var(--surface-sunken); opacity: 1; }
.mo-bars span.warn { background: #E0A526; } .mo-bars span.bad { background: var(--status-offline); }
.mo-bars span:hover { opacity: 1; outline: 2px solid var(--ink); outline-offset: 1px; }
.mo-bars.sm { height: 18px; gap: 1px; }
.mo-axis { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--ink-muted); margin-top: 6px; }
.mo-comp { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px 16px; padding: 16px 20px; border-top: 1px solid var(--line-soft); }
.mo-comp:first-child { border-top: 0; }
.mo-comp .bars { grid-column: 1 / -1; }
.mo-comp strong { font-size: 15px; }
.mo-state { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: var(--ok-ink); }
.mo-state::before { content: ""; width: 9px; height: 9px; border-radius: 50%; background: currentColor; }
.mo-state.warning { color: var(--warn-ink); } .mo-state.critical { color: var(--bad-ink); } .mo-state.unknown { color: var(--ink-muted); }
.mo-pct { font-variant-numeric: tabular-nums; font-weight: 700; }
.mo-pct.low { color: var(--bad-ink); } .mo-pct.mid { color: var(--warn-ink); }
.mo-site a { color: inherit; font-weight: 700; text-decoration: none; } .mo-site a:hover { text-decoration: underline; }
.mo-site .small { display: block; }
.mo-disk { display: flex; flex-direction: column; gap: 14px; padding: 4px 0; }
.mo-svc { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; }
.mo-svc div { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-radius: 10px; background: var(--surface-sunken); font-size: 13.5px; font-weight: 600; min-width: 0; }
.mo-svc div span:last-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mo-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--status-online); flex: none; }
.mo-dot.warning { background: #E0A526; } .mo-dot.critical { background: var(--status-offline); }
.mo-facts { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 14px; margin: 0; }
.mo-facts dt { color: var(--ink-muted); } .mo-facts dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; }
.mo-incidents { list-style: none; margin: 0; padding: 0; }
.mo-incidents li { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 2px 12px; align-items: center; padding: 12px 20px; border-top: 1px solid var(--line-soft); }
.mo-incidents li:first-child { border-top: 0; }
.mo-incidents .meta { grid-column: 2 / 4; font-size: 12.5px; color: var(--ink-muted); }
.mo-spark { width: 100%; height: 120px; display: block; }
.mo-spark .line { fill: none; stroke: var(--koraal); stroke-width: 2; vector-effect: non-scaling-stroke; }
.mo-spark .area { fill: color-mix(in srgb, var(--koraal) 14%, transparent); }
.mo-spark .down { fill: color-mix(in srgb, var(--status-offline) 30%, transparent); }
.mo-channel { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 4px 16px; align-items: center; padding: 16px 20px; border-top: 1px solid var(--line-soft); }
.mo-channel:first-child { border-top: 0; }
.mo-channel .ico { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: var(--surface-sunken); }
.mo-channel .ico.teams { background: #5B5FC7; color: #fff; font-weight: 800; }
.mo-channel .acts { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
@media (max-width: 700px) { .mo-channel { grid-template-columns: auto minmax(0, 1fr); } .mo-channel .acts { grid-column: 1 / -1; justify-content: flex-start; } }
.mo-steps { margin: 0; padding-left: 20px; font-size: 13.5px; line-height: 1.7; color: var(--ink-muted); }
.mo-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.mo-filters .input { width: 200px; max-width: 100%; min-height: 36px; }
.mo-start { align-self: start; }
.mo-notice { padding: 14px 18px; border-radius: 12px; border: 1px solid var(--line); border-left: 4px solid #5B8DEF; background: var(--surface-raised); }
.mo-notice.maintenance { border-left-color: #5B8DEF; } .mo-notice.degraded { border-left-color: #E0A526; } .mo-notice.outage { border-left-color: var(--status-offline); }
.mo-notice h3 { margin: 0 0 4px; font-size: 15px; } .mo-notice p { margin: 0; font-size: 14px; color: var(--ink-muted); white-space: pre-line; }
</style>
