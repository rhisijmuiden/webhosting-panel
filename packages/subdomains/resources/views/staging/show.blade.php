@php
    $statusLabel = ['copying' => __('Copying'), 'ready' => __('Ready'), 'failed' => __('Failed')];
    $statusPill = ['copying' => 'warn', 'ready' => 'ok', 'failed' => 'bad'];
    $last = $history->first();
    $tables = collect($plan['tables'] ?? []);
    $suggested = $plan['suggested_keep'] ?? [];
    $keepOld = old('keep_tables', ($plan['woocommerce'] ?? false) ? $suggested : []);
    $problem = $plan['error'] ?? (($plan['problems'] ?? [])[0] ?? null);
    $busy = $staging->status === 'copying' || $running;
    $step = $running ? collect($job['log'] ?? [])->reduce(function ($carry, $line) {
        return str_contains($line, 'database:') ? 3 : (str_contains($line, 'files:') ? max($carry, 2) : (str_contains($line, 'after the push') ? 4 : $carry));
    }, 1) : 0;
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Staging :domain', ['domain' => $staging->staging_domain])">
    @if ($busy)<meta http-equiv="refresh" content="4">@endif
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.websites.index') }}">{{ __('Websites') }}</a>
                @if ($staging->live) / <a href="{{ route($area.'.websites.show', [$staging->live, 'tab' => 'wordpress']) }}">{{ $staging->live_domain }}</a>@endif / {{ __('Staging') }}</div>
            <div class="stg-route"><h1>{{ $staging->staging_domain }} <span class="stg-arrow" aria-hidden="true">→</span> {{ $staging->live_domain }}</h1>
                <x-ui.pill :status="$running ? 'warn' : ($statusPill[$staging->status] ?? 'neutral')">{{ $running ? ($running->action === 'restore' ? __('Restoring') : __('Pushing')) : ($statusLabel[$staging->status] ?? $staging->status) }}</x-ui.pill></div>
            <p class="sub">{{ __('Staging copy of :domain', ['domain' => $staging->live_domain]) }}@if ($staging->copied_at) · {{ __('copied :time', ['time' => $staging->copied_at->diffForHumans()]) }}@endif</p>
        </div>
        @if ($staging->isReady())
            <div class="actions">
                <a class="btn" href="{{ $staging->stagingUrl() }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open staging') }}</a>
                @if ($wp && Route::has($area.'.wordpress.login'))
                    <form method="POST" action="{{ route($area.'.wordpress.login', $wp) }}" target="_blank">@csrf
                        <button class="btn btn-primary" type="submit"><x-icon name="wordpress" />{{ __('Log in to staging') }}</button></form>
                @endif
            </div>
        @endif
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @foreach (['push', 'refresh', 'restore', 'remove'] as $key)
        @error($key)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @endforeach
    @if ($staging->isReady() && $staging->error)<div class="alert alert-warn" role="alert"><x-icon name="info" />{{ $staging->error }}</div>@endif

    @if ($staging->status === 'copying')
        <x-ui.card :title="__('Making the staging copy')">
            <ol class="clone-progress">
                <li @class(['is-done' => $staging->siteClone?->status === 'running', 'is-now' => $staging->siteClone?->status === 'waiting'])>{{ __('Prepare :domain', ['domain' => $staging->staging_domain]) }}</li>
                <li @class(['is-now' => $staging->siteClone?->status === 'running'])>{{ __('Copy files and database') }}</li>
                <li>{{ __('Mark the copy as staging') }}</li>
            </ol>
            <p class="muted small">{{ __('This page refreshes by itself. You can leave it: the copy continues.') }}</p>
        </x-ui.card>
    @elseif ($staging->status === 'failed')
        <div class="alert alert-bad" role="alert"><x-icon name="info" />
            <div><strong>{{ __('The staging copy could not be made. Your live website was not changed.') }}</strong>
                @if ($staging->error)<p class="small">{{ $staging->error }}</p>@endif</div>
        </div>
    @endif

    @if ($running)
        <x-ui.card :title="$running->action === 'restore' ? __('Putting the backup back') : __('Pushing to :domain', ['domain' => $staging->live_domain])">
            @if ($running->action === 'restore')
                <p>{{ __('The files and the database of :domain are put back as they were at :time.', ['domain' => $staging->live_domain, 'time' => $running->backup]) }}</p>
            @else
                <ol class="clone-progress">
                    <li @class(['is-done' => $step > 1, 'is-now' => $step <= 1])>{{ __('Backup of the live website') }}</li>
                    <li @class(['is-done' => $step > 2, 'is-now' => $step === 2])>{{ __('Files') }}</li>
                    <li @class(['is-done' => $step > 3, 'is-now' => $step === 3])>{{ __('Database and addresses') }}</li>
                    <li @class(['is-now' => $step === 4])>{{ __('Check the live website') }}</li>
                </ol>
            @endif
            <p class="muted small">{{ __('This page refreshes by itself. If anything goes wrong, the live website is put back automatically.') }}</p>
            @if ($showLog && ! empty($job['log']))<div class="log">{{ implode("\n", $job['log']) }}</div>@endif
        </x-ui.card>
    @elseif ($last && $last->finished_at && $last->finished_at->gt(now()->subHour()))
        @if ($last->status === 'succeeded')
            <div class="alert alert-ok" role="status"><x-icon name="check" />
                <div><strong>{{ $last->action === 'restore' ? __('The backup is back on :domain.', ['domain' => $staging->live_domain]) : __(':domain is live.', ['domain' => $staging->live_domain]) }}</strong>
                    @if ($last->action === 'push')<p class="small">{{ __('Backup :backup was made first. Not happy? Put it back below.', ['backup' => $last->backup]) }}</p>@endif</div>
                <a class="btn btn-sm" href="{{ $staging->liveUrl() }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open live website') }}</a>
            </div>
        @else
            <div class="alert alert-bad" role="alert"><x-icon name="info" />
                <div><strong>{{ $last->rolled_back ? __('The push failed. The live website was put back as it was.') : ($last->action === 'restore' ? __('Putting the backup back failed.') : __('The push failed.')) }}</strong>
                    @if ($last->error)<p class="small">{{ $last->error }}</p>@endif</div>
            </div>
        @endif
    @endif

    @if ($staging->isReady() && ! $running)
        <div class="stg-layout">
            <div class="stg-col">
                <x-ui.card :title="__('Push to live')">
                    @if ($problem)
                        <div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __('A push is not possible: :problem', ['problem' => $problem]) }}</div>
                    @else
                        <form method="POST" action="{{ route($area.'.staging.push', $staging) }}" class="form stg-form"
                              x-data="{ db: @js((bool) old('database', true)) }">
                            @csrf
                            <p class="small muted">{{ __('Everything on :staging replaces :domain. A full backup of the live website is made first, and if the website does not work afterwards it is put back automatically.', ['staging' => $staging->staging_domain, 'domain' => $staging->live_domain]) }}</p>
                            <div class="stg-what">
                                <input type="hidden" name="files" value="0">
                                <label class="check"><input type="checkbox" name="files" value="1" @checked(old('files', '1') === '1')>
                                    <span>{{ __('Files') }}<small>{{ __('Themes, plugins, WordPress itself and media') }}</small></span></label>
                                <input type="hidden" name="database" value="0">
                                <label class="check"><input type="checkbox" name="database" value="1" x-model="db" @checked(old('database', '1') === '1')>
                                    <span>{{ __('Database') }}<small>{{ __('Pages, posts, settings and menus') }}</small></span></label>
                            </div>
                            <input type="hidden" name="keep_uploads" value="0">
                            <label class="check"><input type="checkbox" name="keep_uploads" value="1" @checked(old('keep_uploads', '1') === '1')>
                                <span>{{ __('Keep media that was only uploaded on the live website') }}<small>{{ __('Photos added to :domain while you worked on staging stay', ['domain' => $staging->live_domain]) }}</small></span></label>

                            <div x-show="db">
                                @if ($plan['woocommerce'] ?? false)
                                    <div class="alert alert-warn" role="alert"><x-icon name="info" />{{ __('This is a shop. Orders and customers that came in on the live website since the copy was made would be lost, so their tables are kept by default.') }}</div>
                                @endif
                                <details class="stg-tables-wrap" @if ($keepOld) open @endif>
                                    <summary>{{ __('Keep live data in these tables (:count of :total)', ['count' => count($keepOld), 'total' => $tables->count()]) }}</summary>
                                    <p class="small muted">{{ __('Ticked tables are not overwritten: the live website keeps its own data there. Leave the rest unticked to push your changes.') }}</p>
                                    <div class="stg-tables">
                                        @foreach ($tables as $t)
                                            <label class="check"><input type="checkbox" name="keep_tables[]" value="{{ $t['name'] }}" @checked(in_array($t['name'], $keepOld, true))>
                                                <span><span class="mono">{{ $t['name'] }}</span>
                                                    <small>{{ \Illuminate\Support\Number::fileSize(($t['size_mb'] ?? 0) * 1024 * 1024) }}@if (in_array($t['name'], $suggested, true)) · {{ __('live data: orders, customers, comments or form entries') }}@endif @unless ($t['on_live'] ?? true) · {{ __('new on staging') }}@endunless</small></span></label>
                                        @endforeach
                                    </div>
                                </details>
                            </div>

                            <x-ui.field name="confirm" :label="__('Type :domain to confirm', ['domain' => $staging->live_domain])" autocomplete="off" spellcheck="false" required />
                            <div><x-ui.button variant="primary" icon="send">{{ __('Push to :domain', ['domain' => $staging->live_domain]) }}</x-ui.button></div>
                        </form>
                    @endif
                </x-ui.card>

                <x-ui.card :title="__('History')">
                    @forelse ($history as $p)
                        <ul class="stg-list">
                            <li>
                                <div class="stg-grow"><strong>{{ $p->action === 'restore' ? __('Backup :backup put back', ['backup' => $p->backup]) : __('Pushed to live') }}</strong>
                                    <div class="muted small">{{ $p->started_at?->isoFormat('LLL') }}@if ($p->user) · {{ $p->user->name }}@endif
                                        @if ($p->action === 'push' && $p->options)
                                            · {{ collect([($p->options['files'] ?? false) ? __('files') : null, ($p->options['database'] ?? false) ? __('database') : null])->filter()->implode(' + ') }}
                                            @if (! empty($p->options['keep_tables'])) · {{ trans_choice(':count table kept|:count tables kept', count($p->options['keep_tables'])) }}@endif
                                        @endif</div>
                                    @if ($p->status === 'failed' && $p->error)<div class="small mono muted">{{ \Illuminate\Support\Str::limit($p->error, 200) }}</div>@endif</div>
                                <x-ui.pill :status="$p->status">{{ ['running' => __('Running'), 'succeeded' => __('Done'), 'failed' => $p->rolled_back ? __('Put back') : __('Failed')][$p->status] ?? $p->status }}</x-ui.pill>
                            </li>
                        </ul>
                    @empty
                        <p class="muted small">{{ __('Not pushed yet.') }}</p>
                    @endforelse
                </x-ui.card>
            </div>

            <div class="stg-col">
                <x-ui.card :title="__('Backups of the live website')">
                    <p class="small muted">{{ __('Made before each push. The last :n are kept.', ['n' => 3]) }}</p>
                    @forelse ($backups as $b)
                        <ul class="stg-list">
                            <li>
                                <div class="stg-grow"><strong>{{ \Illuminate\Support\Carbon::createFromTimestamp($b['created'] ?? 0)->isoFormat('LLL') }}</strong>
                                    <div class="muted small">{{ \Illuminate\Support\Number::fileSize(($b['size_mb'] ?? 0) * 1024 * 1024) }} · {{ trans_choice(':count table|:count tables', count($b['tables'] ?? [])) }}</div></div>
                                <form method="POST" action="{{ route($area.'.staging.restore', $staging) }}"
                                      onsubmit="return confirm(@js(__('Put :domain back as it was at this moment? Changes made since then are lost.', ['domain' => $staging->live_domain])))">
                                    @csrf<input type="hidden" name="backup" value="{{ $b['id'] }}">
                                    <button class="btn btn-sm" type="submit"><x-icon name="backup" />{{ __('Put back') }}</button>
                                </form>
                            </li>
                        </ul>
                    @empty
                        <p class="muted small">{{ __('No backup yet: the first one is made at the first push.') }}</p>
                    @endforelse
                </x-ui.card>

                <x-ui.card :title="__('Start over')">
                    <p class="small muted">{{ __('Copy :domain over the staging copy again. Changes on staging that were not pushed are lost.', ['domain' => $staging->live_domain]) }}</p>
                    <details class="stg-danger">
                        <summary>{{ __('Refresh the staging copy') }}</summary>
                        <form method="POST" action="{{ route($area.'.staging.refresh', $staging) }}" class="form stg-form">
                            @csrf
                            <x-ui.field name="confirm" :label="__('Type :domain to confirm', ['domain' => $staging->staging_domain])" autocomplete="off" spellcheck="false" required />
                            <div><button class="btn btn-sm" type="submit"><x-icon name="refresh" />{{ __('Copy live to staging') }}</button></div>
                        </form>
                    </details>
                </x-ui.card>

                <x-ui.card :title="__('Remove staging')">
                    <details class="stg-danger">
                        <summary>{{ __('Remove :domain', ['domain' => $staging->staging_domain]) }}</summary>
                        <form method="POST" action="{{ route($area.'.staging.destroy', $staging) }}" class="form stg-form">
                            @csrf @method('DELETE')
                            <input type="hidden" name="remove_site" value="1">
                            <p class="small">{{ __('All files and databases of :domain are deleted. The live website and its backups stay.', ['domain' => $staging->staging_domain]) }}</p>
                            <x-ui.field name="confirm" :label="__('Type :domain to confirm', ['domain' => $staging->staging_domain])" autocomplete="off" spellcheck="false" required />
                            <div><button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" />{{ __('Remove staging copy') }}</button></div>
                        </form>
                    </details>
                </x-ui.card>
            </div>
        </div>
    @elseif ($staging->status === 'failed')
        <div class="actions">
            @if ($staging->staging && $staging->live)
                <form method="POST" action="{{ route($area.'.staging.destroy', $staging) }}">@csrf @method('DELETE')
                    <button class="btn" type="submit"><x-icon name="trash" />{{ __('Forget this staging copy') }}</button></form>
            @endif
            @if ($staging->live && $staging->staging)
                <form method="POST" action="{{ route($area.'.staging.refresh', $staging) }}">@csrf
                    <input type="hidden" name="confirm" value="{{ $staging->staging_domain }}">
                    <button class="btn btn-primary" type="submit"><x-icon name="refresh" />{{ __('Try again') }}</button></form>
            @endif
        </div>
    @endif
    @include('subdomains::staging.partials.styles')
</x-dynamic-component>
