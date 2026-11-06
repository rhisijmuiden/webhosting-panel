@once
<style>
    .seg.seg-sm { padding: 2px; flex-wrap: nowrap; }
    td .actions { flex-wrap: nowrap; }
    .seg.seg-sm span { min-height: 28px; padding: 0 10px; font-size: 13px; }
    .alias-details > summary { list-style: none; display: inline-flex; margin-top: 8px; }
    .alias-details > summary::-webkit-details-marker { display: none; }
    .alias-details[open] > summary { margin-bottom: 12px; }
    .alias-add { max-width: 560px; }
    ul.checklist { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
    ul.checklist li { display: flex; gap: 8px; align-items: flex-start; }
    ul.checklist svg { flex: none; color: var(--ok, #16a34a); width: 18px; height: 18px; margin-top: 2px; }
</style>
@endonce
@error('alias')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
@foreach ((array) session('warnings', []) as $w)
    <div class="alert alert-warn" role="status"><x-icon name="info" />{{ $w }}</div>
@endforeach
