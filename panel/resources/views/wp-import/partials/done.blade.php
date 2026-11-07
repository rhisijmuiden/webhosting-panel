{{-- Step 5: the website is imported. --}}
@php
    $res = $import->result ?? [];
    $wp = class_exists(\Rhis\WordPress\Models\WordPressInstall::class)
        ? \Rhis\WordPress\Models\WordPressInstall::where('website_id', $import->website_id)->where('path', '')->first() : null;
    $health = (int) ($res['health'] ?? 0);
@endphp
<div class="wpi-layout">
    <div class="wpi-main">
        <div class="wpi-done">
            <span class="wpi-done-icon" aria-hidden="true"><x-icon name="check" /></span>
            <div>
                <h2>{{ __('Your WordPress website is imported') }}</h2>
                <p class="small">{{ __(':domain runs on this server now, with its own database.', ['domain' => $import->domain]) }}</p>
                <div class="actions">
                    <a class="btn btn-primary" href="{{ $res['url'] ?? '#' }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open website') }}</a>
                    @if ($wp && Route::has($area.'.wordpress.login'))
                        <form method="POST" action="{{ route($area.'.wordpress.login', $wp) }}" target="_blank">@csrf
                            <button class="btn" type="submit"><x-icon name="wordpress" />{{ __('WP admin') }}</button></form>
                    @endif
                    @if (Route::has($area.'.websites.show'))<a class="btn btn-ghost" href="{{ route($area.'.websites.show', $import->website_id) }}">{{ __('Go to the website') }}</a>@endif
                </div>
            </div>
        </div>
        @if ($health >= 500 || $health === 0)
            <div class="alert alert-warn" role="alert"><x-icon name="info" />
                <div><strong>{{ __('The website answered with an error (HTTP :code).', ['code' => $health ?: '?']) }}</strong>
                    <p class="small">{{ __('Often a plugin that needs a newer PHP version or a setting of the old host. Look at the error log of the website, or switch off plugins in the file manager by renaming their folder.') }}</p></div></div>
        @endif
        <x-ui.card :title="__('What was done')">
            <dl class="kv">
                <dt>{{ __('Address') }}</dt><dd class="mono">{{ $res['url'] ?? '' }}</dd>
                <dt>{{ __('Database') }}</dt><dd class="mono">{{ $res['database']['name'] ?? '' }}</dd>
                <dt>{{ __('Database user') }}</dt><dd class="mono">{{ $res['database']['user'] ?? '' }} @ {{ $res['database']['host'] ?? 'localhost' }}</dd>
                <dt>{{ __('Changed in the database') }}</dt><dd>{{ trans_choice(':count value|:count values', $res['replacements'] ?? 0) }}</dd>
                @if (! empty($res['disabled']))<dt>{{ __('Switched off') }}</dt><dd class="mono">{{ implode(', ', $res['disabled']) }}</dd>@endif
                <dt>{{ __('Duration') }}</dt><dd>{{ $import->started_at && $import->finished_at ? $import->started_at->diffForHumans($import->finished_at, true) : '–' }}</dd>
            </dl>
            <p class="small muted">{{ __('The password of the database is in wp-config.php and under Databases. The uploaded files were removed from the server.') }}</p>
        </x-ui.card>
    </div>
    <aside class="wpi-side">
        <x-ui.card :title="__('Next steps')">
            <ol class="wpi-plan small">
                <li>{{ __('Check the website. Until the domain points here, your computer may still show the old website.') }}</li>
                <li>{{ __('Point the domain to this server: change the A record at your domain provider, or connect the domain under Email & domains.') }}</li>
                <li>{{ __('Log in to WP admin, check the plugins and empty caches once.') }}</li>
                <li>{{ __('Everything fine? Cancel the hosting at your old host.') }}</li>
            </ol>
        </x-ui.card>
    </aside>
</div>
