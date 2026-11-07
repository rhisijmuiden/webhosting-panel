@once
<style>
    .stg-flow { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 0 0 20px; padding: 0; list-style: none; }
    .stg-flow li { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); }
    .stg-flow .stg-num { flex: none; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 13px;
        background: color-mix(in srgb, var(--accent) 16%, transparent); color: var(--accent); }
    .stg-flow strong { display: block; }
    .stg-route { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .stg-route .stg-arrow { color: var(--accent); font-weight: 700; }
    .stg-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: var(--radius-pill); font-size: 11px; font-weight: 700; letter-spacing: .04em;
        text-transform: uppercase; background: color-mix(in srgb, var(--accent) 18%, transparent); color: var(--accent); }
    .stg-layout { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 20px; align-items: start; }
    .stg-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
    .stg-what { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .stg-what .check { padding: 12px 14px; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); }
    .stg-tables { max-height: 320px; overflow: auto; border: 1px solid var(--line-soft); border-radius: var(--radius-md); padding: 6px 12px; margin-top: 8px; }
    .stg-tables .check { padding: 6px 0; border-bottom: 1px solid var(--line-soft); }
    .stg-tables .check:last-child { border-bottom: 0; }
    .stg-tables .mono { overflow-wrap: anywhere; }
    .stg-tables summary, .stg-danger summary { cursor: pointer; font-weight: 600; }
    .stg-list { list-style: none; margin: 0; padding: 0; }
    .stg-list li { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--line-soft); }
    .stg-list li:last-child { border-bottom: 0; }
    .stg-list .stg-grow { flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
    .stg-form { display: flex; flex-direction: column; gap: 14px; }
    .stg-form .field { margin: 0; }
    .stg-hint { margin: 0; padding-left: 18px; display: grid; gap: 4px; }
    .stg-cta { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
    .stg-cta p { flex: 1 1 260px; margin: 0; }
    @media (max-width: 900px) { .stg-layout, .stg-flow { grid-template-columns: 1fr; } }
    @media (max-width: 520px) { .stg-what { grid-template-columns: 1fr; } }
</style>
@endonce
@include('subdomains::partials.styles')
