<style>
.vx-hero { display: grid; grid-template-columns: minmax(240px, 1fr) minmax(300px, 1.4fr) minmax(240px, 1fr); gap: 16px; }
@media (max-width: 1100px) { .vx-hero { grid-template-columns: 1fr; } }
.vx-score { display: flex; align-items: center; gap: 20px; }
.vx-ring { --p: 0; --c: var(--status-online); width: 112px; height: 112px; flex: none; border-radius: 50%; display: grid; place-items: center;
  background: conic-gradient(var(--c) calc(var(--p) * 1%), var(--surface-sunken) 0); }
.vx-ring::before { content: ""; grid-area: 1 / 1; width: 88px; height: 88px; border-radius: 50%; background: var(--surface-raised); }
.vx-ring strong { grid-area: 1 / 1; z-index: 1; font-size: 30px; font-weight: 800; font-stretch: 125%; }
.vx-ring.warn { --c: var(--status-warning); } .vx-ring.bad { --c: var(--status-offline); }
.vx-sev { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-top: 14px; }
.vx-sev a { display: flex; flex-direction: column; gap: 2px; padding: 10px 12px; border-radius: var(--radius-md); background: var(--surface-sunken); color: inherit; text-decoration: none; border-top: 3px solid var(--sc, var(--line)); }
.vx-sev a:hover { outline: 2px solid var(--line); }
.vx-sev strong { font-size: 24px; font-weight: 800; font-stretch: 125%; line-height: 1.1; }
.vx-sev span { font-size: 12px; color: var(--ink-muted); }
.vx-stack { display: flex; height: 10px; border-radius: 999px; overflow: hidden; background: var(--surface-sunken); }
.vx-stack span { display: block; height: 100%; }
.sev-critical { --sc: #C23434; } .sev-high { --sc: #E0661F; } .sev-medium { --sc: #D9A21B; } .sev-low, .sev-unknown { --sc: #5B8DEF; } .sev-malware { --sc: #7A2E8E; }
.vx-chip { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap;
  background: color-mix(in srgb, var(--sc) 14%, transparent); color: color-mix(in srgb, var(--sc) 80%, var(--ink)); }
.vx-chip::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--sc); }
.vx-kpis { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
.vx-kpis div { display: flex; flex-direction: column; } .vx-kpis strong { font-size: 24px; font-weight: 800; font-stretch: 125%; } .vx-kpis span { font-size: 12px; color: var(--ink-muted); }
.vx-risk { list-style: none; margin: 0; padding: 0; }
.vx-risk li { display: grid; grid-template-columns: 28px 1fr auto; gap: 4px 12px; align-items: center; padding: 14px 20px; border-top: 1px solid var(--line-soft); }
.vx-risk li:first-child { border-top: 0; }
.vx-rank { width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; font-weight: 800; font-size: 13px; background: var(--surface-sunken); }
.vx-risk .name { font-weight: 700; } .vx-risk .meta { grid-column: 2 / 4; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 12px; color: var(--ink-muted); }
.vx-bar { grid-column: 2 / 4; height: 6px; border-radius: 999px; background: var(--surface-sunken); overflow: hidden; }
.vx-bar span { display: block; height: 100%; background: var(--sc); border-radius: 999px; }
.vx-checks { list-style: none; margin: 0; padding: 0; }
.vx-checks li { display: flex; gap: 12px; align-items: flex-start; padding: 12px 20px; border-top: 1px solid var(--line-soft); }
.vx-checks li:first-child { border-top: 0; }
.vx-dot { width: 22px; height: 22px; flex: none; border-radius: 50%; display: grid; place-items: center; font-size: 12px; font-weight: 800; margin-top: 1px; }
.vx-dot.ok { background: var(--ok-bg); color: var(--ok-ink); } .vx-dot.warn { background: var(--warn-bg); color: var(--warn-ink); } .vx-dot.bad { background: var(--bad-bg); color: var(--bad-ink); }
.vx-checks strong { display: block; font-size: 14px; } .vx-checks span { font-size: 13px; color: var(--ink-muted); }
.vx-finding { border: 1px solid var(--line); border-left: 4px solid var(--sc); border-radius: var(--radius-md); padding: 16px 18px; display: grid; gap: 8px; background: var(--surface-raised); }
.vx-finding h3 { margin: 0; font-size: 16px; } .vx-finding p { margin: 0; }
.vx-fix { background: var(--surface-sunken); border-radius: 8px; padding: 10px 14px; font-size: 14px; }
.vx-fix b { display: block; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--status-online); margin-bottom: 2px; }
.vx-list { display: grid; gap: 12px; }
.vx-site { margin: 6px 0 2px; font-size: 13px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--ink-muted); }
.vx-allclear { display: flex; gap: 18px; align-items: center; }
.vx-allclear .vx-dot { width: 48px; height: 48px; font-size: 22px; }
</style>
