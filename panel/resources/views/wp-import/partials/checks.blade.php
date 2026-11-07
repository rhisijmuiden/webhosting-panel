{{-- The result of the check, one line per check, in the user's language. --}}
@php
    $r = $import->report ?? [];
    $s = $r['site'] ?? [];
    $d = $r['database'] ?? [];
    $text = function (array $c) use ($s, $d, $import) {
        $k = $c['key'] ?? '';
        $st = $c['state'] ?? 'ok';

        return match ($k) {
            'zip_valid' => [__('The website zip can be opened'), __(':count files and folders', ['count' => number_format($s['entries'] ?? 0, 0, ',', '.')])],
            'wordpress_found' => [__('WordPress found in the zip'), $st === 'ok'
                ? trim(($s['version'] ?? null ? 'WordPress '.$s['version'] : '').(($s['folder'] ?? '') !== '' ? ' · '.__('in the folder :folder', ['folder' => $s['folder']]) : ''))
                : __('The zip has no complete WordPress: wp-admin, wp-includes and wp-content are needed.')],
            'config_found' => [__('wp-config.php found'), match ($st) {
                'ok' => $c['detail'] ?? '',
                'warn' => __('wp-config.php is missing: a new one is made from wp-config-sample.php.'),
                default => __('wp-config.php and wp-config-sample.php are missing from the zip.'),
            }],
            'not_multisite' => [__('Not a multisite'), __('WordPress multisite cannot be imported with the wizard.')],
            'database_found' => [__('WordPress tables in the database export'), $st === 'ok'
                ? __(':count tables in :file', ['count' => $d['tables'] ?? 0, 'file' => $d['file'] ?? ''])
                : __('No WordPress tables found. Is this the export of the right database?')],
            'prefix_matches' => [__('Table prefix matches'), $st === 'ok' ? ($d['prefix'] ?? '')
                : __('wp-config.php uses :old, the database :new: wp-config.php is changed to :new.', ['old' => $s['table_prefix'] ?? '', 'new' => $d['prefix'] ?? ''])],
            'one_sql_file' => [__('One database file'), __('There are several .sql files in the zip: the largest one is used (:file).', ['file' => $d['file'] ?? ''])],
            'address_found' => [__('Old address found'), __('The address of the old website was not found in the database. Check the address after the import.')],
            'target_empty' => [__(':domain is empty', ['domain' => $import->domain]), __(':domain already has files. They are replaced by the imported website.', ['domain' => $import->domain])],
            'disk_space' => [__('Fits in the disk space'), match ($st) {
                'ok' => __(':size MB of :quota MB', ['size' => $c['size_mb'] ?? 0, 'quota' => $c['quota_mb'] ?? 0]),
                'warn' => __('Tight: the website is :size MB and :free MB is free now. Make room first if the import stops.', ['size' => $c['size_mb'] ?? 0, 'free' => $c['free_mb'] ?? 0]),
                default => __('The website (:size MB) is larger than the disk space of the package (:quota MB).', ['size' => $c['size_mb'] ?? 0, 'quota' => $c['quota_mb'] ?? 0]),
            }],
            'database_limit' => [__('A database is available'), $st === 'ok'
                ? trans_choice(':count database left in the package|:count databases left in the package', $c['left'] ?? 0)
                : __('The package has no database left. Remove a database or choose a bigger package.')],
            default => [$k, $c['detail'] ?? ''],
        };
    };
@endphp
<ul class="wpi-checks">
    @foreach ($r['checks'] ?? [] as $c)
        @php [$title, $detail] = $text($c); @endphp
        <li @class(['is-'.($c['state'] ?? 'ok')])>
            <span class="wpi-check-icon" aria-hidden="true">@if (($c['state'] ?? '') === 'ok')<x-icon name="check" />@elseif (($c['state'] ?? '') === 'warn')<x-icon name="info" />@else<x-icon name="close" />@endif</span>
            <div><strong>{{ $title }}</strong>@if ($detail)<small>{{ $detail }}</small>@endif</div>
        </li>
    @endforeach
</ul>
