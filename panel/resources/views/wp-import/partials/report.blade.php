{{-- Steps 2 and 3: what the check found, and the changes the user confirms before the import. --}}
@php
    $r = $import->report ?? [];
    $s = $r['site'] ?? [];
    $d = $r['database'] ?? [];
    $t = $r['target'] ?? [];
    $old = $s['old_db'] ?? [];
    $passed = $import->checkPassed();
    $cacheLabel = [
        'dropin' => __('Switch off :file: it connects to Redis or Memcached of the old host. You can switch on the object cache of this server later.'),
        'page_cache' => __('Switch off :file (page cache plugin). Keep it on to keep the plugin working: its paths are updated.'),
        'host_plugin' => __('Switch off :file: a plugin of the old hosting company that does not work here.'),
    ];
@endphp
<div class="wpi-layout">
    <div class="wpi-main">
        <x-ui.card :title="$passed ? __('The files are complete') : __('The check found problems')">
            @include('wp-import.partials.checks')
            @if ($passed)
                <dl class="kv wpi-summary">
                    <dt>{{ __('Website') }}</dt><dd>WordPress {{ $s['version'] ?? '?' }} · {{ \Illuminate\Support\Number::fileSize($s['size'] ?? 0) }}
                        @if (($s['skipped_size'] ?? 0) > 0)<span class="muted small">· {{ __(':size of cache and logs skipped', ['size' => \Illuminate\Support\Number::fileSize($s['skipped_size'])]) }}</span>@endif</dd>
                    <dt>{{ __('Database') }}</dt><dd>{{ $d['file'] ?? '' }} · {{ trans_choice(':count table|:count tables', $d['tables'] ?? 0) }} · {{ \Illuminate\Support\Number::fileSize($d['size'] ?? 0) }}</dd>
                    @if ($d['home'] ?? $d['siteurl'] ?? null)<dt>{{ __('Old address') }}</dt><dd class="mono">{{ $d['home'] ?? $d['siteurl'] }}</dd>@endif
                </dl>
            @else
                <p class="small">{{ __('Make the files again as described on the right and upload them once more. Nothing was changed.') }}</p>
                <form method="POST" action="{{ route($area.'.wp-import.reset', $import) }}">@csrf
                    <button class="btn btn-primary" type="submit"><x-icon name="refresh" />{{ __('Upload other files') }}</button></form>
            @endif
        </x-ui.card>

        @if ($passed)
            <form method="POST" action="{{ route($area.'.wp-import.run', $import) }}" class="wpi-confirm" x-data="wpImportConfirm(@js(['url' => old('new_url', $t['url'] ?? '')]))">
                @csrf
                <x-ui.card :title="__('Database connection')">
                    <p class="small muted">{{ __('A new database and database user are made for :domain and the export is loaded into it. wp-config.php gets these values:', ['domain' => $import->domain]) }}</p>
                    <x-ui.table class="wpi-diff">
                        <x-slot:head><th>{{ __('Setting') }}</th><th>{{ __('Now (old host)') }}</th><th></th><th>{{ __('New') }}</th></x-slot:head>
                        <tr><td class="mono">DB_NAME</td><td class="mono muted">{{ $old['db_name'] ?? '–' }}</td><td class="wpi-arrow">→</td><td class="mono strong">{{ $t['database'] ?? '' }}</td></tr>
                        <tr><td class="mono">DB_USER</td><td class="mono muted">{{ $old['db_user'] ?? '–' }}</td><td class="wpi-arrow">→</td><td class="mono strong">{{ $t['database'] ?? '' }}</td></tr>
                        <tr><td class="mono">DB_PASSWORD</td><td class="mono muted">{{ ($old['password_set'] ?? false) ? '••••••••' : '–' }}</td><td class="wpi-arrow">→</td><td class="strong">{{ __('New strong password') }}</td></tr>
                        <tr><td class="mono">DB_HOST</td><td class="mono muted">{{ $old['db_host'] ?? '–' }}</td><td class="wpi-arrow">→</td><td class="mono strong">localhost</td></tr>
                        <tr><td class="mono">$table_prefix</td><td class="mono muted">{{ $s['table_prefix'] ?? '–' }}</td><td class="wpi-arrow">→</td><td class="mono strong">{{ $d['prefix'] ?? '' }}</td></tr>
                    </x-ui.table>
                    <p class="small muted">{{ __('You find the database and its password under Databases after the import. The secret keys (salts) in wp-config.php are renewed, so everyone logs in again once.') }}</p>
                    @if (! empty($t['previous_databases']))
                        <p class="small">{{ __('The database of the earlier import (:names) is removed after a successful import.', ['names' => implode(', ', $t['previous_databases'])]) }}</p>
                    @endif
                    @if (($d['create_database'] ?? 0) || ($d['use_database'] ?? 0) || ($d['super_statements'] ?? 0) || ($d['mysql8_collation'] ?? false))
                        <p class="small muted">{{ __('Lines in the export that only work at the old host (database name, server settings) are skipped automatically.') }}</p>
                    @endif
                </x-ui.card>

                <x-ui.card :title="__('Website address')">
                    @if (count($t['urls'] ?? []) > 1)
                        <fieldset class="wpi-radios">
                            <legend class="small muted">{{ __('The address of the website on this server') }}</legend>
                            @foreach ($t['urls'] as $u)
                                <label class="check"><input type="radio" name="new_url" value="{{ $u }}" x-model="url" @checked(old('new_url', $t['url']) === $u)><span class="mono">{{ $u }}</span></label>
                            @endforeach
                        </fieldset>
                    @else
                        <input type="hidden" name="new_url" value="{{ $t['url'] ?? '' }}">
                        <p class="small">{{ __('The address of the website on this server') }}: <span class="mono strong">{{ $t['url'] ?? '' }}</span></p>
                    @endif
                    @if (! empty($r['urls']))
                        <p class="small muted">{{ __('Links, images and settings in the database that use the old address are changed to the new one.') }}</p>
                        <ul class="wpi-pairs">
                            @foreach ($r['urls'] as $u)
                                <li><label class="check"><input type="checkbox" name="urls[]" value="{{ $u['old'] }}" @checked(in_array($u['old'], old('urls', array_column($r['urls'], 'old')), true))>
                                    <span><span class="mono">{{ $u['old'] }}</span> <span class="wpi-arrow">→</span> <span class="mono strong" x-text="url">{{ $u['new'] }}</span></span></label></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="small muted">{{ __('The address stays the same: nothing to change.') }}</p>
                    @endif
                </x-ui.card>

                <x-ui.card :title="__('Paths of the old hosting')">
                    @if (! empty($r['paths']))
                        <p class="small muted">{{ __('WordPress and plugins (cache, security, backup) often store the folder of the old server. These folders were found; ticked ones are changed to the folder on this server.') }}</p>
                        <ul class="wpi-pairs">
                            @foreach ($r['paths'] as $p)
                                <li><label class="check"><input type="checkbox" name="paths[]" value="{{ $p['old'] }}"
                                        @checked(in_array($p['old'], old('paths', collect($r['paths'])->where('likely', true)->pluck('old')->all()), true))>
                                    <span><span class="mono">{{ $p['old'] }}</span> <span class="wpi-arrow">→</span> <span class="mono strong">{{ $p['new'] }}</span>
                                        <small>{{ trans_choice('found :count time|found :count times', $p['count'] ?? 0) }}@unless ($p['likely'] ?? true) · {{ __('not sure: this may also be a normal link') }}@endunless</small></span></label></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="small muted">{{ __('No paths of the old server were found.') }}</p>
                    @endif
                </x-ui.card>

                <x-ui.card :title="__('Caching and the old host')">
                    <p class="small muted">{{ __('Cache folders (such as wp-content/cache) are not copied: they are rebuilt automatically. Cache settings with old paths are updated.') }}</p>
                    @if (! empty($s['cache']))
                        <ul class="wpi-pairs">
                            @foreach ($s['cache'] as $c)
                                <li><label class="check"><input type="checkbox" name="disable[]" value="{{ $c['file'] }}"
                                        @checked(in_array($c['file'], old('disable', collect($s['cache'])->where('disable', true)->pluck('file')->all()), true))>
                                    <span>{!! str_replace(':file', '<span class="mono">'.e($c['file']).'</span>', e($cacheLabel[$c['kind']] ?? ':file')) !!}</span></label></li>
                            @endforeach
                        </ul>
                    @endif
                    @if (! empty($s['cache_defines']))
                        <p class="small muted">{{ __('Cache settings in wp-config.php: :list', ['list' => implode(', ', $s['cache_defines'])]) }}</p>
                    @endif
                    <details class="wpi-how">
                        <summary>{{ __('Change something else as well (optional)') }}</summary>
                        <p class="small muted">{{ __('For example an old CDN address. Every occurrence in the database is replaced.') }}</p>
                        <div class="form-grid">
                            <x-ui.field name="extra_old" :label="__('Find')" placeholder="https://cdn.old-host.com" autocomplete="off" spellcheck="false" />
                            <x-ui.field name="extra_new" :label="__('Replace with')" autocomplete="off" spellcheck="false" />
                        </div>
                    </details>
                </x-ui.card>

                <x-ui.card :title="__('Start the import')">
                    <ol class="wpi-plan small">
                        <li>{{ __('Unpack the website into :folder', ['folder' => $t['docroot'] ?? '']) }}</li>
                        <li>{{ __('Make database :name and load the export', ['name' => $t['database'] ?? '']) }}</li>
                        <li>{{ __('Put the new database connection in wp-config.php') }}</li>
                        <li>{{ __('Change the ticked addresses and paths in the database') }}</li>
                        <li>{{ __('Check that the website answers') }}</li>
                    </ol>
                    @if (! empty($t['content']))
                        <div class="alert alert-warn" role="alert"><x-icon name="info" />
                            <div>{{ __(':domain already has files (:list). They are replaced. If the import fails, they are put back.', ['domain' => $import->domain, 'list' => implode(', ', array_slice($t['content'], 0, 5))]) }}</div></div>
                        <label class="check"><input type="checkbox" name="replace" value="1" @checked(old('replace'))>
                            <span>{{ __('Replace the current files of :domain', ['domain' => $import->domain]) }}</span></label>
                    @endif
                    <label class="check"><input type="checkbox" name="confirm" value="1" required>
                        <span>{{ __('I have checked the changes above') }}<small>{{ __('If anything goes wrong, the import is undone automatically.') }}</small></span></label>
                    <div class="actions">
                        <x-ui.button variant="primary" icon="wordpress">{{ __('Import website') }}</x-ui.button>
                        <button class="btn btn-ghost" type="submit" form="wpi-reset"><x-icon name="refresh" />{{ __('Upload other files') }}</button>
                    </div>
                </x-ui.card>
            </form>
            <form method="POST" action="{{ route($area.'.wp-import.reset', $import) }}" id="wpi-reset">@csrf</form>
        @endif
    </div>
    <aside class="wpi-side">
        <x-ui.card :title="__('What happens')">
            <p class="small">{{ __('The import puts the website on :domain. Until the domain points to this server, visitors still see the old website: you can check everything first.', ['domain' => $import->domain]) }}</p>
            <p class="small muted">{{ __('Nothing is changed at your old host.') }}</p>
        </x-ui.card>
    </aside>
</div>
<script src="{{ asset('assets/wp-import.js') }}?v={{ @filemtime(public_path('assets/wp-import.js')) }}"></script>
