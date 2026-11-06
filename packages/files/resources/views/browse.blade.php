<x-dynamic-component :component="'layouts.'.$area" :title="__('File manager').' · '.$website->domain">
    <link rel="stylesheet" href="{{ asset('assets/files/filemanager.css') }}?v={{ @filemtime(public_path('assets/files/filemanager.css')) }}">
@php
        $fmI18n = [
            'deleted' => __(':count items deleted.'),
            'compressing' => __('Creating archive…'),
            'extracting' => __('Unpacking archive…'),
            'done' => __('Done.'),
            'cut' => __('Cut. Open the destination folder and choose Paste.'),
            'copied' => __('Copied. Open the destination folder and choose Paste.'),
            'too_large' => __('This file is larger than the upload limit.'),
        ];
    @endphp
    <script src="{{ asset('assets/files/filemanager.js') }}?v={{ @filemtime(public_path('assets/files/filemanager.js')) }}"></script>

    <div x-data="fileManager(@js($config + ['path' => $path, 'i18n' => $fmI18n, 'editorMax' => (int) config('files.editor_max_kb') * 1024]))" class="stack" style="display:flex;flex-direction:column;gap:16px">
        <div class="page-head">
            <div>
                <div class="crumbs"><a href="{{ route($area.'.files.index') }}">{{ __('File manager') }}</a> / {{ $website->domain }}</div>
                <h1>{{ $website->domain }}</h1>
                <div class="sub">{{ __('Everything here runs as your website\'s own user and stays inside your webspace.') }}</div>
            </div>
            <div class="actions">
                @if ($terminal)
                    <a class="btn" href="{{ route($area.'.files.terminal', $website) }}">@include('files::partials.icon', ['name' => 'terminal']) {{ __('Terminal') }}</a>
                @endif
                <a class="btn" href="https://{{ $website->domain }}" target="_blank" rel="noopener">{{ __('Visit website') }}<x-icon name="external" /></a>
            </div>
        </div>

        <template x-if="error"><div class="alert alert-bad" role="alert"><span x-text="error"></span></div></template>
        <template x-if="notice"><div class="alert alert-ok" role="status"><span x-text="notice"></span></div></template>
        <template x-if="job">
            <div class="alert" :class="job.status === 'failed' ? 'alert-bad' : 'alert-info'" role="status">
                <div style="flex:1">
                    <strong x-text="job.status === 'failed' ? @js(__('The operation failed')) : job.label"></strong>
                    <p class="small" x-text="job.error || (job.log || []).slice(-1)[0] || ''"></p>
                </div>
                <button type="button" class="btn btn-sm btn-ghost" x-show="job.status !== 'running'" @click="job = null">{{ __('Close') }}</button>
            </div>
        </template>

        <section class="card card-flush">
            <div class="fm-toolbar">
                <div class="group">
                    <button type="button" class="btn btn-primary btn-sm" @click="pick(false)">@include('files::partials.icon', ['name' => 'upload']) {{ __('Upload') }}</button>
                    <button type="button" class="btn btn-sm fm-hide-sm" @click="pick(true)">{{ __('Upload folder') }}</button>
                    <button type="button" class="btn btn-sm" @click="ask('mkdir')">@include('files::partials.icon', ['name' => 'folder-plus']) {{ __('New folder') }}</button>
                    <button type="button" class="btn btn-sm" @click="ask('mkfile')">@include('files::partials.icon', ['name' => 'file-plus']) {{ __('New file') }}</button>
                    <button type="button" class="btn btn-sm" x-show="clipboard" @click="paste()" x-text="clipboard ? @js(__('Paste')) + ' (' + clipboard.paths.length + ')' : ''"></button>
                    <input type="file" multiple hidden x-ref="fileInput" @change="picked($event)">
                    <input type="file" multiple webkitdirectory hidden x-ref="folderInput" @change="picked($event)">
                </div>
                <form class="fm-search" @submit.prevent="runSearch()">
                    <input type="search" class="input" x-model="search" placeholder="{{ __('Search in this folder') }}" aria-label="{{ __('Search in this folder') }}" @search="if (!search) clearSearch()">
                    <button type="button" class="btn btn-sm btn-ghost" x-show="searchResults" @click="clearSearch()">{{ __('Clear') }}</button>
                    <button type="button" class="btn btn-sm btn-ghost icon-btn" @click="load(false)" aria-label="{{ __('Refresh') }}" title="{{ __('Refresh') }}"><x-icon name="refresh" /></button>
                </form>
            </div>

            <nav class="fm-path" aria-label="{{ __('Folder') }}">
                <button type="button" @click="load(true, '')" :class="!path && 'current'">{{ $website->domain }}</button>
                <template x-for="c in crumbs" :key="c.path">
                    <span><span class="sep">/</span><button type="button" @click="load(true, c.path)" :class="c.path === path && 'current'" x-text="c.name"></button></span>
                </template>
                <span class="muted small" style="margin-left:auto" x-show="searchResults" x-text="@js(__('Search results')) + ': ' + (searchResults || []).length"></span>
            </nav>

            <div class="fm-selection" x-show="selected.length" x-cloak>
                <strong x-text="selected.length + ' ' + (selected.length === 1 ? @js(__('item selected')) : @js(__('items selected')))"></strong>
                <button type="button" class="btn btn-sm" @click="downloadPaths(selected)">@include('files::partials.icon', ['name' => 'download']) {{ __('Download') }}</button>
                <button type="button" class="btn btn-sm" @click="ask('compress')">{{ __('Compress') }}</button>
                <button type="button" class="btn btn-sm" x-show="single && isArchive(single)" @click="ask('extract', single)">{{ __('Extract') }}</button>
                <button type="button" class="btn btn-sm" @click="copy()">{{ __('Copy') }}</button>
                <button type="button" class="btn btn-sm" @click="cut()">{{ __('Cut') }}</button>
                <button type="button" class="btn btn-sm" @click="ask('move')">{{ __('Move to…') }}</button>
                <button type="button" class="btn btn-sm" x-show="single" @click="ask('rename', single)">{{ __('Rename') }}</button>
                <button type="button" class="btn btn-sm" @click="ask('chmod', single)">{{ __('Permissions') }}</button>
                <button type="button" class="btn btn-sm btn-danger" @click="ask('delete')"><x-icon name="trash" /> {{ __('Delete') }}</button>
            </div>

            <div class="fm-drop" :class="dragging && 'dragging'" data-drop="{{ __('Drop files or folders to upload them here') }}"
                 @dragover.prevent="dragging = true" @dragleave.self="dragging = false" @drop.prevent="dropped($event)">
                <div class="table-wrap">
                    <table class="table fm-table">
                        <thead><tr>
                            <th class="check"><input type="checkbox" :checked="allSelected" @change="toggleAll()" aria-label="{{ __('Select all') }}"></th>
                            <th><button type="button" @click="sortBy('name')">{{ __('Name') }}</button></th>
                            <th class="num"><button type="button" @click="sortBy('size')">{{ __('Size') }}</button></th>
                            <th class="fm-hide-sm"><button type="button" @click="sortBy('mtime')">{{ __('Modified') }}</button></th>
                            <th class="fm-hide-sm">{{ __('Permissions') }}</th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                            <tr x-show="path && !searchResults">
                                <td class="check"></td>
                                <td colspan="5"><div class="fm-name">@include('files::partials.icon', ['name' => 'up'])<button type="button" @click="load(true, path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '')">..</button></div></td>
                            </tr>
                            <template x-for="e in rows" :key="full(e)">
                                <tr :class="selected.includes(full(e)) && 'selected'" @dblclick="open(e)">
                                    <td class="check"><input type="checkbox" :checked="selected.includes(full(e))" @change="toggle(e)" :aria-label="e.name"></td>
                                    <td>
                                        <div class="fm-name">
                                            <span x-html="$refs['ico-' + icon(e)].innerHTML"></span>
                                            <button type="button" @click="open(e)" x-text="e.path || e.name" :title="e.target ? '→ ' + e.target : e.name"></button>
                                            <span class="fm-tag web" x-show="!path && isDir(e) && e.name === docroot">{{ __('website') }}</span>
                                            <span class="fm-tag" x-show="isProtected(e) && e.name !== docroot">{{ __('system') }}</span>
                                            <span class="fm-tag" x-show="e.type === 'link'">{{ __('link') }}</span>
                                        </div>
                                    </td>
                                    <td class="num muted" x-text="size(e.size)"></td>
                                    <td class="fm-hide-sm muted small" x-text="date(e.mtime)"></td>
                                    <td class="fm-hide-sm mono small muted" :title="e.mode" x-text="rwx(e.mode)"></td>
                                    <td class="num">
                                        <div class="fm-row-menu" @click.outside="menu === full(e) && (menu = null)">
                                            <button type="button" class="btn btn-sm btn-ghost icon-btn" @click="menu = menu === full(e) ? null : full(e)" aria-label="{{ __('Actions') }}">@include('files::partials.icon', ['name' => 'more'])</button>
                                            <div class="fm-menu" x-show="menu === full(e)" x-cloak x-transition.opacity>
                                                <button type="button" x-show="isText(e)" @click="open(e)"><x-icon name="edit" /> {{ __('Edit') }}</button>
                                                <button type="button" x-show="isDir(e)" @click="open(e)">{{ __('Open') }}</button>
                                                <button type="button" @click="menu = null; downloadPaths([full(e)])">{{ __('Download') }}</button>
                                                <button type="button" x-show="isArchive(e)" @click="ask('extract', e)">{{ __('Extract') }}</button>
                                                <button type="button" @click="ask('compress', e)">{{ __('Compress') }}</button>
                                                <button type="button" x-show="!isProtected(e)" @click="ask('rename', e)">{{ __('Rename') }}</button>
                                                <button type="button" x-show="!isProtected(e)" @click="ask('move', e)">{{ __('Move to…') }}</button>
                                                <button type="button" @click="ask('copy', e)">{{ __('Copy to…') }}</button>
                                                <button type="button" @click="ask('chmod', e)">{{ __('Permissions') }}</button>
                                                <button type="button" class="danger" x-show="!isProtected(e)" @click="ask('delete', e)"><x-icon name="trash" /> {{ __('Delete') }}</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="!loading && rows.length === 0"><td colspan="6"><div class="empty">{{ __('This folder is empty. Drop files here or use Upload.') }}</div></td></tr>
                            <tr x-show="loading && rows.length === 0"><td colspan="6" class="muted">{{ __('Loading…') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="fm-foot">
                <span x-text="rows.length + ' ' + @js(__('items')) + (truncated ? ' · ' + @js(__('only the first 5000 are shown')) : '')"></span>
                <span>
                    <label class="check small" style="display:inline-flex;gap:6px;margin-right:12px"><input type="checkbox" x-model="showHidden"> {{ __('Show hidden files') }}</label>
                    <span x-show="freeBytes !== null" x-text="size(freeBytes) + ' ' + @js(__('free'))"></span>
                </span>
            </div>
        </section>

        {{-- Icon templates, cloned into the rows. --}}
        <div hidden>
            @foreach (['folder', 'web', 'archive', 'code', 'image', 'link', 'file'] as $ico)
                <template x-ref="ico-{{ $ico }}"><span>@include('files::partials.icon', ['name' => $ico])</span></template>
            @endforeach
        </div>

        {{-- Upload queue --}}
        <div class="fm-uploads" x-show="uploads.length" x-cloak>
            <header>
                <span x-text="uploadsActive ? @js(__('Uploading')) + ' (' + uploadsActive + ')' : @js(__('Uploads finished'))"></span>
                <span>
                    <label class="small" style="font-weight:400;margin-right:8px"><input type="checkbox" x-model="overwrite"> {{ __('Overwrite') }}</label>
                    <button type="button" class="btn btn-sm btn-ghost" x-show="!uploadsActive" @click="clearUploads()">{{ __('Close') }}</button>
                </span>
            </header>
            <ul>
                <template x-for="u in uploads" :key="u.id || u.name">
                    <li>
                        <div class="name"><span x-text="u.name"></span>
                            <span :class="u.status === 'failed' ? 'bad' : 'muted'" x-text="u.status === 'done' ? '✓' : u.status === 'failed' ? @js(__('Failed')) : u.progress + '%'"></span></div>
                        <div class="fm-progress" x-show="u.status !== 'failed'"><i :style="'width:' + u.progress + '%'"></i></div>
                        <div class="small bad" x-show="u.error" x-text="u.error"></div>
                    </li>
                </template>
            </ul>
        </div>

        {{-- One dialog for every action --}}
        <dialog class="modal" x-ref="dialog" @close="dialog.kind = null" aria-labelledby="fm-dialog-title">
            <form @submit.prevent="submitDialog()">
                <div class="modal-head">
                    <h2 id="fm-dialog-title" x-text="{
                        mkdir: @js(__('New folder')), mkfile: @js(__('New file')), rename: @js(__('Rename')),
                        delete: @js(__('Delete')), chmod: @js(__('Permissions')), move: @js(__('Move to…')),
                        copy: @js(__('Copy to…')), compress: @js(__('Compress')), extract: @js(__('Extract archive'))
                    }[dialog.kind] || ''"></h2>
                    <button type="button" class="btn btn-ghost icon-btn" @click="closeDialog()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button>
                </div>
                <div class="modal-body form">
                    <template x-if="['mkdir', 'mkfile', 'rename'].includes(dialog.kind)">
                        <label class="field"><span class="label">{{ __('Name') }}</span>
                            <input class="input" x-model="dialog.value" required maxlength="255" autocomplete="off" spellcheck="false"></label>
                    </template>
                    <template x-if="dialog.kind === 'delete'">
                        <div>
                            <p x-text="dialog.paths.length === 1 ? @js(__('Delete this item permanently?')) : @js(__('Delete these items permanently?'))"></p>
                            <ul class="small mono" style="max-height:160px;overflow:auto;padding-left:18px"><template x-for="p in dialog.paths.slice(0, 30)" :key="p"><li x-text="p"></li></template></ul>
                            <p class="help">{{ __('Folders are deleted with everything in them. This cannot be undone, except from a backup.') }}</p>
                        </div>
                    </template>
                    <template x-if="dialog.kind === 'chmod'">
                        <div class="form">
                            <div class="fm-perm">
                                <span></span><span class="head">{{ __('Read') }}</span><span class="head">{{ __('Write') }}</span><span class="head">{{ __('Execute') }}</span>
                                <template x-for="[who, label] in [[2, @js(__('Owner'))], [1, @js(__('Group'))], [0, @js(__('Others'))]]" :key="who">
                                    <div style="display:contents">
                                        <span x-text="label"></span>
                                        <template x-for="bit in [4, 2, 1]" :key="bit">
                                            <input type="checkbox" :checked="modeBit(who, bit)" @change="setBit(who, bit, $event.target.checked)" :disabled="who === 0 && bit === 2">
                                        </template>
                                    </div>
                                </template>
                            </div>
                            <label class="field"><span class="label">{{ __('Octal') }}</span><input class="input mono" x-model="dialog.mode" pattern="[0-7]{3}" style="width:110px"></label>
                            <p class="help">{{ __('Files usually 644, folders 755. World writable permissions are not allowed.') }}</p>
                        </div>
                    </template>
                    <template x-if="['move', 'copy'].includes(dialog.kind)">
                        <label class="field"><span class="label">{{ __('Destination folder') }}</span>
                            <input class="input mono" x-model="dialog.to" placeholder="public/images" spellcheck="false">
                            <span class="help">{{ __('Relative to the root of your webspace. Leave empty for the root.') }}</span></label>
                    </template>
                    <template x-if="dialog.kind === 'compress'">
                        <div class="form">
                            <label class="field"><span class="label">{{ __('Archive name') }}</span><input class="input" x-model="dialog.value" required maxlength="200"></label>
                            <div class="seg" role="radiogroup" aria-label="{{ __('Format') }}">
                                <label><input type="radio" value="zip" x-model="dialog.format"><span>.zip</span></label>
                                <label><input type="radio" value="tar.gz" x-model="dialog.format"><span>.tar.gz</span></label>
                            </div>
                        </div>
                    </template>
                    <template x-if="dialog.kind === 'extract'">
                        <div class="form">
                            <p class="mono small" x-text="dialog.target"></p>
                            <label class="field"><span class="label">{{ __('Unpack into folder') }}</span><input class="input mono" x-model="dialog.to" spellcheck="false"></label>
                            <label class="check"><input type="checkbox" x-model="dialog.overwrite"><span>{{ __('Overwrite existing files') }}</span></label>
                            <p class="help">{{ __('Paths that would leave your webspace and links are skipped for your safety.') }}</p>
                        </div>
                    </template>
                    <template x-if="dialog.error"><div class="alert alert-bad" role="alert"><span x-text="dialog.error"></span></div></template>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn" @click="closeDialog()">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn" :class="dialog.kind === 'delete' ? 'btn-danger' : 'btn-dark'" :disabled="dialog.busy"
                            x-text="dialog.kind === 'delete' ? @js(__('Delete')) : @js(__('OK'))"></button>
                </div>
            </form>
        </dialog>
    </div>
</x-dynamic-component>
