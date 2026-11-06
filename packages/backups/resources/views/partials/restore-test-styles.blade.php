{{-- Styles of the restore test screens only. --}}
<style>
    .rt-hero { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 16px; margin-bottom: 16px; }
    @media (max-width: 1000px) { .rt-hero { grid-template-columns: 1fr; } }
    .rt-state { display: flex; gap: 24px; align-items: center; padding: 24px; border-radius: var(--radius-lg); background: var(--surface-raised); border: 1px solid var(--line); border-left: 4px solid var(--tc, var(--line)); }
    @media (max-width: 560px) { .rt-state { flex-direction: column; align-items: flex-start; } }
    .rt-state h2 { font-size: 24px; font-weight: 800; font-stretch: 115%; margin: 2px 0 6px; }
    .rt-state p { margin: 0; color: var(--ink-muted); font-size: 14px; }
    .rt-state .rt-meta { display: flex; flex-wrap: wrap; gap: 8px 16px; margin-top: 12px; font-size: 13px; color: var(--ink-muted); }
    .rt-state .rt-meta span { display: inline-flex; align-items: center; gap: 6px; }
    .rt-state .rt-meta svg { width: 14px; height: 14px; }
    .rt-ok { --tc: var(--status-online); } .rt-warn { --tc: var(--status-warning); } .rt-bad { --tc: var(--status-offline); } .rt-none { --tc: #5B8DEF; }
    .rt-tiles { display: grid; grid-template-rows: repeat(3, auto); gap: 12px; }
    .rt-tile { display: grid; grid-template-columns: 36px 1fr; gap: 2px 12px; align-items: center; padding: 14px 16px; border-radius: var(--radius-md); background: var(--surface-raised); border: 1px solid var(--line); }
    .rt-tile .ic { grid-row: span 2; width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: var(--info-bg); color: var(--info-ink); }
    .rt-tile .ic.ok { background: var(--ok-bg); color: var(--ok-ink); } .rt-tile .ic.warn { background: var(--warn-bg); color: var(--warn-ink); } .rt-tile .ic.bad { background: var(--bad-bg); color: var(--bad-ink); }
    .rt-tile .ic svg { width: 18px; height: 18px; }
    .rt-tile span { font-size: 12.5px; font-weight: 600; color: var(--ink-muted); }
    .rt-tile strong { font-size: 17px; font-weight: 800; overflow-wrap: anywhere; }
    .rt-steps { list-style: none; margin: 0; padding: 0; counter-reset: s; display: flex; flex-direction: column; gap: 14px; }
    .rt-steps li { counter-increment: s; display: grid; grid-template-columns: 28px 1fr; gap: 12px; font-size: 14px; }
    .rt-steps li::before { content: counter(s); width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 13px; background: var(--nav-active-bg); color: var(--nav-active-ink); }
    .rt-steps strong { display: block; }
    .rt-steps small { color: var(--ink-muted); }
    .rt-item { border: 1px solid var(--line); border-radius: var(--radius-md); margin-bottom: 12px; overflow: hidden; background: var(--surface-raised); }
    .rt-item > header { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; padding: 14px 16px; background: var(--surface-sunken); }
    .rt-item > header .who { display: flex; align-items: center; gap: 10px; font-weight: 700; flex: 1; min-width: 200px; }
    .rt-item > header .who svg { width: 18px; height: 18px; color: var(--ink-muted); }
    .rt-item > header .small { color: var(--ink-muted); }
    .rt-item .checks { padding: 4px 16px 12px; }
    .rt-item .checks li:first-child { padding-top: 10px; }
    .rt-item .checks li { flex-wrap: wrap; }
    .rt-item .checks li small { max-width: 60%; overflow-wrap: anywhere; }
    @media (max-width: 640px) { .rt-item .checks li small { max-width: 100%; margin-left: 30px; text-align: left; } }
    .rt-list { list-style: none; margin: 0; padding: 0; }
    .rt-list li { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-top: 1px solid var(--line-soft); font-size: 14px; }
    .rt-list li:first-child { border-top: 0; }
    .rt-state .ring-label strong { font-size: 26px; }
    .rt-form h3 { font-size: 14px; margin: 8px 0 4px; }
</style>
