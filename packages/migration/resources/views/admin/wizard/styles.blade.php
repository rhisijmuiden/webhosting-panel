<style>
    .wz { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 24px; align-items: start; }
    .wz-nav { position: sticky; top: 16px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 10px; }
    .wz-nav ol { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
    .wz-nav a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: var(--radius-md); color: inherit; text-decoration: none; position: relative; }
    .wz-nav a:hover { background: var(--surface-sunken); }
    .wz-nav li.is-current a { background: var(--surface-sunken); }
    .wz-nav li.is-current a::before { content: ""; position: absolute; left: 0; top: 10px; bottom: 10px; width: 3px; border-radius: 3px; background: var(--accent); }
    .wz-nav li:not(:last-child) a::after { content: ""; position: absolute; left: 25px; top: 40px; height: 14px; width: 2px; background: var(--line); }
    .wz-num { flex: none; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 13px; background: var(--surface-sunken); color: var(--ink-muted); box-shadow: inset 0 0 0 1px var(--line); }
    .is-current .wz-num, .is-todo.is-current .wz-num { background: var(--accent); color: #fff; box-shadow: none; }
    .is-ok .wz-num, .is-skip .wz-num { background: var(--ok-bg); color: var(--ok-ink); box-shadow: none; }
    .is-warn .wz-num { background: var(--warn-bg, #FFF4DB); color: var(--warn-ink, #8A5A00); box-shadow: none; }
    .is-bad .wz-num, .is-blocked .wz-num { background: var(--bad-bg, #FDE8E8); color: var(--bad-ink, #A32020); box-shadow: none; }
    .wz-label { display: flex; flex-direction: column; min-width: 0; }
    .wz-label strong { font-size: 14px; }
    .wz-label small { font-size: 12px; color: var(--ink-muted); }
    .wz-main { display: grid; gap: 24px; min-width: 0; }
    .wz-head { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
    .wz-head h2 { margin: 0; font-size: 20px; }
    .wz-head .eyebrow { margin: 0; }
    .wz-icon { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: var(--accent); color: #fff; }
    .wz-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
    .wz-choice { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
    .wz-choice label { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border: 1px solid var(--line); border-radius: var(--radius-md); cursor: pointer; }
    .wz-choice label.is-on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
    .wz-choice input { margin-top: 3px; accent-color: var(--accent); }
    .wz-choice span { display: flex; flex-direction: column; gap: 4px; }
    .wz-choice small { color: var(--ink-muted); font-size: 13px; }
    .wz-dns td { vertical-align: top; }
    .wz-dns .wz-tick { width: 34px; }
    .wz-dns tr.is-off td { opacity: .55; }
    .wz-val { word-break: break-all; max-width: 340px; }
    .wz-hint { font-family: var(--font, system-ui, sans-serif); font-size: 12px; color: var(--status-warning); margin-top: 4px; word-break: normal; }
    .wz-cf { display: inline-block; font-size: 10px; font-weight: 700; padding: 0 5px; border-radius: 4px; background: #F48120; color: #fff; vertical-align: middle; }
    .wz-summary { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
    .wz-summary a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--radius-md); color: inherit; text-decoration: none; flex-wrap: wrap; }
    .wz-summary small { width: 100%; padding-left: 38px; margin-top: -6px; color: var(--ink-muted); font-size: 12px; }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    @media (max-width: 900px) {
        .wz { grid-template-columns: 1fr; }
        .wz-nav { position: static; overflow-x: auto; }
        .wz-nav ol { grid-auto-flow: column; grid-auto-columns: max-content; }
        .wz-nav li a::after { display: none; }
        .wz-label small { display: none; }
    }
</style>
