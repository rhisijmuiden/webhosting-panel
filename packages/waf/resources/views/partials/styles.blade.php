<style>
.waf-tabs { display: flex; gap: 4px; flex-wrap: wrap; background: var(--surface-sunken); border: 1px solid var(--line); border-radius: var(--radius-md); padding: 4px; width: fit-content; max-width: 100%; margin-bottom: 16px; }
.waf-tabs a { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border-radius: 8px; font-size: 14px; font-weight: 600; color: var(--ink-muted); text-decoration: none; }
.waf-tabs a[aria-current="page"] { background: var(--nav-active-bg); color: var(--nav-active-ink); }
.waf-tabs a:hover:not([aria-current]) { color: var(--ink); }
.waf-mode { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; }
.waf-mode label { position: relative; display: flex; flex-direction: column; gap: 4px; padding: 14px 16px; border: 1px solid var(--line); border-radius: var(--radius-md); cursor: pointer; background: var(--surface); }
.waf-mode input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.waf-mode strong { display: flex; align-items: center; gap: 8px; font-size: 15px; }
.waf-mode small { color: var(--ink-muted); font-size: 13px; line-height: 1.4; }
.waf-mode label:has(input:checked) { border-color: var(--wc, var(--accent)); box-shadow: 0 0 0 1px var(--wc, var(--accent)); background: color-mix(in srgb, var(--wc, var(--accent)) 7%, var(--surface)); }
.waf-mode label:has(input:focus-visible) { outline: 2px solid var(--lucht, var(--accent)); outline-offset: 2px; }
.waf-mode .m-block { --wc: var(--status-online); } .waf-mode .m-detect { --wc: var(--status-warning); } .waf-mode .m-off { --wc: var(--status-offline); } .waf-mode .m-default { --wc: var(--accent); }
.waf-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--wc, var(--ink-muted)); flex: none; }
.waf-kind { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap;
  background: color-mix(in srgb, var(--kc, #5B8DEF) 14%, transparent); color: color-mix(in srgb, var(--kc, #5B8DEF) 75%, var(--ink)); }
.waf-kind.k-sqli { --kc: #C23434; } .waf-kind.k-xss { --kc: #E0661F; } .waf-kind.k-rce, .waf-kind.k-injection-php, .waf-kind.k-injection-java { --kc: #7A2E8E; }
.waf-kind.k-lfi, .waf-kind.k-rfi { --kc: #D9A21B; } .waf-kind.k-protocol, .waf-kind.k-disclosure { --kc: #5B8DEF; } .waf-kind.k-reputation-scanner { --kc: #3E8E7E; }
.waf-uri { font-family: var(--mono, ui-monospace, monospace); font-size: 12.5px; word-break: break-all; max-width: 420px; display: inline-block; }
.waf-rules { list-style: none; margin: 0; padding: 0; }
.waf-rules li { display: grid; grid-template-columns: auto 1fr; gap: 2px 14px; padding: 14px 20px; border-top: 1px solid var(--line-soft, var(--line)); }
.waf-rules li:first-child { border-top: 0; }
.waf-rules code { font-weight: 700; }
.waf-rules .data { grid-column: 2; font-family: var(--mono, ui-monospace, monospace); font-size: 12px; color: var(--ink-muted); word-break: break-all; }
.waf-top { list-style: none; margin: 0; padding: 0; }
.waf-top li { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line-soft, var(--line)); align-items: baseline; }
.waf-top li:first-child { border-top: 0; }
.waf-top .t { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.waf-split { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.waf-split div { padding: 12px 14px; border-radius: var(--radius-md); background: var(--surface-sunken); border-top: 3px solid var(--wc); display: flex; flex-direction: column; }
.waf-split strong { font-size: 24px; font-weight: 800; font-stretch: 125%; line-height: 1.15; } .waf-split span { font-size: 12px; color: var(--ink-muted); }
.waf-split .m-block { --wc: var(--status-online); } .waf-split .m-detect { --wc: var(--status-warning); } .waf-split .m-off { --wc: var(--status-offline); }
.waf-fieldset { border: 0; padding: 0; margin: 0; display: grid; gap: 8px; } .waf-fieldset legend { font-weight: 700; font-size: 14px; margin-bottom: 8px; padding: 0; }
.waf-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; padding: 0 20px 16px; }
.waf-filters .field { margin: 0; min-width: 150px; }
@media (max-width: 640px) { .waf-split { grid-template-columns: 1fr; } .waf-uri { max-width: 220px; } .waf-filters { padding: 0 16px 14px; } }
</style>
