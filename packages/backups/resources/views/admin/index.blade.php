@php
    $last = $status['last_run'] ?? null;
    $bytes = function ($b) { if ($b === null) return '–'; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, $i ? 1 : 0).' '.$u[$i]; };
    $statusPill = ['ok' => 'ok', 'warning' => 'warn', 'failed' => 'bad'];
    $typeLabels = ['sftp' => 'SFTP', 'scp' => 'SCP', 'ftps' => 'FTPS', 'ftp' => 'FTP', 'onedrive' => 'OneDrive', 'local' => __('Local / mounted disk')];
    $scopeLabel = function ($s) {
        return match (true) {
            $s === 'system' => __('Server configuration'),
            str_starts_with($s, 'site:') => __('Website').' '.substr($s, 5),
            str_starts_with($s, 'mail:') => __('Mail').' '.substr($s, 5),
            str_starts_with($s, 'db:') => __('Database').' '.substr($s, 3),
            default => $s,
        };
    };
@endphp
<x-layouts.admin :title="__('Backups')">
    <div class="page-head">
        <div><h1>{{ __('Backups') }}</h1><p class="sub">{{ __('Encrypted full and incremental backups of websites, mail, databases and server configuration.') }}</p></div>
    </div>
    @include('backups::partials.admin-tabs')

    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @if (session('repository_key'))
        <div class="alert alert-warn" role="alert">
            <div>
                <strong>{{ __('Repository key of :name', ['name' => session('repository_key')['target']]) }}</strong>
                <p class="small">{{ __('Store this key in your password manager. Without it the backups cannot be restored on another server. It is shown only now.') }}</p>
                <p class="mono" style="word-break:break-all">{{ session('repository_key')['key'] }}</p>
            </div>
        </div>
    @endif

    @include('backups::partials.restore-test-summary')

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Last backup')">
            @if ($last)
                <dl class="kv">
                    <dt>{{ __('Status') }}</dt><dd><x-ui.pill :status="$statusPill[$last['status']] ?? 'neutral'">{{ __(ucfirst($last['status'])) }}</x-ui.pill></dd>
                    <dt>{{ __('Kind') }}</dt><dd>{{ $last['kind'] === 'full' ? __('Full') : __('Incremental') }}</dd>
                    <dt>{{ __('Started') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($last['started'])->diffForHumans() }}</dd>
                    <dt>{{ __('Duration') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($last['started'])->diffAsCarbonInterval(\Illuminate\Support\Carbon::parse($last['finished']))->forHumans(short: true) }}</dd>
                    @foreach ($last['targets'] as $t)
                        @php $items = collect($t['items']); @endphp
                        <dt>{{ $t['target'] }}</dt>
                        <dd>{{ __(':ok of :total items', ['ok' => $items->whereIn('status', ['ok', 'skipped'])->count(), 'total' => $items->count()]) }} · {{ $bytes($items->sum('data_added')) }} {{ __('new') }}
                            @foreach ($items->where('status', 'failed') as $f)<br><span class="small" style="color:var(--bad, #c0392b)">{{ $scopeLabel($f['scope']) }}: {{ \Illuminate\Support\Str::limit($f['reason'] ?? '', 140) }}</span>@endforeach
                        </dd>
                    @endforeach
                </dl>
            @else
                <x-ui.empty icon="backup">{{ __('No backup has run yet.') }}</x-ui.empty>
            @endif
            @if ($status['running'] ?? false)<p><x-ui.pill status="warn">{{ __('A backup job is running') }}</x-ui.pill></p>@endif
        </x-ui.card>

        <x-ui.card :title="__('Back up now')">
            <form method="POST" action="{{ route('admin.backups.run') }}" class="form">
                @csrf
                <div class="seg" role="radiogroup" aria-label="{{ __('Kind') }}">
                    <label><input type="radio" name="kind" value="incremental" checked><span>{{ __('Incremental') }}</span></label>
                    <label><input type="radio" name="kind" value="full"><span>{{ __('Full') }}</span></label>
                </div>
                <div class="form-grid">
                    <x-ui.field name="target" type="select" :label="__('Location')">
                        <option value="">{{ __('All locations') }}</option>
                        @foreach ($targets as $t)<option value="{{ $t['name'] }}">{{ $t['name'] }}</option>@endforeach
                    </x-ui.field>
                    <x-ui.field name="scope" type="select" :label="__('What')">
                        <option value="all">{{ __('Everything') }}</option>
                        <option value="sites">{{ __('All websites') }}</option>
                        <option value="mail">{{ __('All mail') }}</option>
                        <option value="databases">{{ __('All databases') }}</option>
                        <option value="system">{{ __('Server configuration') }}</option>
                    </x-ui.field>
                </div>
                <p class="muted small">{{ __('Incremental only reads changed files. Full reads and checks every file again. Every backup is a complete restore point.') }}</p>
                <div><button type="submit" class="btn btn-dark" @disabled(empty($targets))><x-icon name="backup" />{{ __('Start backup') }}</button></div>
            </form>
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Restore points')" flush>
        <x-slot:actions>
            <form method="GET" action="{{ route('admin.backups.index') }}" class="actions">
                <select name="target" class="select" aria-label="{{ __('Location') }}">
                    @foreach ($targets as $t)<option value="{{ $t['name'] }}" @selected($t['name'] === $selected)>{{ $t['name'] }}</option>@endforeach
                </select>
                <input type="text" name="scope" class="input" value="{{ $scope }}" placeholder="site:example.nl" aria-label="{{ __('Filter') }}">
                <button class="btn btn-sm" type="submit">{{ __('Filter') }}</button>
            </form>
        </x-slot:actions>
        @if (empty($snapshots))
            <x-ui.empty icon="backup">{{ $selected ? __('No restore points on this location yet.') : __('Add a backup location first.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Date') }}</th><th>{{ __('What') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Server') }}</th><th class="num">{{ __('Restore') }}</th></x-slot:head>
            @foreach ($snapshots as $s)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($s['time'])->isoFormat('lll') }}<br><span class="mono small muted">{{ $s['short_id'] }}</span></td>
                    <td>{{ $scopeLabel($s['scope']) }}</td>
                    <td><x-ui.pill :status="$s['kind'] === 'full' ? 'info' : 'neutral'" :dot="false">{{ match ($s['kind']) { 'full' => __('Full'), 'incremental' => __('Incremental'), 'pre-restore' => __('Before restore'), default => $s['kind'] } }}</x-ui.pill></td>
                    <td class="small">{{ $s['host'] ?? '–' }}</td>
                    <td class="num">
                        <div class="actions" style="justify-content:flex-end">
                            <form method="POST" action="{{ route('admin.backups.restore') }}" data-confirm="{{ __('Put :what back as it was on :date? Current data is saved first as a restore point.', ['what' => $scopeLabel($s['scope']), 'date' => \Illuminate\Support\Carbon::parse($s['time'])->isoFormat('lll')]) }}">
                                @csrf
                                <input type="hidden" name="target" value="{{ $selected }}"><input type="hidden" name="snapshot" value="{{ $s['id'] }}"><input type="hidden" name="scope" value="{{ $s['scope'] }}"><input type="hidden" name="mode" value="in_place">
                                <button class="btn btn-sm btn-dark" type="submit">{{ __('Restore') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.backups.restore') }}">
                                @csrf
                                <input type="hidden" name="target" value="{{ $selected }}"><input type="hidden" name="snapshot" value="{{ $s['id'] }}"><input type="hidden" name="scope" value="{{ $s['scope'] }}"><input type="hidden" name="mode" value="copy">
                                <button class="btn btn-sm" type="submit" title="{{ __('Restore to /root/restore/:id on the server', ['id' => $s['short_id']]) }}">{{ __('Copy') }}</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Backup locations')" flush>
            @if (empty($targets))
                <x-ui.empty icon="backup">{{ __('No backup locations yet.') }}</x-ui.empty>
            @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Name') }}</th><th>{{ __('Type') }}</th><th>{{ __('Where') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                @foreach ($targets as $t)
                    <tr>
                        <td><strong>{{ $t['name'] }}</strong></td>
                        <td>{{ $typeLabels[$t['type']] ?? $t['type'] }}</td>
                        <td class="small mono">{{ $t['user'] ? $t['user'].'@' : '' }}{{ $t['host'] }}{{ $t['port'] ? ':'.$t['port'] : '' }}{{ $t['host'] ? ' ' : '' }}{{ $t['path'] }}</td>
                        <td class="num">
                            <div class="actions" style="justify-content:flex-end">
                                <form method="POST" action="{{ route('admin.backups.targets.test', $t['name']) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Test') }}</button></form>
                                <form method="POST" action="{{ route('admin.backups.targets.check', $t['name']) }}">@csrf<button class="btn btn-sm" type="submit" title="{{ __('Download part of the backup and verify it') }}">{{ __('Verify') }}</button></form>
                                <form method="POST" action="{{ route('admin.backups.targets.key', $t['name']) }}" data-confirm="{{ __('Show the repository key?') }}">@csrf<button class="btn btn-sm" type="submit" aria-label="{{ __('Show key') }}"><x-icon name="shield" /></button></form>
                                <form method="POST" action="{{ route('admin.backups.targets.destroy', $t['name']) }}" data-confirm="{{ __('Remove this location from the panel? The backups on it stay where they are.') }}">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" type="submit" aria-label="{{ __('Remove') }}"><x-icon name="trash" /></button></form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Schedule')">
            <form method="POST" action="{{ route('admin.backups.schedule') }}" class="form">
                @csrf @method('PUT')
                <label class="check"><input type="hidden" name="incremental_enabled" value="0"><input type="checkbox" name="incremental_enabled" value="1" @checked($schedule['incremental']['enabled'])><span>{{ __('Incremental backup every day') }}</span></label>
                <x-ui.field name="incremental_time" type="time" :label="__('Time')" :value="$schedule['incremental']['time']" />
                <label class="check"><input type="hidden" name="full_enabled" value="0"><input type="checkbox" name="full_enabled" value="1" @checked($schedule['full']['enabled'])><span>{{ __('Full backup every week') }}</span></label>
                <div class="form-grid">
                    <x-ui.field name="full_day" type="select" :label="__('Day')">
                        @foreach ($days as $d)<option value="{{ $d }}" @selected($schedule['full']['day'] === $d)>{{ __($d) }}</option>@endforeach
                    </x-ui.field>
                    <x-ui.field name="full_time" type="time" :label="__('Time')" :value="$schedule['full']['time']" />
                </div>
                <p class="muted small">{{ __('Old restore points are cleaned up after each backup: the last 3, then one per day for 7 days, per week for 4 weeks and per month for 6 months. Once a month part of every location is downloaded and verified.') }}</p>
                <div><button type="submit" class="btn btn-dark">{{ __('Save schedule') }}</button></div>
            </form>
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Add a backup location')">
        <form method="POST" action="{{ route('admin.backups.targets.store') }}" class="form" x-data="{ type: @js(old('type', 'sftp')), existing: {{ old('repo_key') ? 'true' : 'false' }} }">
            @csrf
            <div class="form-grid">
                <x-ui.field name="name" :label="__('Name')" placeholder="nas" :help="__('Lower case letters, digits, - and _.')" required />
                <x-ui.field name="type" type="select" :label="__('Type')" x-model="type">
                    @foreach ($types as $t)<option value="{{ $t }}" @selected(old('type', 'sftp') === $t)>{{ $typeLabels[$t] }}</option>@endforeach
                </x-ui.field>
            </div>
            <div class="form-grid" x-show="type !== 'onedrive' && type !== 'local'">
                <x-ui.field name="host" :label="__('Server')" placeholder="backup.example.nl" />
                <x-ui.field name="port" type="number" min="1" max="65535" :label="__('Port')" placeholder="22" />
                <x-ui.field name="user" :label="__('User name')" />
                <x-ui.field name="password" type="password" :label="__('Password')" autocomplete="new-password" />
            </div>
            <div x-show="type === 'sftp' || type === 'scp'">
                <x-ui.field name="ssh_key" type="textarea" rows="4" :label="__('SSH private key (instead of a password)')" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" :help="__('The server key is remembered on the first connection and checked on every backup.')" />
            </div>
            <div x-show="type === 'ftps'" x-cloak>
                <x-ui.field name="tls" type="select" :label="__('TLS')"><option value="explicit">{{ __('Explicit (port 21)') }}</option><option value="implicit">{{ __('Implicit (port 990)') }}</option></x-ui.field>
            </div>
            <div x-show="type === 'ftp'" x-cloak>
                <div class="alert alert-warn">{{ __('Plain FTP sends the password unencrypted. Use FTPS or SFTP when the server supports it.') }}</div>
                <label class="check"><input type="hidden" name="allow_plain_ftp" value="0"><input type="checkbox" name="allow_plain_ftp" value="1"><span>{{ __('I understand, use plain FTP') }}</span></label>
            </div>
            <div x-show="type === 'onedrive'" x-cloak>
                <p class="muted small">{{ __('Run "rclone authorize onedrive" on a computer with a browser, sign in, and paste the JSON it prints below.') }}</p>
                <x-ui.field name="onedrive_token" type="textarea" rows="3" :label="__('OneDrive token (JSON)')" />
                <div class="form-grid">
                    <x-ui.field name="drive_type" type="select" :label="__('Account')"><option value="personal">{{ __('OneDrive personal') }}</option><option value="business">{{ __('OneDrive for Business') }}</option><option value="documentLibrary">{{ __('SharePoint library') }}</option></x-ui.field>
                    <x-ui.field name="drive_id" :label="__('Drive ID (Business/SharePoint)')" />
                </div>
            </div>
            <x-ui.field name="path" :label="__('Folder on the location')" placeholder="whp/{{ gethostname() }}" required />
            <label class="check"><input type="checkbox" x-model="existing"><span>{{ __('Connect to existing backups (for example after moving to a new server)') }}</span></label>
            <div x-show="existing" x-cloak><x-ui.field name="repo_key" type="password" :label="__('Repository key')" autocomplete="off" /></div>
            <div><button type="submit" class="btn btn-dark">{{ __('Add location') }}</button></div>
        </form>
    </x-ui.card>

    @if (! empty($jobs))
    <x-ui.card :title="__('Recent jobs')" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Job') }}</th><th>{{ __('By') }}</th><th>{{ __('Started') }}</th></x-slot:head>
            @foreach ($jobs as $j)
                <tr><td><a href="{{ route('admin.backups.jobs.show', $j['id']) }}">{{ $j['label'] }}</a></td><td>{{ $j['user'] ?? '–' }}</td><td class="small muted">{{ \Illuminate\Support\Carbon::parse($j['started_at'])->diffForHumans() }}</td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    @endif
</x-layouts.admin>
