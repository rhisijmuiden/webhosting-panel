@once
<style>
    .clone-layout { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 20px; align-items: start; }
    .clone-form { display: flex; flex-direction: column; gap: 20px; }
    .clone-side { display: flex; flex-direction: column; gap: 20px; }
    .clone-targets { display: grid; gap: 10px; }
    .clone-target { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border: 1px solid var(--line); border-radius: var(--radius-md); cursor: pointer; background: var(--surface-raised); }
    .clone-target:hover { border-color: color-mix(in srgb, var(--accent) 45%, var(--line)); }
    .clone-target.is-on { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 15%, transparent); }
    .clone-target input[type=radio] { margin-top: 3px; accent-color: var(--accent); }
    .clone-target-body { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
    .clone-target .sd-input { margin-top: 8px; }
    .clone-replace { display: flex; flex-direction: column; gap: 10px; }
    .clone-steps { margin: 0; padding-left: 18px; display: grid; gap: 6px; }
    .clone-progress { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    .clone-progress li { display: flex; align-items: center; gap: 10px; color: var(--ink-muted); }
    .clone-progress li::before { content: ''; width: 12px; height: 12px; border-radius: 50%; border: 2px solid var(--line); flex: none; }
    .clone-progress li.is-done { color: var(--ink); }
    .clone-progress li.is-done::before { background: var(--status-online, #1C9D63); border-color: var(--status-online, #1C9D63); }
    .clone-progress li.is-now { color: var(--ink); font-weight: 600; }
    .clone-progress li.is-now::before { border-color: var(--accent); animation: clone-pulse 1.2s ease-in-out infinite; }
    @keyframes clone-pulse { 50% { box-shadow: 0 0 0 5px color-mix(in srgb, var(--accent) 25%, transparent); } }
    .sd-input { display: flex; align-items: stretch; }
    .sd-input .input { border-top-right-radius: 0; border-bottom-right-radius: 0; min-width: 0; flex: 1 1 auto; }
    .sd-suffix { display: inline-flex; align-items: center; padding: 0 12px; border: 1px solid var(--line); border-left: 0; border-radius: 0 var(--radius-md) var(--radius-md) 0;
        background: var(--surface-sunken); color: var(--ink-muted); font-size: .9rem; white-space: nowrap; max-width: 70%; overflow: hidden; text-overflow: ellipsis; }
    .sd-add { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: end; margin-top: 12px; }
    .sd-add > .field:first-of-type { grid-column: 1 / -1; }
    .sd-add .field { margin: 0; }
    .sd-list { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--line-soft); }
    .sd-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; padding: 12px 0; border-bottom: 1px solid var(--line-soft); }
    .sd-name { flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
    .sd-status { display: flex; flex-wrap: wrap; gap: 6px; }
    .sd-actions { margin-left: auto; }
    .sd-remove { position: relative; }
    .sd-remove summary { list-style: none; }
    .sd-remove summary::-webkit-details-marker { display: none; }
    .sd-remove-form { position: absolute; right: 0; z-index: 5; width: min(320px, 80vw); margin-top: 6px; padding: 14px; text-align: left;
        background: var(--surface-raised); border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 12px 32px rgba(0,0,0,.18); }
    @media (max-width: 520px) { .sd-add { grid-template-columns: 1fr; } }
    @media (max-width: 900px) { .clone-layout { grid-template-columns: 1fr; } }
    @media (prefers-reduced-motion: reduce) { .clone-progress li.is-now::before { animation: none; } }
</style>
@endonce
