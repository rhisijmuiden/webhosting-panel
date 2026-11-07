@once
<style>
    .cert-kpis { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    @media (max-width: 760px) { .cert-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .cert-kpis .is-bad { color: var(--status-offline); }
    .cert-kpis .is-warn { color: var(--warn-ink); }
    .cert-filter { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
    .cert-filter a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line); font-size: 13px; font-weight: 600; color: var(--ink-muted); text-decoration: none; }
    .cert-filter a.is-on { background: var(--nav-active-bg, var(--ink)); border-color: transparent; color: #fff; }
    .cert-names { display: flex; gap: 6px; flex-wrap: wrap; margin: 0; padding: 0; list-style: none; }
    .cert-names li { font-family: var(--font-mono, ui-monospace, monospace); font-size: 12.5px; padding: 3px 9px; border-radius: 7px; background: var(--surface-sunken); border: 1px solid var(--line); }
    .cert-names li.is-missing { background: var(--warn-bg); color: var(--warn-ink); border-color: transparent; }
    .cert-hero { display: flex; gap: 22px; align-items: center; flex-wrap: wrap; }
    .cert-hero .ring-label strong { display: block; font-size: 28px; font-weight: 800; font-stretch: 125%; line-height: 1; }
    .cert-hero .ring-label small { font-size: 11.5px; color: var(--ink-muted); }
    .cert-hero > div:last-child { flex: 1 1 260px; min-width: 0; }
    .cert-hero h3 { margin: 0 0 4px; font-size: 20px; font-weight: 800; }
    .cert-days { font-variant-numeric: tabular-nums; font-weight: 700; }
    .cert-days.is-bad { color: var(--status-offline); }
    .cert-options { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
    .cert-option { display: flex; gap: 14px; align-items: flex-start; padding: 14px; border-radius: var(--radius-md, 12px); border: 1px solid var(--line); }
    .cert-option.is-current { border-color: var(--status-online, #16a34a); box-shadow: inset 3px 0 0 var(--status-online, #16a34a); }
    .cert-option .cert-icon { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: var(--info-bg); color: var(--info-ink); flex: none; }
    .cert-option .cert-icon svg { width: 18px; height: 18px; }
    .cert-option > div { flex: 1; min-width: 0; }
    .cert-option strong { display: block; }
    .cert-option p { margin: 2px 0 0; font-size: 13px; color: var(--ink-muted); }
    .cert-option form, .cert-option .cert-act { flex: none; align-self: center; }
    .cert-tabs { display: inline-flex; margin-bottom: 14px; }
    .cert-pem { font-family: var(--font-mono, ui-monospace, monospace); font-size: 12px; min-height: 120px; }
    .cert-or { font-size: 12px; color: var(--ink-muted); }
    .cert-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
</style>
@endonce
