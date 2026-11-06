{{-- Styles for the Git screens only (kept in the package, so panel CSS stays untouched). --}}
<style>
.input-group { display: flex; align-items: center; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-raised); padding-left: 12px; }
.input-group:focus-within { border-color: var(--accent); }
.input-group .input { border: 0; background: transparent; padding-left: 2px; }
.copy-row { display: flex; align-items: center; gap: 8px; background: var(--surface-sunken); border-radius: var(--radius-md); padding: 8px 10px; }
.copy-row code { flex: 1; min-width: 0; overflow-wrap: anywhere; font-size: 12px; }
.howto { margin: 0; padding-left: 20px; font-size: 14px; display: grid; gap: 6px; }
.log.log-tall { max-height: 560px; }
</style>
