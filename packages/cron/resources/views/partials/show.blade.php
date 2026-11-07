{{-- One cron job: status, run now, run history with output, settings. Shared by admin and client. --}}
@php
    $r = fn ($name, $extra = []) => route($area.'.cron.'.$name, [$job, ...$extra]);
    $confirm = fn (string $text) => 'if (!confirm('.\Illuminate\Support\Js::from($text).')) $event.preventDefault()';
    $tz = config('app.timezone');
    $running = (bool) ($status['running'] ?? false);
    $suspended = $job->website->status === 'suspended';
    $fmt = fn ($c) => $c?->timezone($tz)->isoFormat('dd D MMM HH:mm:ss');
@endphp
<div x-data="{
        running: @js($running), last: @js($runs->first()?->id), waiting: @js((bool) session('cron_started')), tries: 0,
        async poll() {
            try {
                const res = await fetch(@js($r('poll')), {headers: {'Accept': 'application/json'}});
                if (!res.ok) return;
                const d = await res.json();
                this.running = d.running;
                if (d.last_run !== this.last) { window.location.reload(); }
            } catch (e) {}
        },
        init() { setInterval(() => { if (this.running || (this.waiting && this.tries++ < 40)) this.poll(); }, 3000); }
     }" class="cj-page">
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.cron.index') }}">{{ __('Cron jobs') }}</a> / {{ $job->website->domain }}</div>
        <div class="actions"><span class="cj-name">@include('cron::partials.badge')<h1>{{ $job->name }}</h1></span>
            <span x-show="running" x-cloak><x-ui.pill status="info"><span class="live"></span> {{ __('Running') }}</x-ui.pill></span>
            <span x-show="!running">@include('cron::partials.state', ['st' => null])</span></div>
        <p class="sub">{{ $job->typeLabel() }} · <span class="mono">{{ $job->summary() }}</span></p>
    </div>
    <div class="actions">
        <form method="POST" action="{{ $r('run') }}" x-show="!running">@csrf<x-ui.button variant="primary" icon="play" :disabled="$suspended">{{ __('Run now') }}</x-ui.button></form>
        <form method="POST" action="{{ $r('stop') }}" x-show="running" x-cloak x-on:submit="{{ $confirm(__('Stop the running job?')) }}">@csrf<x-ui.button icon="pause">{{ __('Stop run') }}</x-ui.button></form>
        <form method="POST" action="{{ $r('toggle') }}">@csrf
            @if ($job->enabled)<x-ui.button icon="pause">{{ __('Pause') }}</x-ui.button>@else<x-ui.button icon="play">{{ __('Switch on') }}</x-ui.button>@endif</form>
    </div>
</div>

@if (session('status'))<div class="alert alert-ok" role="status"><x-icon name="check" /><div>{{ session('status') }}</div></div>@endif
@foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __($e) }}</div>@endforeach
@foreach ($errors->get('agent') as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $e }}</div></div>@endforeach
@if ($job->last_error)
    <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The server did not accept the last change') }}</strong><p class="small mono">{{ __($job->last_error) }}</p></div></div>
@endif
@if ($suspended)
    <div class="alert alert-warn" role="status"><x-icon name="info" /><div>{{ __('The website is suspended, so its cron jobs do not run.') }}</div></div>
@elseif (! $job->enabled)
    <div class="alert alert-warn" role="status"><x-icon name="info" /><div>{{ __('This cron job is paused. It does not run on its schedule until you switch it on; Run now still works.') }}</div></div>
@endif

<section class="grid grid-stats">
    <div class="card stat"><span class="label">{{ __('Schedule') }}</span>
        <strong class="value" style="font-size:20px">{{ $job->scheduleLabel() }}</strong>
        <span class="hint mono">{{ $job->schedule }} · {{ $tz }}</span></div>
    <div class="card stat"><span class="label">{{ __('Next run') }}</span>
        <strong class="value" style="font-size:20px">{{ $job->enabled && ! $suspended && ($status['next_run'] ?? null) ? \Illuminate\Support\Carbon::createFromTimestamp($status['next_run'])->timezone($tz)->isoFormat('dd D MMM HH:mm') : '–' }}</strong>
        <span class="hint">@if ($job->enabled && ! $suspended && count($next) > 1){{ __('Then') }} {{ collect($next)->slice(1)->map(fn ($d) => $d->isoFormat('dd D MMM HH:mm'))->implode(' · ') }}@else&nbsp;@endif</span></div>
    <div class="card stat"><span class="label">{{ __('Last run') }}</span>
        <strong class="value" style="font-size:20px">{{ $job->last_run_at?->diffForHumans() ?? '–' }}</strong>
        <span class="hint">{{ $job->last_run_at ? __('Took :d', ['d' => \Rhis\Cron\Models\CronRun::duration($job->last_duration_ms)]) : __('Not run yet') }}</span></div>
    <div class="card stat"><span class="label">{{ __('Failed in a row') }}</span>
        <strong @class(['value', 'is-bad' => $job->failures > 0]) style="font-size:20px">{{ $job->failures }}</strong>
        <span class="hint">{{ $job->notify_failure ? __('Mail to :to', ['to' => \Rhis\Cron\CronManager::recipient($job) ?? '–']) : __('No mail on failure') }}</span></div>
</section>

<x-ui.card :title="__('Recent runs')" flush class="cj-runs">
    @if ($runs->isEmpty())
        <x-ui.empty icon="clock">{{ __('No runs yet. Press Run now to try the job, or wait for its schedule.') }}</x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head><th>{{ __('Finished') }}</th><th>{{ __('Result') }}</th><th class="num">{{ __('Duration') }}</th><th>{{ __('Output') }}</th></x-slot:head>
        @foreach ($runs as $i => $run)
            @php [$pill, $label] = \Rhis\Cron\Models\CronRun::STATUSES[$run->status] ?? ['bad', 'Failed']; $out = trim((string) $run->output); @endphp
            <tr>
                <td class="nowrap">{{ $fmt($run->finished_at) }}</td>
                <td><x-ui.pill :status="$pill">{{ __($label) }}</x-ui.pill>@if ($run->reason())<div class="muted small">{{ $run->reason() }}</div>@endif</td>
                <td class="num">{{ \Rhis\Cron\Models\CronRun::duration($run->duration_ms) }}</td>
                <td>
                    @if ($out === '')<span class="muted small">{{ __('No output') }}</span>
                    @else
                        <details @if ($i === 0 && $run->failed()) open @endif>
                            <summary>{{ trans_choice(':count line|:count lines', substr_count($out, "\n") + 1) }}@if ($run->truncated) · {{ __('last 64 KB') }}@endif</summary>
                            <pre class="log cj-out">{{ \Illuminate\Support\Str::limit($out, 8000) }}</pre>
                            @if (strlen($out) > 8000)<a class="small" href="{{ $r('output', [$run->id]) }}" target="_blank" rel="noopener">{{ __('Full output') }}</a>@endif
                        </details>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>

<x-ui.card :title="__('Settings')">
    <form method="POST" action="{{ $r('update') }}">
        @csrf @method('PUT')
        @include('cron::partials.form')
        <div style="margin-top:16px"><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
    </form>
</x-ui.card>

<x-ui.card :title="__('Remove cron job')">
    <p class="muted small">{{ __('Removes the job and its run history from the server. Your files stay.') }}</p>
    <form method="POST" action="{{ $r('destroy') }}" x-on:submit="{{ $confirm(__('Remove this cron job?')) }}">
        @csrf @method('DELETE')
        <x-ui.button variant="danger" icon="trash">{{ __('Remove') }}</x-ui.button></form>
</x-ui.card>
</div>
