@once
<style>
    .du { --du-files: var(--koraal); --du-db: #4F7FE0; --du-mail: #1C9D63; }
    @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .du { --du-db: #7EA6FF; --du-mail: #4ACB8C; } }
    :root[data-theme="dark"] .du { --du-db: #7EA6FF; --du-mail: #4ACB8C; }
    .du-stack { display: flex; height: 14px; border-radius: var(--radius-pill); overflow: hidden; background: var(--surface-sunken); }
    .du-stack.is-thin { height: 8px; min-width: 120px; }
    .du-stack > span { display: block; height: 100%; min-width: 0; }
    .du-stack > span + span { box-shadow: -2px 0 0 var(--surface-raised); }
    .du-files { background: var(--du-files); } .du-db { background: var(--du-db); } .du-mail { background: var(--du-mail); }
    .du-legend { display: flex; flex-wrap: wrap; gap: 8px 22px; margin-top: 14px; padding: 0; list-style: none; }
    .du-legend li { display: flex; align-items: baseline; gap: 8px; font-size: 13px; color: var(--ink-muted); }
    .du-legend li i { width: 10px; height: 10px; border-radius: 3px; flex: none; align-self: center; }
    .du-legend strong { color: var(--ink); font-variant-numeric: tabular-nums; font-size: 15px; }
    .du-total { font-size: 34px; font-weight: 800; font-stretch: 125%; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .du-total small { font-size: 14px; font-weight: 600; color: var(--ink-muted); font-stretch: 100%; margin-left: 6px; }
    .du-quota { display: flex; align-items: center; gap: 18px; }
    .du-quota p { margin: 0; }
    .du-note { font-size: 12.5px; color: var(--ink-muted); margin: 12px 0 0; }
    .du-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; font-size: 14px; padding: 0 20px 14px; }
    .du-crumbs a, .du-crumbs span { padding: 3px 8px; border-radius: var(--radius-sm); overflow-wrap: anywhere; }
    .du-crumbs a { color: var(--ink-muted); } .du-crumbs a:hover { background: var(--surface-sunken); color: var(--ink); }
    .du-crumbs span[aria-current] { font-weight: 700; background: var(--surface-sunken); }
    .du-crumbs .sep { color: var(--ink-muted); padding: 0; }
    .du-rows { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--line-soft); }
    .du-row { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(90px, 1fr) 88px 62px; gap: 6px 16px; align-items: center; padding: 11px 20px; border-bottom: 1px solid var(--line-soft); }
    .du-row:last-child { border-bottom: 0; }
    .du-row.is-link { position: relative; }
    .du-row.is-link a.du-stretch::after { content: ''; position: absolute; inset: 0; } .du-row.is-link:hover { background: color-mix(in srgb, var(--surface-sunken) 70%, transparent); }
    .du-name { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .du-name a, .du-name > span:not(.du-ico) { font-weight: 600; color: var(--ink); overflow-wrap: anywhere; min-width: 0; }
    .du-name .muted { font-weight: 400; }
    .du-ico { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; flex: none; background: var(--surface-sunken); color: var(--ink-muted); }
    .du-ico svg { width: 16px; height: 16px; }
    .du-ico.is-folder { background: color-mix(in srgb, var(--koraal) 14%, transparent); color: var(--accent-text); }
    .du-ico.is-db { background: color-mix(in srgb, var(--du-db) 16%, transparent); color: var(--du-db); }
    .du-ico.is-mail { background: color-mix(in srgb, var(--du-mail) 16%, transparent); color: var(--du-mail); }
    .du-bar { height: 8px; border-radius: var(--radius-pill); background: var(--surface-sunken); overflow: hidden; }
    .du-bar > span { display: block; height: 100%; border-radius: inherit; background: var(--du-files); }
    .du-bar.is-db > span { background: var(--du-db); } .du-bar.is-mail > span { background: var(--du-mail); }
    .du-bar > span.warn { background: var(--status-warning); } .du-bar > span.bad { background: var(--status-offline); }
    .du-row.is-stack { grid-template-columns: minmax(0, 1fr) auto; gap: 8px 16px; }
    .du-row.is-stack .du-bar { grid-column: 1 / -1; order: 3; margin-left: 40px; }
    .du-row.is-stack .du-name > span:not(.du-ico) { overflow-wrap: break-word; word-break: normal; }
    .du-ring-label { font-size: 24px; font-weight: 800; font-stretch: 110%; font-variant-numeric: tabular-nums; }
    .du-size { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .du-pct { text-align: right; color: var(--ink-muted); font-size: 13px; font-variant-numeric: tabular-nums; }
    .du-hint { grid-column: 1 / -1; margin: -2px 0 0 40px; font-size: 12.5px; color: var(--ink-muted); display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .du-sub { padding: 12px 20px 4px; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--ink-muted); }
    .du-grid { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr); gap: 16px; align-items: start; }
    .du-side { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
    .du-empty-row { padding: 16px 20px; color: var(--ink-muted); font-size: 14px; }
    .du-scanning { display: inline-flex; align-items: center; gap: 8px; }
    .du-scanning::before { content: ''; width: 10px; height: 10px; border-radius: 50%; background: var(--accent); animation: du-pulse 1.2s ease-in-out infinite; }
    @keyframes du-pulse { 50% { opacity: .3; } }
    .du-site-name { display: flex; flex-direction: column; min-width: 0; }
    .du-site-name a { font-weight: 700; overflow-wrap: anywhere; }
    .du-table td { vertical-align: middle; }
    .du-table .du-col-bar { width: 32%; min-width: 160px; }
    @media (max-width: 1100px) { .du-grid { grid-template-columns: 1fr; } }
    @media (max-width: 640px) {
        .du-row { grid-template-columns: minmax(0, 1fr) auto; }
        .du-row .du-bar { grid-column: 1 / -1; order: 3; }
        .du-row .du-pct { display: none; }
        .du-hint { margin-left: 0; }
        .du-hide-sm { display: none; }
        .du-total { font-size: 28px; }
    }
    @media (prefers-reduced-motion: reduce) { .du-scanning::before { animation: none; } }
</style>
@endonce
