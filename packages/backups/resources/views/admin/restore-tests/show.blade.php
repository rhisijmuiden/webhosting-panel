@php
    use Rhis\Backups\RestoreTests\Labels;
    $cov = $run->coverage();
    [$checksOk, $checksAll] = $run->checksPassed();
    $tone = match ($run->status) { 'ok' => 'ok', 'warning', 'busy', 'running' => 'warn', default => 'bad' };
    $sorted = collect($run->items())->sortBy(fn ($i) => ['failed' => 0, 'warning' => 1, 'skipped' => 2][$i['status']] ?? 3)->values();
@endphp
<x-layouts.admin :title="__('Restore test')">
    @if ($run->isRunning())<meta http-equiv="refresh" content="5">@endif
    @include('backups::partials.restore-test-styles')
    <div class="page-head">
        <div><h1>{{ __('Restore test of :date', ['date' => $run->started_at?->isoFormat('lll')]) }}</h1>
            <p class="sub">{{ $run->trigger === 'manual' ? __('Started by :name', ['name' => $run->user?->name ?? __('Administrator')]) : __('Scheduled') }}{{ $run->target ? ' · '.$run->target : ' · '.__('All locations') }}</p></div>
        <div class="actions"><x-ui.button :href="route('admin.backups.restore-tests.index')" icon="backup">{{ __('All restore tests') }}</x-ui.button></div>
    </div>
    @include('backups::partials.admin-tabs')
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif

    @if ($run->isRunning())
        <div class="alert alert-info" role="status"><x-icon name="refresh" /><div><strong>{{ __('Restoring and checking…') }}</strong>
            <p class="small">{{ __('Started :time. This page refreshes by itself.', ['time' => $run->started_at->diffForHumans()]) }}</p></div></div>
    @elseif ($run->error)
        <div class="alert {{ $run->status === 'busy' ? 'alert-warn' : 'alert-bad' }}" role="alert"><x-icon name="info" />{{ $run->error }}</div>
    @endif

    @unless ($run->isRunning() || in_array($run->status, ['error', 'busy'], true))
    <section class="rt-hero" aria-label="{{ __('Status') }}">
        <div class="rt-state rt-{{ $tone }}">
            <x-ui.ring :value="$checksAll ? 100 * $checksOk / $checksAll : 0" :tone="$tone" :size="112">
                <strong>{{ $checksOk }}</strong><small class="muted">/ {{ $checksAll }}</small>
            </x-ui.ring>
            <div>
                <div class="eyebrow">{{ __('Result') }}<x-ui.pill :status="$run->tone()">{{ $run->statusLabel() }}</x-ui.pill></div>
                <h2>{{ match ($run->status) { 'ok' => __('Every tested backup can be restored'), 'warning' => __('Restorable, with warnings'), default => __('Some backups cannot be restored') } }}</h2>
                <p>{{ __(':n restore points restored in isolation and checked; :ok of :all checks passed. Everything was removed again afterwards.', ['n' => $run->tested, 'ok' => $checksOk, 'all' => $checksAll]) }}</p>
                <div class="rt-meta">
                    @if ($run->durationForHumans())<span><x-icon name="refresh" />{{ $run->durationForHumans() }}</span>@endif
                    @if ($run->notified_at)<span><x-icon name="mail" />{{ __('Mailed :time', ['time' => $run->notified_at->diffForHumans()]) }}</span>@endif
                </div>
            </div>
        </div>
        <div class="rt-tiles">
            <div class="rt-tile"><span class="ic {{ $cov['missing'] ? 'bad' : 'ok' }}"><x-icon name="shield" /></span><span>{{ __('Without any backup') }}</span><strong>{{ count($cov['missing']) }}</strong></div>
            <div class="rt-tile"><span class="ic {{ $cov['stale'] ? 'warn' : 'ok' }}"><x-icon name="activity" /></span><span>{{ __('Backup too old') }}</span><strong>{{ count($cov['stale']) }}</strong></div>
            <div class="rt-tile"><span class="ic"><x-icon name="backup" /></span><span>{{ __('Restored and checked') }}</span><strong>{{ Labels::bytes((int) collect($run->items())->sum('bytes')) }}</strong></div>
        </div>
    </section>
    @endunless

    @foreach ($run->report['targets'] ?? [] as $t)
        @if (! empty($t['error']))<div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ $t['target'] }}</strong> · {{ $t['error'] }}</div></div>@endif
    @endforeach

    @if ($cov['missing'] || $cov['stale'])
        <x-ui.card :title="__('Not (recently) backed up')">
            <ul class="rt-list">
                @foreach ($cov['missing'] as $m)<li><span>{{ Labels::scope($m['scope']) }} <span class="small muted">· {{ $m['target'] }}</span></span><x-ui.pill status="bad">{{ __('No backup at all') }}</x-ui.pill></li>@endforeach
                @foreach ($cov['stale'] as $s)<li><span>{{ Labels::scope($s['scope']) }} <span class="small muted">· {{ $s['target'] }}</span></span><x-ui.pill status="warn">{{ __('Newest backup :h hours old', ['h' => $s['age_hours']]) }}</x-ui.pill></li>@endforeach
            </ul>
        </x-ui.card>
    @endif

    @if ($sorted->isNotEmpty())
        <h2 class="section-title" style="margin:20px 0 12px">{{ __('Tested restore points') }}</h2>
        @foreach ($sorted as $item)
            <article class="rt-item">
                <header>
                    <span class="who"><x-icon :name="Labels::icon($item['scope'])" />{{ Labels::scope($item['scope']) }}</span>
                    <x-ui.pill :status="($item['kind'] ?? '') === 'full' ? 'info' : 'neutral'" :dot="false">{{ Labels::kind($item['kind'] ?? null) }}</x-ui.pill>
                    <span class="small">{{ \Illuminate\Support\Carbon::parse($item['time'])->isoFormat('lll') }} · <span class="mono">{{ $item['short_id'] }}</span> · {{ $item['target'] }}</span>
                    <span class="small">{{ isset($item['files']) ? __(':n files', ['n' => number_format((int) $item['files'], 0, ',', '.')]).' · ' : '' }}{{ Labels::bytes(isset($item['bytes']) ? (int) $item['bytes'] : null) }} · {{ round(($item['duration_ms'] ?? 0) / 1000, 1) }} s</span>
                    <x-ui.pill :status="Labels::tone($item['status'])">{{ Labels::itemStatus($item['status']) }}</x-ui.pill>
                </header>
                <ul class="checks">
                    @foreach ($item['checks'] ?? [] as $c)
                        <li class="{{ ['ok' => 'is-ok', 'warning' => 'is-warn'][$c['result']] ?? 'is-bad' }}">
                            <x-icon :name="$c['result'] === 'ok' ? 'check' : 'info'" />{{ Labels::check($c['check']) }}<small>{{ $c['detail'] }}</small>
                        </li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    @endif
</x-layouts.admin>
