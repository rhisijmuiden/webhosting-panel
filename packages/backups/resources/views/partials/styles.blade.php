{{-- Styles of the backup screens only (kept in the package, not in app.css). --}}
<style>
    .bk-tabs { display: flex; gap: 4px; flex-wrap: wrap; background: var(--surface-sunken); border: 1px solid var(--line); border-radius: var(--radius-md); padding: 4px; margin-bottom: 20px; width: fit-content; max-width: 100%; }
    .bk-tabs a { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border-radius: 8px; font-size: 14px; font-weight: 600; color: var(--ink-muted); text-decoration: none; }
    .bk-tabs a[aria-current="page"] { background: var(--nav-active-bg); color: var(--nav-active-ink); }
    .bk-browser { display: grid; grid-template-columns: minmax(200px, 260px) 1fr; gap: 20px; align-items: start; }
    @media (max-width: 860px) { .bk-browser { grid-template-columns: 1fr; } }
    .bk-days { list-style: none; margin: 0; padding: 6px; display: flex; flex-direction: column; gap: 2px; max-height: 70vh; overflow-y: auto; }
    .bk-days a { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 8px; color: var(--ink); text-decoration: none; font-size: 14px; }
    .bk-days a:hover { background: var(--surface-sunken); }
    .bk-days a[aria-current="date"] { background: var(--nav-active-bg); color: var(--nav-active-ink); }
    .bk-days .bk-count { font-size: 12px; opacity: .75; white-space: nowrap; }
    .bk-group { border: 1px solid var(--line); border-radius: var(--radius-md); margin-bottom: 12px; overflow: hidden; }
    .bk-group > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; background: var(--surface-sunken); }
    .bk-group > header label { display: flex; align-items: center; gap: 10px; font-weight: 700; }
    .bk-items { list-style: none; margin: 0; padding: 4px 0; }
    .bk-items li label { display: grid; grid-template-columns: 20px 1fr auto; gap: 10px; align-items: center; padding: 8px 16px; cursor: pointer; }
    .bk-items li:hover { background: var(--surface-sunken); }
    .bk-sticky { position: sticky; bottom: 0; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; padding: 14px 16px; background: var(--surface-raised); border-top: 1px solid var(--line); border-radius: 0 0 var(--radius-md) var(--radius-md); }
    .bk-drop { display: block; margin-top: 12px; border: 2px dashed var(--line); border-radius: var(--radius-md); padding: 28px 16px; text-align: center; cursor: pointer; }
    .bk-drop.is-over { border-color: var(--lucht, #4aa3df); background: var(--surface-sunken); }
    .bk-progress { height: 8px; border-radius: 999px; background: var(--surface-sunken); overflow: hidden; margin: 12px 0; }
    .bk-progress > span { display: block; height: 100%; background: var(--nav-active-bg); transition: width .2s; }
    .bk-parts { display: flex; flex-wrap: wrap; gap: 16px; border: 0; padding: 0; margin: 0; }
    .bk-parts legend { width: 100%; padding: 0; margin-bottom: 4px; }
</style>
