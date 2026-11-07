{{-- Styles for the smart updates screens only (kept in the package, so panel CSS stays untouched). --}}
<style>
.su-page { display: grid; gap: 20px; }
.su-page > .page-head { margin-bottom: 0; }
.su-steps { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0; padding: 0; overflow: hidden; }
.su-step { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px; position: relative; }
.su-step + .su-step { border-left: 1px solid var(--line-soft); }
.su-step .n { display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; flex: none;
    background: color-mix(in srgb, var(--accent) 16%, transparent); color: var(--accent); }
.su-step .n svg { width: 18px; height: 18px; }
.su-step strong { display: block; font-size: 14px; }
.su-step span { display: block; font-size: 12.5px; color: var(--ink-muted); line-height: 1.45; margin-top: 2px; }
.su-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.su-mode { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
.su-mode .ic { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 9px; flex: none; }
.su-mode .ic svg { width: 15px; height: 15px; }
.su-mode-all .ic { background: color-mix(in srgb, var(--status-online) 16%, transparent); color: var(--status-online); }
.su-mode-security .ic { background: color-mix(in srgb, var(--accent) 18%, transparent); color: var(--accent); }
.su-mode-notify .ic { background: color-mix(in srgb, var(--status-warning) 18%, transparent); color: var(--status-warning); }
.su-mode-off .ic { background: var(--surface-sunken); color: var(--ink-muted); }
.su-mode small { display: block; font-weight: 400; font-size: 12px; color: var(--ink-muted); }
.su-modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
.su-modes label { position: relative; display: block; cursor: pointer; }
.su-modes input { position: absolute; opacity: 0; inset: 0; }
.su-modes .box { display: grid; gap: 8px; height: 100%; padding: 14px; border: 1.5px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); transition: border-color .15s, box-shadow .15s; }
.su-modes .box p { margin: 0; font-size: 12.5px; color: var(--ink-muted); line-height: 1.45; }
.su-modes input:checked + .box { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent); }
.su-modes input:focus-visible + .box { outline: 2px solid var(--lucht); }
.su-modes .rec { font-size: 11px; font-weight: 700; color: var(--accent); text-transform: uppercase; letter-spacing: .04em; }
.su-score { display: grid; gap: 4px; min-width: 120px; }
.su-score .bar { position: relative; height: 6px; border-radius: 99px; background: var(--surface-sunken); overflow: visible; }
.su-score .bar > span { position: absolute; inset: 0 auto 0 0; border-radius: 99px; background: var(--status-online); }
.su-score .bar > span.over { background: var(--status-offline); }
.su-score .bar > i { position: absolute; top: -3px; bottom: -3px; width: 2px; background: var(--ink); opacity: .55; border-radius: 2px; }
.su-score small { font-size: 12px; color: var(--ink-muted); }
.su-banner { display: flex; gap: 16px; align-items: center; padding: 18px 20px; border-radius: var(--radius-lg, 16px); border: 1px solid var(--line); }
.su-banner .ic { display: inline-grid; place-items: center; width: 48px; height: 48px; border-radius: 14px; flex: none; }
.su-banner .ic svg { width: 24px; height: 24px; }
.su-banner h2 { margin: 0; font-size: 18px; }
.su-banner p { margin: 2px 0 0; color: var(--ink-muted); font-size: 14px; }
.su-banner.ok { background: var(--ok-bg); border-color: transparent; } .su-banner.ok .ic { background: var(--status-online); color: #fff; }
.su-banner.warn { background: var(--warn-bg); border-color: transparent; } .su-banner.warn .ic { background: var(--status-warning); color: #fff; }
.su-banner.bad { background: var(--bad-bg); border-color: transparent; } .su-banner.bad .ic { background: var(--status-offline); color: #fff; }
.su-banner.info { background: var(--info-bg); border-color: transparent; } .su-banner.info .ic { background: var(--accent); color: var(--nacht); }
.su-banner .spin svg { animation: su-spin 1.2s linear infinite; }
@keyframes su-spin { to { transform: rotate(360deg); } }
.su-shots { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
.su-shot .card-head h2 { display: flex; align-items: center; gap: 8px; }
.su-view { position: relative; border-radius: 12px; overflow: hidden; border: 1px solid var(--line); background: var(--surface-sunken); max-height: 640px; overflow-y: auto; }
.su-view img { display: block; width: 100%; height: auto; }
.su-compare { position: relative; }
.su-compare .top { position: absolute; inset: 0; overflow: hidden; }
.su-compare .top img { width: 100%; }
.su-compare .handle { position: absolute; top: 0; bottom: 0; width: 2px; background: var(--accent); box-shadow: 0 0 0 1px rgba(0,0,0,.15); pointer-events: none; }
.su-compare .tag { position: absolute; top: 10px; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; background: rgba(10, 20, 40, .72); color: #fff; }
.su-range { width: 100%; margin: 10px 0 0; accent-color: var(--accent); }
.su-shot .seg span { min-height: 30px; padding: 0 10px; font-size: 13px; }
.su-legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--ink-muted); margin-top: 8px; }
.su-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
.su-changes td:first-child { width: 40px; }
.su-type { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; background: var(--surface-sunken); color: var(--ink-muted); }
.su-type svg { width: 14px; height: 14px; }
.su-arrow { color: var(--ink-muted); padding: 0 4px; }
.su-log { max-height: 380px; overflow: auto; font: 12px/1.5 ui-monospace, Menlo, monospace; background: var(--surface-sunken); border-radius: 10px; padding: 12px; white-space: pre-wrap; margin: 10px 0 0; }
.su-bulk { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 12px 16px; border-top: 1px solid var(--line-soft); }
.su-bulk .select { min-width: 220px; }
.su-two { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(280px, 1fr); gap: 20px; align-items: start; }
.su-side { display: grid; gap: 20px; }
.su-mini { list-style: none; margin: 0; padding: 0; display: grid; gap: 0; }
.su-mini li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 0; border-top: 1px solid var(--line-soft); font-size: 13.5px; }
.su-mini li:first-child { border-top: 0; }
.su-mini a { color: inherit; text-decoration: none; } .su-mini a:hover { color: var(--accent); }
.su-card-site { display: flex; gap: 14px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.su-checks { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 13.5px; }
.su-checks li { display: flex; gap: 8px; align-items: center; }
.su-checks .ok { color: var(--status-online); } .su-checks .bad { color: var(--status-offline); }
.su-checks svg { width: 16px; height: 16px; }
.su-threshold { display: flex; gap: 12px; align-items: center; }
.su-threshold input[type=range] { flex: 1; accent-color: var(--accent); }
.su-threshold output { min-width: 48px; font-weight: 700; text-align: right; }
@media (max-width: 1000px) { .su-two { grid-template-columns: 1fr; } }
@media (max-width: 860px) { .su-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); } .su-step:nth-child(3) { border-left: 0; } .su-step:nth-child(n+3) { border-top: 1px solid var(--line-soft); } }
@media (max-width: 520px) { .su-steps { grid-template-columns: 1fr; } .su-step + .su-step { border-left: 0; border-top: 1px solid var(--line-soft); } .su-shots { grid-template-columns: 1fr; } .su-banner { align-items: flex-start; } }
</style>
