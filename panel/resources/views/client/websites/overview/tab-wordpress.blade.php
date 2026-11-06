{{-- WordPress tab: one block per WordPress install on this website. --}}
@foreach ($o->wordpress as $i)
    <div class="wp-block">
        <div class="wp-head">
            <div>
                <h2>{{ $i->label() }}</h2>
                <p class="muted small">{{ $i->checked_at ? __('Checked :time', ['time' => $i->checked_at->diffForHumans()]) : __('Not checked yet') }}</p>
            </div>
            <div class="actions">
                <form method="POST" action="{{ $o->url('wordpress.refresh', $i) }}">@csrf<button class="btn btn-sm btn-ghost" type="submit"><x-icon name="refresh" />{{ __('Check now') }}</button></form>
                <a class="btn btn-sm" href="{{ $o->url('wordpress.show', $i) }}"><x-icon name="settings" />{{ __('Manage') }}</a>
            </div>
        </div>

        <div class="wp-links">
            @if ($o->has('files.browse'))<a href="{{ $o->url('files.browse', [$site, 'path' => $i->path]) }}">@include('client.websites.overview.icon', ['name' => 'folder']){{ __('Files') }}</a>@endif
            <a href="{{ $o->url('wordpress.show', $i) }}"><x-icon name="refresh" />{{ __('Plugins & themes') }}</a>
            <a href="{{ $o->url('wordpress.show', $i) }}"><x-icon name="key" />{{ __('Administrators') }}</a>
            @if ($o->has('backups.index'))<a href="{{ $o->url('backups.index') }}"><x-icon name="backup" />{{ __('Backups') }}</a>@endif
            <a href="{{ $i->url() }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open website') }}</a>
        </div>

        <div class="wp-grid">
            <div>
                <h3>{{ __('Updates') }}</h3>
                <dl class="kv">
                    <dt>WordPress</dt>
                    <dd>{{ $i->version ?? '?' }} @if ($i->core_update)<x-ui.pill status="warn">{{ $i->core_update }}</x-ui.pill>@endif</dd>
                    <dt>{{ __('Plugins') }}</dt>
                    <dd>@if ($i->outdated_plugins)<span class="is-warn">{{ trans_choice(':count update|:count updates', $i->outdated_plugins) }}</span>@else{{ __('Up to date') }}@endif</dd>
                    <dt>{{ __('Themes') }}</dt>
                    <dd>@if ($i->outdated_themes)<span class="is-warn">{{ trans_choice(':count update|:count updates', $i->outdated_themes) }}</span>@else{{ __('Up to date') }}@endif</dd>
                    <dt>{{ __('Last update') }}</dt>
                    <dd>{{ $i->last_update_at ? $i->last_update_at->diffForHumans() : '–' }}</dd>
                </dl>
                @if ($i->isOutdated())
                    <form method="POST" action="{{ $o->url('wordpress.update', $i) }}" onsubmit="return confirm(@js(__('Update WordPress, all plugins, themes and translations now?')))">
                        @csrf<input type="hidden" name="scope" value="all">
                        <button class="btn btn-primary btn-sm" type="submit"><x-icon name="refresh" />{{ __('Update everything') }}</button>
                    </form>
                    <p class="muted small">{{ __('A restore point is made first. If the website breaks, it is put back automatically.') }}</p>
                @endif
            </div>
            <div>
                <h3>{{ __('Hosting') }}</h3>
                <dl class="kv">
                    <dt>PHP</dt><dd>{{ $site->php_version }} <a class="small" href="{{ $o->url('websites.show', [$site, 'tab' => 'hosting']) }}#php">{{ __('Change') }}</a></dd>
                    <dt>{{ __('Page cache') }}</dt><dd>{{ $site->page_cache ? __('On') : __('Off') }}</dd>
                    <dt>WP-cron</dt><dd>{{ $i->cron_mode === 'system' ? __('Server, every :n min', ['n' => $i->cron_interval ?? 5]) : __('WordPress') }}</dd>
                    <dt>{{ __('Certificate') }}</dt><dd>{{ $site->ssl_status === 'active' ? 'Let\'s Encrypt' : __(ucfirst($site->ssl_status)) }}</dd>
                </dl>
            </div>
        </div>
    </div>
@endforeach
