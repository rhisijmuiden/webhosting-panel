<style>
.wpm-checks{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.wpm-checks li{display:flex;gap:10px;align-items:flex-start}
.wpm-checks .wpm-ico{flex:0 0 22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:700;color:#fff;background:var(--muted,#8a8a8a)}
.wpm-checks .is-ok .wpm-ico{background:var(--ok,#3c9a6b)}
.wpm-checks .is-warn .wpm-ico{background:var(--warn,#d49a2a)}
.wpm-checks .is-bad .wpm-ico{background:var(--bad,#c4524a)}
.wpm-from{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1.3fr);gap:6px;align-items:end}
.wpm-from .wpm-at{padding-bottom:10px;font-weight:600}
.wpm-route{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px;margin:8px 0 0}
.wpm-route span{padding:3px 9px;border-radius:999px;background:var(--surface-2,rgba(127,127,127,.12))}
.wpm-route i{font-style:normal;opacity:.6}
@media (max-width:640px){.wpm-from{grid-template-columns:1fr}.wpm-from .wpm-at{display:none}}
</style>
