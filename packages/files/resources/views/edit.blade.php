<x-dynamic-component :component="'layouts.'.$area" :title="basename($path).' · '.$website->domain">
    <link rel="stylesheet" href="{{ asset('assets/files/filemanager.css') }}?v={{ @filemtime(public_path('assets/files/filemanager.css')) }}">
    <script src="{{ asset('assets/editor/codemirror.js') }}?v={{ @filemtime(public_path('assets/editor/codemirror.js')) }}"></script>
    <script src="{{ asset('assets/files/filemanager.js') }}?v={{ @filemtime(public_path('assets/files/filemanager.js')) }}"></script>
    @php $folder = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/')) : ''; @endphp

    <div x-data="codeEditor(@js($config + ['path' => $path, 'i18n' => []]))" style="display:flex;flex-direction:column;gap:12px">
        <div class="crumbs"><a href="{{ route($area.'.files.index') }}">{{ __('File manager') }}</a> /
            <a href="{{ route($area.'.files.browse', [$website, 'path' => $folder]) }}">{{ $website->domain }}{{ $folder ? '/'.$folder : '' }}</a></div>

        <template x-if="conflict">
            <div class="alert alert-warn" role="alert">
                <div style="flex:1"><strong>{{ __('This file was changed elsewhere since you opened it.') }}</strong>
                    <p class="small">{{ __('Reload to see the new version (your changes are lost), or overwrite it with your version.') }}</p></div>
                <button type="button" class="btn btn-sm" @click="reload()">{{ __('Reload') }}</button>
                <button type="button" class="btn btn-sm btn-dark" @click="save(true)">{{ __('Overwrite') }}</button>
            </div>
        </template>
        <template x-if="error"><div class="alert alert-bad" role="alert"><span x-text="error"></span></div></template>

        <div class="ed-shell">
            <div class="ed-bar">
                <div class="file">
                    @include('files::partials.icon', ['name' => 'code'])
                    <span class="mono">{{ $path }}</span>
                    <span class="ed-dot" x-show="dirty" title="{{ __('Unsaved changes') }}"></span>
                    <span class="fm-tag">{{ $language }}</span>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-sm btn-ghost" @click="editor && editor.search()">{{ __('Find & replace') }}</button>
                    <button type="button" class="btn btn-sm btn-ghost" @click="toggleWrap()" :aria-pressed="wrap">{{ __('Word wrap') }}</button>
                    <a class="btn btn-sm" href="{{ route($area.'.files.download', [$website, 'path' => $path]) }}">{{ __('Download') }}</a>
                    <a class="btn btn-sm" href="{{ route($area.'.files.browse', [$website, 'path' => $folder]) }}">{{ __('Close') }}</a>
                    <button type="button" class="btn btn-sm btn-primary" @click="save()" :disabled="saving || loading || !editor"
                            x-text="saving ? @js(__('Saving…')) : @js(__('Save'))"></button>
                </div>
            </div>
            <div class="ed-area" x-ref="editor"><p class="muted small" style="padding:16px" x-show="loading">{{ __('Loading…') }}</p></div>
            <div class="ed-status">
                <span x-text="@js(__('Line')) + ' ' + line + ', ' + @js(__('column')) + ' ' + col"></span>
                <span>
                    <span x-show="savedAt && !dirty" x-text="@js(__('Saved at')) + ' ' + savedAt"></span>
                    <span x-show="dirty">{{ __('Unsaved changes') }}</span>
                    · <kbd>Ctrl</kbd>+<kbd>S</kbd> {{ __('to save') }} · UTF-8
                </span>
            </div>
        </div>
    </div>
</x-dynamic-component>
