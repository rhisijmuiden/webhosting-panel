{{-- Step 1: upload the website zip and the database export (in chunks, resumable). --}}
@php
    $gb = config('wp-import.max_upload_gb', 20);
    $cfg = [
        'csrf' => csrf_token(), 'max' => $maxBytes,
        'files' => collect(\App\Models\WpImport::KINDS)->mapWithKeys(fn ($k) => [$k => [
            'start' => route($area.'.wp-import.upload', [$import, $k]), 'chunk' => route($area.'.wp-import.chunk', [$import, $k]),
            'name' => $import->file($k)['name'] ?? null, 'size' => $import->file($k)['size'] ?? 0, 'complete' => $import->uploaded($k),
            'accept' => $k === 'site' ? '\.zip$' : '\.(zip|sql|sql\.gz|gz)$',
        ]])->all(),
        'text' => [
            'tooBig' => __('This file is larger than :n GB.', ['n' => $gb]),
            'siteType' => __('Choose a .zip file.'), 'databaseType' => __('Choose a .zip, .sql or .sql.gz file.'),
            'failed' => __('The upload failed. Try again.'),
        ],
    ];
@endphp
<script src="{{ asset('assets/wp-import.js') }}?v={{ @filemtime(public_path('assets/wp-import.js')) }}"></script>

<div class="wpi-layout">
    <div class="wpi-main">
        @if ($import->error)
            <div class="alert alert-bad" role="alert"><x-icon name="info" />
                <div><strong>{{ __('The files could not be checked.') }}</strong><p class="small">{{ __($import->error) }}</p>
                    <p class="small">{{ __('Make the files again as described on the right and upload them once more.') }}</p></div></div>
        @endif
        <x-ui.card :title="__('Upload the two files')">
            <div x-data="wpImportUpload(@js($cfg))" class="wpi-uploads">
                <p class="muted small">{{ __('Nothing changes on :domain yet: the files are checked first and you confirm every change.', ['domain' => $import->domain]) }}</p>
                @foreach (['site' => [__('Website (zip)'), __('The whole WordPress folder: wp-admin, wp-content, wp-includes and wp-config.php'), 'folder'],
                           'database' => [__('Database export (zip)'), __('The .sql export from phpMyAdmin, zipped'), 'database']] as $kind => [$title, $hint, $icon])
                    <div class="wpi-drop" :class="{ 'is-over': f.{{ $kind }}.over, 'is-done': f.{{ $kind }}.complete, 'is-busy': f.{{ $kind }}.busy }"
                         @dragover.prevent="f.{{ $kind }}.over = true" @dragleave="f.{{ $kind }}.over = false"
                         @drop.prevent="f.{{ $kind }}.over = false; choose('{{ $kind }}', $event.dataTransfer.files[0])">
                        <span class="wpi-drop-icon" aria-hidden="true">@include('client.websites.overview.icon', ['name' => $icon])</span>
                        <div class="wpi-drop-body">
                            <strong>{{ $title }}</strong>
                            <small class="muted" x-show="!f.{{ $kind }}.name">{{ $hint }}</small>
                            <small x-show="f.{{ $kind }}.name" x-cloak><span class="mono" x-text="f.{{ $kind }}.name"></span> · <span x-text="size(f.{{ $kind }}.size)"></span></small>
                            <div class="wpi-bar" x-show="f.{{ $kind }}.busy" x-cloak role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent('{{ $kind }}')">
                                <span :style="`width: ${percent('{{ $kind }}')}%`"></span></div>
                            <small class="muted" x-show="f.{{ $kind }}.busy" x-cloak><span x-text="percent('{{ $kind }}')"></span>% · {{ __('Keep this page open while uploading.') }}</small>
                            <small class="is-bad" x-show="f.{{ $kind }}.error" x-cloak x-text="f.{{ $kind }}.error"></small>
                        </div>
                        <span class="wpi-drop-state" x-show="f.{{ $kind }}.complete" x-cloak><x-icon name="check" />{{ __('Uploaded') }}</span>
                        <label class="btn btn-sm" :class="{ 'is-disabled': f.{{ $kind }}.busy }">
                            <input type="file" class="sr-only" accept="{{ $kind === 'site' ? '.zip,application/zip' : '.zip,.sql,.gz,application/zip,application/sql,application/gzip' }}"
                                   @change="choose('{{ $kind }}', $event.target.files[0]); $event.target.value = ''" :disabled="f.{{ $kind }}.busy">
                            <x-icon name="plus" /><span x-text="f.{{ $kind }}.complete ? @js(__('Other file')) : @js(__('Choose file'))"></span></label>
                    </div>
                @endforeach
                <form method="POST" action="{{ route($area.'.wp-import.check', $import) }}" class="wpi-next">
                    @csrf
                    <p class="small muted">{{ __('Up to :n GB per file. Drop a file on a box or choose it.', ['n' => $gb]) }}</p>
                    <button class="btn btn-primary" type="submit" :disabled="!ready" @if (! $import->bothUploaded()) disabled @endif><x-icon name="check" />{{ __('Check the files') }}</button>
                </form>
            </div>
        </x-ui.card>
    </div>
    <aside class="wpi-side">@include('wp-import.partials.help')</aside>
</div>
