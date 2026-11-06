@php
    $new = ! $target->exists;
    $parts = old('parts', $target->parts ?: \Rhis\Backups\Models\ClientBackupTarget::PARTS);
    $chosen = array_map('intval', old('websites', $target->website_ids ?? []));
    $typeLabels = ['sftp' => 'SFTP', 'scp' => 'SCP', 'ftps' => 'FTPS', 'ftp' => __('FTP (unencrypted)')];
@endphp
<x-layouts.client :title="$new ? __('Add backup location') : $target->name">
    <div class="page-head">
        <div><h1>{{ $new ? __('Add backup location') : $target->name }}</h1>
            <p class="sub">{{ __('Your own server where the panel stores the backups of your websites as zip files.') }}</p></div>
        <div class="actions"><x-ui.button :href="route('client.backups.index')" icon="backup">{{ __('Back to backups') }}</x-ui.button></div>
    </div>
    @error('agent')<div class="alert alert-bad" role="alert">{{ $message }}</div>@enderror

    <form method="POST" action="{{ $new ? route('client.backups.locations.store') : route('client.backups.locations.update', $target->id) }}" class="form"
          x-data="{ type: @js(old('type', $target->type)), schedule: @js(old('schedule', $target->schedule)) }">
        @csrf @unless ($new) @method('PUT') @endunless
        <x-ui.card :title="__('Connection')">
            <div class="form-grid">
                <x-ui.field name="name" :label="__('Name')" :value="$target->name" placeholder="NAS" required />
                <x-ui.field name="type" type="select" :label="__('Type')" x-model="type">
                    @foreach ($typeLabels as $value => $label)<option value="{{ $value }}" @selected(old('type', $target->type) === $value)>{{ $label }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="host" :label="__('Server')" :value="$target->host" placeholder="backup.example.nl" required />
                <x-ui.field name="port" type="number" min="1" max="65535" :label="__('Port')" :value="$target->port" placeholder="22 / 21" />
                <x-ui.field name="username" :label="__('User name')" :value="$target->username" required />
                <x-ui.field name="password" type="password" :label="__('Password')" autocomplete="new-password"
                    :help="$new ? null : __('Leave empty to keep the current password.')" />
            </div>
            <div x-show="type === 'sftp' || type === 'scp'">
                <x-ui.field name="ssh_key" type="textarea" rows="4" :label="__('SSH private key (instead of a password)')" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                    :help="__('The server key is remembered on the first connection and checked on every backup.')" />
                @if ($target->host_key)<p class="small muted">{{ __('Remembered server key') }}: <span class="mono">{{ implode(', ', array_map(fn ($l) => implode(' ', array_slice(explode(' ', $l), 1, 1)), explode("\n", $target->host_key))) }}</span></p>@endif
            </div>
            <div x-show="type === 'ftps'" x-cloak>
                <x-ui.field name="tls" type="select" :label="__('TLS')">
                    <option value="explicit" @selected(old('tls', $target->tls) === 'explicit')>{{ __('Explicit (port 21)') }}</option>
                    <option value="implicit" @selected(old('tls', $target->tls) === 'implicit')>{{ __('Implicit (port 990)') }}</option>
                </x-ui.field>
            </div>
            <div x-show="type === 'ftp'" x-cloak>
                <div class="alert alert-warn">{{ __('Plain FTP sends the password and your backups unencrypted over the internet. Use FTPS or SFTP when your server supports it.') }}</div>
                <label class="check"><input type="hidden" name="allow_plain_ftp" value="0"><input type="checkbox" name="allow_plain_ftp" value="1" @checked(old('allow_plain_ftp', $target->allow_plain_ftp))><span>{{ __('I understand, use plain FTP') }}</span></label>
                @error('allow_plain_ftp')<span class="error">{{ $message }}</span>@enderror
            </div>
            <x-ui.field name="path" :label="__('Folder on the server')" :value="$target->path" placeholder="backups/website" :help="__('A folder per website is made inside it.')" />
        </x-ui.card>

        <x-ui.card :title="__('What and when')">
            <fieldset class="bk-parts" style="margin-bottom: 12px">
                <legend class="small muted">{{ __('Back up') }}</legend>
                <label class="check"><input type="checkbox" name="parts[]" value="files" @checked(in_array('files', $parts, true))><span>{{ __('Website files') }}</span></label>
                <label class="check"><input type="checkbox" name="parts[]" value="databases" @checked(in_array('databases', $parts, true))><span>{{ __('Databases') }}</span></label>
                <label class="check"><input type="checkbox" name="parts[]" value="mail" @checked(in_array('mail', $parts, true))><span>{{ __('Mail') }}</span></label>
            </fieldset>
            @error('parts')<span class="error">{{ $message }}</span>@enderror
            @if ($websites->count() > 1)
                <fieldset class="bk-parts" style="margin-bottom: 12px">
                    <legend class="small muted">{{ __('Websites (none selected = all, also new ones)') }}</legend>
                    @foreach ($websites as $w)
                        <label class="check"><input type="checkbox" name="websites[]" value="{{ $w->id }}" @checked(in_array($w->id, $chosen, true))><span>{{ $w->domain }}</span></label>
                    @endforeach
                </fieldset>
            @endif
            <div class="form-grid">
                <x-ui.field name="schedule" type="select" :label="__('Automatic backup')" x-model="schedule">
                    <option value="daily" @selected(old('schedule', $target->schedule) === 'daily')>{{ __('Every day (full once a week, changes on the other days)') }}</option>
                    <option value="weekly" @selected(old('schedule', $target->schedule) === 'weekly')>{{ __('Every week (full)') }}</option>
                    <option value="off" @selected(old('schedule', $target->schedule) === 'off')>{{ __('Off, only when I start it') }}</option>
                </x-ui.field>
                <x-ui.field name="schedule_time" type="time" :label="__('Time')" :value="$target->schedule_time" x-bind:disabled="schedule === 'off'" />
                <x-ui.field name="full_day" type="select" :label="__('Full backup on')" x-bind:disabled="schedule === 'off'">
                    @foreach (\Rhis\Backups\ClientBackups::DAYS as $d)<option value="{{ $d }}" @selected(old('full_day', $target->full_day) === $d)>{{ __($d) }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="keep_full" type="number" min="1" max="30" :label="__('Keep full backups')" :value="$target->keep_full"
                    :help="__('Older backups are removed from the server, with the changes that belong to them.')" />
            </div>
        </x-ui.card>

        <div class="actions">
            <button type="submit" class="btn btn-dark"><x-icon name="check" />{{ $new ? __('Test and add') : __('Test and save') }}</button>
            <span class="small muted">{{ __('The connection is tested first: the panel writes and removes a small test file.') }}</span>
        </div>
    </form>

    @unless ($new)
        <form method="POST" action="{{ route('client.backups.locations.destroy', $target->id) }}" data-confirm="{{ __('Remove this location from the panel? The backups on it stay where they are.') }}" style="margin-top: 24px">
            @csrf @method('DELETE')<button class="btn btn-danger" type="submit"><x-icon name="trash" />{{ __('Remove location') }}</button>
        </form>
    @endunless
</x-layouts.client>
