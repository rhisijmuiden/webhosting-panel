{{-- The import failed: everything was put back. --}}
<div class="alert alert-bad" role="alert"><x-icon name="info" />
    <div><strong>{{ __('The import failed. :domain was put back as it was.', ['domain' => $import->domain]) }}</strong>
        @if ($import->error)<p class="small mono">{{ \Illuminate\Support\Str::limit(__($import->error), 400) }}</p>@endif</div>
</div>
<x-ui.card :title="__('Try again')">
    <p class="small">{{ __('Check the files again and import once more, or upload new files. A common cause is a database export that is not complete: export it again with phpMyAdmin.') }}</p>
    <div class="actions">
        <form method="POST" action="{{ route($area.'.wp-import.check', $import) }}">@csrf
            <button class="btn btn-primary" type="submit"><x-icon name="refresh" />{{ __('Check the files again') }}</button></form>
        <form method="POST" action="{{ route($area.'.wp-import.reset', $import) }}">@csrf
            <button class="btn" type="submit"><x-icon name="plus" />{{ __('Upload other files') }}</button></form>
    </div>
    @if ($area === 'admin' && ! empty($import->log))<div class="log">{{ implode("\n", $import->log) }}</div>@endif
</x-ui.card>
