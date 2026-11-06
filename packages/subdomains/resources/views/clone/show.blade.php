@php
    $statusLabel = ['waiting' => __('Setting up the subdomain'), 'running' => __('Cloning'), 'succeeded' => __('Done'), 'failed' => __('Failed')];
    $statusPill = ['waiting' => 'warn', 'running' => 'warn', 'succeeded' => 'ok', 'failed' => 'bad'];
    $kindName = ['wordpress' => 'WordPress', 'joomla' => 'Joomla'];
    $result = $clone->result ?? [];
    $wp = $clone->status === 'succeeded' && $clone->target && class_exists(\Rhis\WordPress\Models\WordPressInstall::class)
        ? \Rhis\WordPress\Models\WordPressInstall::where('website_id', $clone->target->id)->orderBy('path')->first() : null;
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Clone to :domain', ['domain' => $clone->target_domain])">
    @if ($clone->busy())<meta http-equiv="refresh" content="4">@endif
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.websites.index') }}">{{ __('Websites') }}</a>
                @if ($clone->source) / <a href="{{ route($area.'.websites.show', $clone->source) }}">{{ $clone->source_domain }}</a>@endif / {{ __('Clone') }}</div>
            <div class="actions"><h1>{{ $clone->source_domain }} <span class="muted">→</span> {{ $clone->target_domain }}</h1>
                <x-ui.pill :status="$statusPill[$clone->status] ?? 'neutral'">{{ $statusLabel[$clone->status] ?? $clone->status }}</x-ui.pill></div>
        </div>
        @if ($clone->status === 'succeeded')
            <div class="actions">
                @if ($clone->target)<a class="btn" href="{{ route($area.'.websites.show', $clone->target) }}">{{ __('Manage :domain', ['domain' => $clone->target_domain]) }}</a>@endif
                <a class="btn btn-primary" href="{{ $result['url'] ?? 'https://'.$clone->target_domain }}" target="_blank" rel="noopener">{{ __('Open the copy') }}<x-icon name="external" /></a>
            </div>
        @endif
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif

    @if ($clone->busy())
        <x-ui.card>
            <ol class="clone-progress">
                <li @class(['is-done' => $clone->status === 'running', 'is-now' => $clone->status === 'waiting'])>{{ __('Prepare :domain', ['domain' => $clone->target_domain]) }}</li>
                <li @class(['is-now' => $clone->status === 'running'])>{{ __('Copy files and database') }}</li>
                <li>{{ __('Change addresses and settings') }}</li>
            </ol>
            <p class="muted small">{{ __('This page refreshes by itself. You can leave it: the clone continues.') }}</p>
            @if ($showLog && ! empty($job['log']))<div class="log">{{ implode("\n", $job['log']) }}</div>@endif
        </x-ui.card>
    @elseif ($clone->status === 'failed')
        <div class="alert alert-bad" role="alert"><x-icon name="info" />
            <div><strong>{{ __('The clone failed. The copy was removed again; your live website was not changed.') }}</strong>
            @if ($clone->error)<p class="small">{{ $clone->error }}</p>@endif</div>
        </div>
        @if ($clone->source && $clone->source->status === 'active')
            <div class="actions"><a class="btn" href="{{ route($area.'.clones.create', [$clone->source, 'target' => $clone->target_website_id]) }}"><x-icon name="refresh" />{{ __('Try again') }}</a></div>
        @endif
    @else
        <div class="grid grid-2" style="align-items:start">
            <x-ui.card :title="__('The copy is ready')">
                <dl class="kv">
                    <dt>{{ __('Address') }}</dt><dd><a href="{{ $result['url'] ?? '#' }}" target="_blank" rel="noopener">{{ $result['url'] ?? $clone->target_domain }}</a></dd>
                    <dt>{{ __('Files') }}</dt><dd>{{ isset($result['size_mb']) ? \Illuminate\Support\Number::fileSize($result['size_mb'] * 1024 * 1024) : '–' }}</dd>
                    @foreach ($result['installs'] ?? [] as $i)
                        <dt>{{ $kindName[$i['kind']] ?? $i['kind'] }} /{{ $i['path'] }}</dt>
                        <dd><span class="mono small">{{ $i['database'] }}</span>
                            @if (($i['replacements'] ?? null) !== null)<div class="muted small">{{ trans_choice(':count address changed in the database|:count addresses changed in the database', $i['replacements']) }}</div>@endif</dd>
                    @endforeach
                    <dt>{{ __('Search engines') }}</dt><dd>{{ $clone->noindex ? __('Hidden') : __('Visible') }}</dd>
                    <dt>{{ __('Finished') }}</dt><dd>{{ $clone->finished_at?->isoFormat('LLL') }}</dd>
                </dl>
            </x-ui.card>
            <x-ui.card :title="__('Next steps')">
                @if ($wp && Route::has($area.'.wordpress.login'))
                    <form method="POST" action="{{ route($area.'.wordpress.login', $wp) }}" target="_blank">@csrf
                        <button class="btn btn-primary" type="submit"><x-icon name="wordpress" />{{ __('Log in to WordPress on the copy') }}</button></form>
                @endif
                <ul class="clone-steps small">
                    <li>{{ __('Test your change on the copy: an update, a plugin or a new design.') }}</li>
                    <li>{{ __('Happy with it? Do the same on the live website, or clone the copy back over it.') }}</li>
                    <li>{{ __('Remove the subdomain when you no longer need it.') }}</li>
                </ul>
            </x-ui.card>
        </div>
    @endif
    @include('subdomains::partials.styles')
</x-dynamic-component>
