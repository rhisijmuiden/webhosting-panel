{{-- One app: status, controls, live log, dependencies, settings. Shared by admin and client. --}}
@php
    $r = fn ($name) => route($area.'.webapps.'.$name, $app);
    $confirm = fn (string $text) => 'if (!confirm('.\Illuminate\Support\Js::from($text).')) $event.preventDefault()';
    $state = $status['state'] ?? $app->last_state;
    $memCap = $package?->webapp_memory_mb;
    $mem = $status['memory_bytes'] ?? null;
    $running = in_array($state, ['running', 'starting', 'crashing'], true);
@endphp
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.webapps.index') }}">{{ __('Node.js & Python') }}</a> / {{ $app->website->domain }}</div>
        <div class="actions"><span class="wa-runtime">@include('webapps::partials.badge', ['runtime' => $app->runtime])<h1>{{ $app->name }}</h1></span>
            @include('webapps::partials.state-pill', ['state' => $state])</div>
        <p class="sub">{{ $app->runtimeLabel() }} · <a href="{{ $app->url() }}" target="_blank" rel="noopener">{{ $app->website->domain }}{{ $app->mount_path === '/' ? '' : $app->mount_path }} <x-icon name="external" /></a>
            · <span class="mono">{{ $app->app_root }}/</span> · <span class="mono">{{ $app->start_command }}</span></p>
    </div>
    <div class="actions">
        <form method="POST" action="{{ $r('control') }}">@csrf<input type="hidden" name="op" value="restart"><x-ui.button icon="refresh" :disabled="! $running && ! $app->enabled">{{ __('Restart') }}</x-ui.button></form>
        @if ($app->enabled)
            <form method="POST" action="{{ $r('control') }}" x-on:submit="{{ $confirm(__('Stop this app? Visitors see a "starting up" page until you start it again.')) }}">@csrf<input type="hidden" name="op" value="stop"><x-ui.button icon="pause">{{ __('Stop') }}</x-ui.button></form>
        @else
            <form method="POST" action="{{ $r('control') }}">@csrf<input type="hidden" name="op" value="start"><x-ui.button variant="primary" icon="play">{{ __('Start') }}</x-ui.button></form>
        @endif
    </div>
</div>

@foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
@foreach ($errors->get('agent') as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $e }}</div></div>@endforeach
@if ($app->last_error && empty($agentErrors))
    <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The last action failed') }}</strong><p class="small mono">{{ __($app->last_error) }}</p></div></div>
@endif
@if (in_array($state, ['failed', 'crashing'], true))
    <div class="alert alert-warn" role="status"><x-icon name="info" /><div>{{ __('The app stops right after starting. Check the log below: often a dependency is missing (install the dependencies) or the app does not listen on $PORT.') }}</div></div>
@endif
@if (session('webapp_new'))
    <x-ui.card :title="__('Next steps')">
        <ol class="wa-steps">
            <li>{!! __('Put your code in the folder :folder, with the file manager, SFTP or Git (deploy to the same folder).', ['folder' => '<span class="mono">'.e($app->website->domain.'/'.$app->app_root).'/</span>']) !!}</li>
            <li>{{ __('Press Install dependencies (npm ci or uv pip install, then the build command).') }}</li>
            <li>{{ __('The app starts by itself and restarts after a crash or reboot. Follow it in the log.') }}</li>
        </ol>
    </x-ui.card>
@endif

<section class="grid grid-stats">
    <div class="card stat"><span class="label">{{ __('Running since') }}</span>
        <strong class="value">{{ ($status['since'] ?? null) && $state === 'running' ? \Illuminate\Support\Carbon::createFromTimestamp($status['since'])->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) : '–' }}</strong>
        <span class="hint">{{ trans_choice(':count restart|:count restarts', $status['restarts'] ?? 0, ['count' => $status['restarts'] ?? 0]) }}@if ($status['pid'] ?? null) · PID {{ $status['pid'] }}@endif</span></div>
    <div class="card stat"><span class="label">{{ __('Memory') }}</span>
        <strong class="value">{{ $mem !== null ? \Illuminate\Support\Number::fileSize($mem, 0) : '–' }}</strong>
        @if ($memCap && $mem !== null)<div class="wa-meter" aria-hidden="true"><span style="width: {{ min(100, round($mem / ($memCap * 1048576) * 100)) }}%"></span></div>@endif
        <span class="hint">{{ $memCap ? __('of :limit (package limit)', ['limit' => \Illuminate\Support\Number::fileSize($memCap * 1048576)]) : __('No limit for this app') }}</span></div>
    <div class="card stat"><span class="label">{{ __('CPU time') }}</span>
        <strong class="value">{{ isset($status['cpu_seconds']) ? \Carbon\CarbonInterval::seconds((int) $status['cpu_seconds'])->cascade()->forHumans(short: true, parts: 2) : '–' }}</strong>
        <span class="hint">{{ $package?->webapp_cpu_percent ? __('Capped at :percent %', ['percent' => $package->webapp_cpu_percent]) : __('Fair share of the website\'s resources') }}</span></div>
    <div class="card stat"><span class="label">{{ __('Internal port') }}</span>
        <strong class="value mono">{{ $status['port'] ?? $app->port ?? '–' }}</strong>
        <span class="hint">{{ __('Only reachable through Nginx on 127.0.0.1') }}</span></div>
</section>

<div x-data="{
        lines: @js($logs), job: null, busy: false, follow: true, timer: null,
        async poll() {
            try {
                const res = await fetch(@js($r('poll')) + '?lines=200', {headers: {'Accept': 'application/json'}});
                if (!res.ok) return;
                const data = await res.json();
                this.lines = data.lines || this.lines;
                this.job = data.job;
                this.$nextTick(() => { if (this.follow && this.$refs.log) this.$refs.log.scrollTop = this.$refs.log.scrollHeight; });
            } catch (e) {}
        },
        cls(l) { return /\b(error|exception|traceback|fatal)\b/i.test(l) ? 'err' : (/\bwarn(ing)?\b/i.test(l) ? 'warn' : ''); },
        init() { this.$nextTick(() => { if (this.$refs.log) this.$refs.log.scrollTop = this.$refs.log.scrollHeight; });
                 @if ($app->install_job) this.poll(); @endif
                 this.timer = setInterval(() => this.poll(), 5000); }
     }" style="display:grid;gap:16px">
    <x-ui.card :title="__('Dependencies')">
        <p class="muted small">{{ $app->runtime === 'node'
            ? __('Runs npm ci (or npm install without a package-lock.json) in the app folder with :runtime, then the build command, and restarts the app.', ['runtime' => $app->runtimeLabel()])
            : __('Makes a virtual environment (.venv) with :runtime, installs requirements.txt or pyproject.toml with uv, runs the build command and restarts the app.', ['runtime' => $app->runtimeLabel()]) }}</p>
        <div class="actions">
            <form method="POST" action="{{ $r('install') }}">@csrf<x-ui.button icon="package" x-bind:disabled="job && job.status === 'running'">{{ __('Install dependencies') }}</x-ui.button></form>
            <span class="muted small" x-show="job && job.status === 'running'" x-cloak><span class="live"></span> {{ __('Installing…') }}</span>
            <span x-show="job && job.status === 'succeeded'" x-cloak><x-ui.pill status="ok">{{ __('Installed') }}</x-ui.pill></span>
            <span x-show="job && job.status === 'failed'" x-cloak><x-ui.pill status="bad"><span x-text="job?.error || @js(__('Failed'))"></span></x-ui.pill></span>
        </div>
        <pre class="log" x-show="job && job.log && job.log.length" x-cloak x-text="(job?.log || []).join('\n')"></pre>
    </x-ui.card>

    <x-ui.card :title="__('Log')" flush>
        <div style="padding:0 16px 16px;display:grid;gap:8px">
            <div class="actions" style="justify-content:space-between">
                <span class="muted small"><span class="live"></span> {{ __('Output of the app (stdout and stderr), refreshed every 5 seconds') }}</span>
                <label class="check" style="margin:0"><input type="checkbox" x-model="follow"><span>{{ __('Follow') }}</span></label>
            </div>
            <pre class="log wa-log" x-ref="log"><template x-for="(l, i) in lines" :key="i"><div :class="cls(l)"><span class="t" x-text="l.slice(0, 25)"></span><span x-text="l.slice(25).replace(/^whp-webapp-[a-f0-9]+\[\d+\]: /, ' ')"></span></div></template><div x-show="!lines.length" class="t">{{ __('No output yet.') }}</div></pre>
        </div>
    </x-ui.card>
</div>

<x-ui.card :title="__('Git deployments')">
    <p class="muted small">{{ __('Link a repository under Git and deploy it to the folder :folder. Before a new release goes live the dependencies and the build command run, after the switch the app restarts by itself.', ['folder' => $app->app_root]) }}</p>
    @if (\Illuminate\Support\Facades\Route::has($area.'.git.create') && ($area === 'admin' || config('git.customer_access')))
        <x-ui.button size="sm" icon="git" :href="route($area.'.git.create', ['website' => $app->website_id])">{{ __('Link a repository') }}</x-ui.button>
    @endif
</x-ui.card>

<x-ui.card :title="__('Settings')">
    <form method="POST" action="{{ $r('update') }}">
        @csrf @method('PUT')
        @include('webapps::partials.form')
        <div style="margin-top:16px"><button type="submit" class="btn btn-dark">{{ __('Save and restart') }}</button></div>
    </form>
</x-ui.card>

<x-ui.card :title="__('Remove app')">
    <p class="muted small">{{ __('Stops the app and removes it from the server and from Nginx. The code in the app folder stays.') }}</p>
    <form method="POST" action="{{ $r('destroy') }}" x-on:submit="{{ $confirm(__('Remove this app? The files in the app folder are kept.')) }}">
        @csrf @method('DELETE')
        <label class="check"><input type="checkbox" name="remove_deps" value="1"><span>{{ __('Also remove node_modules and .venv') }}</span></label>
        <x-ui.button variant="danger" icon="trash">{{ __('Remove') }}</x-ui.button></form>
</x-ui.card>
