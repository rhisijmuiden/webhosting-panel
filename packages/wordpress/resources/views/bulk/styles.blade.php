@once
<style>
    .wpb { display: flex; flex-direction: column; gap: 20px; padding-bottom: 90px; }
    .wpb-step { display: flex; align-items: center; gap: 10px; }
    .wpb-step b { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; font-size: 13px; background: var(--nacht); color: #fff; flex: none; }
    :root[data-theme="dark"] .wpb-step b { background: var(--koraal); color: var(--nacht); }
    @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .wpb-step b { background: var(--koraal); color: var(--nacht); } }
    .wpb-group + .wpb-group { margin-top: 18px; }
    .wpb-group h3 { margin: 0 0 10px; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-muted); }
    .wpb-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
    .wpb-tile { position: relative; display: flex; gap: 12px; padding: 14px; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); cursor: pointer; transition: border-color .15s, box-shadow .15s; }
    .wpb-tile:hover { border-color: color-mix(in srgb, var(--koraal) 45%, var(--line)); }
    .wpb-tile input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
    .wpb-tile:has(input:checked) { border-color: var(--koraal); box-shadow: 0 0 0 3px color-mix(in srgb, var(--koraal) 22%, transparent); }
    .wpb-tile:has(input:focus-visible) { outline: 2px solid var(--lucht); outline-offset: 2px; }
    .wpb-tile .ico { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; flex: none; background: color-mix(in srgb, var(--koraal) 14%, transparent); color: var(--accent-text); }
    .wpb-tile .ico svg { width: 18px; height: 18px; }
    .wpb-tile.is-danger .ico { background: var(--bad-bg); color: var(--bad-ink); }
    .wpb-tile strong { display: block; font-size: 14.5px; }
    .wpb-tile small { display: block; color: var(--ink-muted); font-size: 12.5px; line-height: 1.45; margin-top: 3px; }
    .wpb-options { margin-top: 18px; padding: 16px; border-radius: var(--radius-md); background: var(--surface-sunken); display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px 22px; border: 0; }
    .wpb-options legend { font-weight: 700; padding: 0; margin-bottom: 4px; float: left; width: 100%; }
    .wpb-options .note { grid-column: 1 / -1; margin: 0; color: var(--ink-muted); font-size: 13px; }
    .wpb-options input[type=text], .wpb-options input[type=number], .wpb-options select { min-height: 40px; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); color: var(--ink); font: inherit; font-size: 14px; padding: 0 10px; width: 100%; }
    .wpb-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 0 20px 14px; }
    .wpb-filters select, .wpb-filters input[type=search] { min-height: 40px; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); color: var(--ink); font: inherit; font-size: 14px; padding: 0 10px; max-width: 100%; }
    .wpb-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 20px 14px; }
    .wpb-chip { border: 1px solid var(--line); background: var(--surface-raised); color: var(--ink-muted); border-radius: var(--radius-pill); padding: 5px 12px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; gap: 6px; align-items: center; }
    .wpb-chip[aria-pressed=true] { background: var(--nav-active-bg, var(--nacht)); color: var(--nav-active-ink, #fff); border-color: transparent; }
    .wpb-chip .n { font-variant-numeric: tabular-nums; opacity: .8; }
    .wpb-selbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 20px; border-top: 1px solid var(--line-soft); border-bottom: 1px solid var(--line-soft); font-size: 13.5px; }
    .wpb-selbar .grow { flex: 1; }
    .wpb-dock { position: sticky; bottom: 12px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; padding: 12px 16px; border-radius: var(--radius-lg); background: var(--nacht); color: #fff; border: 1px solid #2B3A5C; box-shadow: 0 18px 40px -18px rgba(10, 17, 32, .6); }
    .wpb-dock .what { flex: 1 1 260px; min-width: 0; }
    .wpb-dock .what strong { display: block; font-size: 15px; }
    .wpb-dock .what span { font-size: 12.5px; color: #C9D2E3; }
    .wpb-dock label { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #C9D2E3; }
    .wpb-dock select { min-height: 36px; border-radius: 8px; border: 1px solid #2B3A5C; background: #1B2A4A; color: #fff; font: inherit; font-size: 13px; padding: 0 8px; }
    .wpb-dock .btn-primary[disabled] { opacity: .5; cursor: not-allowed; }
    .wpb-tags { display: flex; flex-wrap: wrap; gap: 4px; }
    .wpb-tag { font-size: 11.5px; padding: 1px 7px; border-radius: 6px; background: var(--surface-sunken); color: var(--ink-muted); }
    .wpb-tag.is-up { background: var(--warn-bg); color: var(--warn-ink); }
    /* Run page */
    .wpb-progress { display: flex; height: 14px; border-radius: var(--radius-pill); overflow: hidden; background: var(--surface-sunken); }
    .wpb-progress > span { display: block; height: 100%; transition: width .4s ease; }
    .wpb-progress .ok { background: var(--status-online); } .wpb-progress .att { background: var(--status-warning); }
    .wpb-progress .bad { background: var(--status-offline); } .wpb-progress .skip { background: color-mix(in srgb, var(--ink-muted) 45%, transparent); }
    .wpb-progress .busy { background: repeating-linear-gradient(45deg, var(--lucht) 0 8px, color-mix(in srgb, var(--lucht) 55%, transparent) 8px 16px); background-size: 22px 22px; animation: wpb-move 1s linear infinite; }
    @keyframes wpb-move { to { background-position: 22px 0; } }
    @media (prefers-reduced-motion: reduce) { .wpb-progress .busy { animation: none; } }
    .wpb-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
    .wpb-pct { font-size: 40px; font-weight: 800; font-stretch: 125%; line-height: 1; font-variant-numeric: tabular-nums; }
    .wpb-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 12px; margin-top: 16px; }
    .wpb-kpis div { display: flex; flex-direction: column; gap: 2px; padding-left: 12px; border-left: 3px solid var(--line); }
    .wpb-kpis span { font-size: 12.5px; color: var(--ink-muted); font-weight: 600; }
    .wpb-kpis strong { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .wpb-kpis .is-ok { border-color: var(--status-online); } .wpb-kpis .is-att { border-color: var(--status-warning); }
    .wpb-kpis .is-bad { border-color: var(--status-offline); } .wpb-kpis .is-busy { border-color: var(--lucht); }
    .wpb-details { margin: 6px 0 0; padding: 0; list-style: none; font-size: 12.5px; color: var(--ink-muted); }
    .wpb-details li { overflow-wrap: anywhere; }
    .wpb-row-msg { font-size: 13.5px; overflow-wrap: anywhere; }
    .wpb-spin { width: 14px; height: 14px; border-radius: 50%; border: 2px solid color-mix(in srgb, var(--ink-muted) 35%, transparent); border-top-color: var(--accent); animation: wpb-spin .8s linear infinite; display: inline-block; vertical-align: -2px; }
    @keyframes wpb-spin { to { transform: rotate(360deg); } }
    .wpb-banner { display: flex; gap: 10px; align-items: flex-start; padding: 12px 16px; border-radius: var(--radius-md); background: var(--warn-bg); color: var(--warn-ink); font-size: 14px; }
    @media (max-width: 720px) {
        .wpb-dock { bottom: 70px; }
        .wpb-tiles { grid-template-columns: 1fr; }
        .wpb-hide-sm { display: none; }
    }
</style>
@endonce
