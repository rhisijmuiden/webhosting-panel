<style>
    .mig-steps { counter-reset: step; list-style: none; margin: 0; padding: 0; display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
    .mig-steps li { counter-increment: step; display: flex; flex-direction: column; gap: 4px; padding-left: 40px; position: relative; }
    .mig-steps li::before { content: counter(step); position: absolute; left: 0; top: 0; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 13px; background: var(--accent); color: #fff; }
    .mig-steps li span { font-size: 13px; color: var(--ink-muted); }
    .mig-steps li.done::before { content: "✓"; background: var(--ok-bg); color: var(--ok-ink); }
    .mig-steps li.todo::before { background: var(--surface-sunken); color: var(--ink-muted); }
    .mig-what { display: flex; flex-wrap: wrap; gap: 6px 18px; }
    .mig-bar { position: sticky; bottom: 0; z-index: 2; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; justify-content: space-between; padding: 14px 18px; border-top: 1px solid var(--line); background: var(--surface-raised, var(--surface)); border-radius: 0 0 var(--radius-lg) var(--radius-lg); }
    .mig-tags { display: flex; flex-wrap: wrap; gap: 4px; }
    .mig-tags span { font-size: 12px; padding: 1px 8px; border-radius: var(--radius-pill); background: var(--surface-sunken); color: var(--ink-muted); white-space: nowrap; }
    .mig-sub td { padding-left: 38px; }
    .mig-group td { background: var(--surface-sunken); font-weight: 600; font-size: 13px; }
    .mig-sel { width: 18px; height: 18px; accent-color: var(--accent); }
    .table .select.mig-cust { min-width: 190px; padding-top: 4px; padding-bottom: 4px; }
    .mig-stack { display: grid; gap: 24px; align-content: start; }
    .mig-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin: -4px 0 4px; }
    .mig-tabs a { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; font-weight: 600; font-size: 14px; color: var(--ink-muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .mig-tabs a:hover { color: var(--ink); }
    .mig-tabs a.is-active { color: var(--ink); border-bottom-color: var(--accent); }
    .mig-count { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 99px; background: var(--accent); color: #fff; font-size: 12px; display: inline-grid; place-items: center; }
    .mig-kpis { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .mig-kpis .mig-bad { color: var(--status-offline); }
    @media (max-width: 760px) { .mig-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .mig-kpis > div { border-left: 0; padding-left: 0; } }
    .mig-status { align-items: start; }
    .mig-list { list-style: none; margin: 0; padding: 0; }
    .mig-list li + li { border-top: 1px solid var(--line-soft, var(--line)); }
    .mig-row { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.5fr) auto; gap: 16px; align-items: center; padding: 14px 18px; color: inherit; text-decoration: none; border-left: 3px solid transparent; }
    .mig-row:hover { background: var(--surface-sunken); }
    .mig-list li.is-selected .mig-row { background: var(--surface-sunken); border-left-color: var(--accent); }
    .mig-name, .mig-prog, .mig-state { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
    .mig-name strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mig-state { align-items: flex-end; text-align: right; }
    .mig-prog small { color: var(--ink-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mig-stages { display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px; }
    .mig-stages i { height: 4px; border-radius: 99px; background: var(--surface-sunken); box-shadow: inset 0 0 0 1px var(--line); }
    .mig-stages i.done { background: var(--status-online); box-shadow: none; }
    .mig-stages i.now { background: var(--accent); box-shadow: none; }
    .mig-stages i.now.bad { background: var(--status-offline); }
    .mig-bar-line { height: 8px; border-radius: 99px; background: var(--surface-sunken); overflow: hidden; }
    .mig-bar-line > span { display: block; height: 100%; border-radius: 99px; background: var(--accent); transition: width .6s ease; }
    .mig-bar-line > span.ok { background: var(--status-online); }
    .mig-bar-line > span.bad { background: var(--status-offline); }
    @media (max-width: 760px) { .mig-row { grid-template-columns: 1fr; } .mig-state { flex-direction: row; align-items: center; justify-content: space-between; } }
    .mig-logcard { display: flex; flex-direction: column; gap: 12px; position: sticky; top: 16px; }
    .mig-loghead { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
    .mig-loghead h2 { margin: 2px 0 0; font-size: 18px; word-break: break-all; }
    .mig-log { background: var(--nacht); color: #DCE4F5; border-radius: var(--radius-md); padding: 12px 14px; font-family: ui-monospace, Menlo, monospace; font-size: 12px; line-height: 1.55; height: 460px; max-height: 60vh; overflow: auto; }
    .mig-line { display: grid; grid-template-columns: 64px minmax(0, 1fr); gap: 12px; }
    .mig-line time { color: #7F8BA8; white-space: nowrap; }
    .mig-line span { white-space: pre-wrap; word-break: break-word; }
    .mig-line.is-agent span { color: #A9B4CC; }
    .mig-line.is-ok span { color: #7EE2A8; }
    .mig-line.is-warn span { color: #F5C66B; }
    .mig-line.is-error span { color: #FF8F8F; }
    .mig-progress { display: grid; gap: 8px; }
    .mig-progress .mig-bar-line { height: 10px; }
    .mig-filter { display: inline-flex; padding: 3px; gap: 2px; border-radius: var(--radius-pill); background: var(--surface-sunken); }
    .mig-filter a { padding: 6px 12px; border-radius: var(--radius-pill); font-size: 13px; font-weight: 600; color: var(--ink-muted); text-decoration: none; white-space: nowrap; }
    .mig-filter a.is-active { background: var(--surface); color: var(--ink); box-shadow: 0 1px 2px rgba(0,0,0,.08); }
</style>
