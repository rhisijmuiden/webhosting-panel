{{-- One deployment with its live log (refreshes itself while it runs). --}}
@php
    $c = $deployment->result['commit'] ?? null;
    $states = ['succeeded' => __('Succeeded'), 'failed' => __('Failed'), 'running' => __('Running'), 'queued' => __('Waiting')];
    $pills = ['succeeded' => 'ok', 'failed' => 'bad', 'running' => 'info', 'queued' => 'neutral'];
@endphp
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.git.index') }}">{{ __('Git') }}</a> / <a href="{{ route($area.'.git.show', $repo) }}">{{ $repo->name }}</a> / {{ __('Deployment') }} #{{ $deployment->id }}</div>
        <div class="actions"><h1>{{ __('Deployment') }} #{{ $deployment->id }}</h1>
            <x-ui.pill :status="$deployment->pill()">{{ $states[$deployment->status] ?? $deployment->status }}</x-ui.pill></div>
        <p class="sub">{{ $deployment->created_at->isoFormat('LLL') }} · {{ $repo->website->domain }}/<span class="mono">{{ $repo->deploy_path }}</span> · <span class="mono">{{ $repo->branch }}</span>
            @if ($deployment->user) · {{ $deployment->user }}@endif</p>
    </div>
    <div class="actions"><x-ui.button :href="route($area.'.git.show', $repo)">{{ __('Back to repository') }}</x-ui.button></div>
</div>

@if ($deployment->status === 'failed')
    <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The deployment failed') }}</strong><p class="small mono">{{ __($deployment->error) }}</p>
        @if ($repo->mode === 'releases')<p class="small">{{ __('Visitors did not notice: the website still runs the previous release.') }}</p>@endif</div></div>
@elseif ($deployment->status === 'succeeded')
    <div class="alert alert-ok" role="status"><x-icon name="check" />{{ __('Live in :s seconds.', ['s' => number_format((float) $deployment->seconds(), 1)]) }}</div>
@elseif ($deployment->status === 'queued')
    <div class="alert alert-info" role="status"><x-icon name="refresh" />{{ __('Waiting for the running deployment to finish.') }}</div>
@endif

@if ($c)
<x-ui.card :title="__('Commit')">
    <dl class="kv">
        <dt>{{ __('Commit') }}</dt><dd class="mono">@if ($url = $repo->commitUrl($c['commit'] ?? null))<a href="{{ $url }}" target="_blank" rel="noopener">{{ $c['commit'] }}</a>@else{{ $c['commit'] ?? '' }}@endif</dd>
        <dt>{{ __('Message') }}</dt><dd>{{ $c['message'] ?? '' }}</dd>
        <dt>{{ __('Author') }}</dt><dd>{{ $c['author'] ?? '' }}</dd>
        @if ($deployment->result['release'] ?? null)<dt>{{ __('Release') }}</dt><dd class="mono">{{ $deployment->result['release'] }}</dd>@endif
        @foreach ($deployment->result['steps'] ?? [] as $s)
            <dt>{{ __('Step') }}</dt><dd>{{ $s['type'] }} <x-ui.pill :status="['ok' => 'ok', 'failed' => 'bad'][$s['status']] ?? 'neutral'" :dot="false">{{ __(ucfirst($s['status'])) }}</x-ui.pill>
                @isset($s['seconds'])<span class="muted small">{{ $s['seconds'] }} s</span>@endisset</dd>
        @endforeach
    </dl>
</x-ui.card>
@endif

<x-ui.card :title="__('Log')">
    <div x-data="{ log: @js($deployment->log ?? []), done: @js($deployment->isFinished()),
            init() { if (!this.done) this.poll() },
            poll() { setTimeout(async () => {
                const res = await fetch(@js(route($area.'.git.deployments.show', [$repo, $deployment])), { headers: { Accept: 'application/json' } });
                if (res.ok) { const d = await res.json(); this.log = d.log; this.$nextTick(() => this.$refs.log.scrollTop = this.$refs.log.scrollHeight);
                    if (d.finished) { window.location.reload(); return } }
                this.poll() }, 2000) } }">
        <div class="log log-tall" x-ref="log" x-text="log.join('\n') || @js(__('Starting…'))">{{ implode("\n", $deployment->log ?? []) }}</div>
    </div>
</x-ui.card>
