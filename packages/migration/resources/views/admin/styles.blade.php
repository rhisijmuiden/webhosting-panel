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
</style>
