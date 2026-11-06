{{-- Restore from an uploaded backup zip: upload in chunks, check, choose, restore. --}}
@php
    $cfg = [
        'start' => route('client.backups.uploads.start'),
        'base' => url('backups/uploads'),
        'csrf' => csrf_token(),
        'max' => (int) config('backups.client_max_upload_gb', 20) * 1024 ** 3,
        'text' => [
            'tooBig' => __('This file is larger than :n GB.', ['n' => config('backups.client_max_upload_gb', 20)]),
            'notZip' => __('Choose a .zip file.'),
            'confirm' => __('Put the backup back on this website? Files that are not in the backup are removed and the selected databases are overwritten.'),
        ],
    ];
@endphp
<script src="{{ route('backups.assets.upload') }}"></script>

<x-ui.card :title="__('Restore from a file')">
    <div x-data="backupUpload(@js($cfg))">
        <div class="alert alert-bad" role="alert" x-show="error" x-cloak x-text="error"></div>

        <div x-show="step === 'pick'">
            <p class="muted">{{ __('Upload a backup zip that the panel made earlier (also from another server). It is checked first; nothing changes until you confirm.') }}</p>
            <label class="bk-drop" :class="{ 'is-over': over }" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="over = false; choose($event.dataTransfer.files[0])">
                <input type="file" accept=".zip,application/zip" class="sr-only" @change="choose($event.target.files[0])">
                <x-icon name="backup" /><br>
                <strong>{{ __('Choose a backup zip or drop it here') }}</strong><br>
                <span class="small muted">{{ __('Up to :n GB', ['n' => config('backups.client_max_upload_gb', 20)]) }}</span>
            </label>
        </div>

        <div x-show="step === 'upload' || step === 'check'" x-cloak>
            <p><strong x-text="file && file.name"></strong> <span class="muted small" x-text="file && size(file.size)"></span></p>
            <div class="bk-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent"><span :style="`width: ${percent}%`"></span></div>
            <p class="small muted" x-show="step === 'upload'"><span x-text="percent"></span>% · {{ __('Keep this page open while uploading.') }}</p>
            <p class="small muted" x-show="step === 'check'">{{ __('Checking the backup...') }}</p>
        </div>

        <div x-show="step === 'choose'" x-cloak class="form">
            <dl class="kv" x-show="info">
                <dt>{{ __('Backup of') }}</dt><dd x-text="info && info.domain"></dd>
                <dt>{{ __('Made on') }}</dt><dd x-text="info && new Date(info.created).toLocaleString()"></dd>
                <dt>{{ __('Contains') }}</dt><dd x-text="info && info.contains.join(', ') + (info.database_files.length ? ' (' + info.database_files.join(', ') + ')' : '')"></dd>
                <dt>{{ __('Size') }}</dt><dd x-text="info && size(info.size)"></dd>
            </dl>
            <div class="form-grid">
                <div class="field">
                    <label for="bk-up-site">{{ __('Restore onto') }}</label>
                    <select id="bk-up-site" class="select" x-model="website">
                        <option value="">{{ __('Choose a website') }}</option>
                        @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->domain }}</option>@endforeach
                    </select>
                </div>
                @if ($targets->isNotEmpty())
                <div class="field">
                    <label for="bk-up-safety">{{ __('First back up the current state to') }}</label>
                    <select id="bk-up-safety" class="select" x-model="safety">
                        <option value="">{{ __('No, do not back up first') }}</option>
                        @foreach ($targets as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                    </select>
                </div>
                @endif
            </div>
            <fieldset class="bk-parts">
                <legend class="small muted">{{ __('What to put back') }}</legend>
                <label class="check" x-show="info && info.contains.includes('files')"><input type="checkbox" value="files" x-model="parts"><span>{{ __('Website files') }}</span></label>
                <label class="check" x-show="info && info.contains.includes('databases')"><input type="checkbox" value="databases" x-model="parts"><span>{{ __('Databases') }}</span></label>
                <label class="check" x-show="info && info.contains.includes('mail')"><input type="checkbox" value="mail" x-model="parts"><span>{{ __('Mail') }}</span></label>
            </fieldset>
            <p class="small muted" x-show="info && website && info.domain && @js($websites->pluck('domain', 'id')->all())[website] !== info.domain">{{ __('Note: this backup was made of another website.') }}</p>
            <div class="actions">
                <button type="button" class="btn btn-dark" @click="restore()" :disabled="busy || !website || parts.length === 0"><x-icon name="backup" />{{ __('Restore') }}</button>
                <button type="button" class="btn" @click="cancel()">{{ __('Cancel') }}</button>
            </div>
        </div>
    </div>
</x-ui.card>
