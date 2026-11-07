@php
    $statusLabels = ['queued' => __('Queued'), 'preparing' => __('Preparing'), 'copying' => __('Copying'), 'copied' => __('Copied'),
        'syncing' => __('Syncing'), 'live' => __('DNS switched'), 'done' => __('Finished'), 'failed' => __('Failed')];
    $current = $steps[$step];
    $icons = ['website' => 'globe', 'databases' => 'database', 'mail' => 'mail', 'ssl' => 'lock', 'dns' => 'cloud', 'final' => 'check'];
    $stateLabels = ['ok' => __('Done'), 'warn' => __('Needs attention'), 'bad' => __('Problem'), 'todo' => __('To do'), 'skip' => __('Not needed'), 'blocked' => __('Not ready')];
    $keys = array_keys($steps);
    $pos = array_search($step, $keys, true);
    $prev = $keys[$pos - 1] ?? null;
    $confirmedAt = $item->wizardValue("confirmed.$step");
@endphp
<x-layouts.admin :title="__('Migration wizard').' · '.$item->domain">
    @if ($item->isActive())<meta http-equiv="refresh" content="10">@endif
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.migration.index') }}">{{ __('Migration') }}</a> / <a href="{{ route('admin.migration.sources.show', $item->source) }}">{{ $item->source->name }}</a> / <a href="{{ route('admin.migration.items.show', $item) }}">{{ $item->domain }}</a></div>
            <div class="actions"><h1>{{ __('Migration wizard') }}</h1><x-ui.pill :status="$item->statusPill()">{{ $statusLabels[$item->status] ?? $item->status }}</x-ui.pill></div>
            <p class="sub"><span class="mono">{{ $item->domain }}</span>@if ($item->customer) · {{ $item->customer->displayName() }}@endif · {{ __('Walk through every part of this domain. Nothing changes in DNS until the final check.') }}</p>
        </div>
        <div class="actions">
            @if ($item->canSync() && $item->status !== 'failed')
                <form method="POST" action="{{ route('admin.migration.items.sync', $item) }}">@csrf<input type="hidden" name="kind" value="sync"><button class="btn" type="submit"><x-icon name="refresh" />{{ __('Sync again') }}</button></form>
            @endif
            <a class="btn" href="{{ route('admin.migration.items.show', $item) }}"><x-icon name="list" />{{ __('Progress and log') }}</a>
        </div>
    </div>

    @foreach (['wizard', 'confirm_domain', 'understood', 'keys', 'provider'] as $f)
        @error($f)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @endforeach
    @if ($item->isActive())<div class="alert alert-info"><x-icon name="refresh" />{{ __('A copy is running. The checks update when it is finished.') }}</div>@endif

    <div class="wz">
        <nav class="wz-nav" aria-label="{{ __('Steps') }}">
            <ol>
                @foreach ($steps as $k => $s)
                    <li @class(['is-current' => $k === $step, 'is-'.$s['state']])>
                        <a href="{{ route('admin.migration.wizard.show', [$item, $k]) }}" @if ($k === $step) aria-current="step" @endif>
                            <span class="wz-num">@if (in_array($s['state'], ['ok', 'skip'], true))✓@else{{ $loop->iteration }}@endif</span>
                            <span class="wz-label"><strong>{{ $s['title'] }}</strong><small>{{ $stateLabels[$s['state']] ?? '' }}</small></span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </nav>

        <div class="wz-main">
            <x-ui.card>
                <div class="wz-head">
                    <span class="wz-icon"><x-icon :name="$icons[$step]" /></span>
                    <div><p class="eyebrow">{{ __('Step :n of :total', ['n' => $pos + 1, 'total' => count($steps)]) }}</p><h2>{{ $current['title'] }}</h2></div>
                </div>
                <ul class="checks">
                    @foreach ($current['checks'] as [$level, $text])
                        <li class="is-{{ $level }}"><x-icon :name="$level === 'ok' ? 'check' : 'info'" />{{ $text }}</li>
                    @endforeach
                </ul>
            </x-ui.card>

            @include('migration::admin.wizard.step-'.$step)

            @if ($step !== 'final')
                <div class="wz-foot">
                    @if ($prev)<a class="btn" href="{{ route('admin.migration.wizard.show', [$item, $prev]) }}">{{ __('Back') }}</a>@else<span></span>@endif
                    @if (! $current['applicable'])
                        <a class="btn btn-primary" href="{{ route('admin.migration.wizard.show', [$item, $keys[$pos + 1]]) }}">{{ __('Next') }}</a>
                    @elseif ($confirmedAt)
                        <div class="actions">
                            <span class="small muted">{{ __('Confirmed :time by :user', ['time' => \Illuminate\Support\Carbon::parse($confirmedAt['at'])->diffForHumans(), 'user' => $confirmedAt['by'] ?? '–']) }}</span>
                            <form method="POST" action="{{ route('admin.migration.wizard.confirm', [$item, $step]) }}">@csrf<input type="hidden" name="undo" value="1"><button class="btn btn-sm" type="submit">{{ __('Undo') }}</button></form>
                            <a class="btn btn-primary" href="{{ route('admin.migration.wizard.show', [$item, $keys[$pos + 1]]) }}">{{ __('Next') }}</a>
                        </div>
                    @elseif ($step !== 'dns')
                        <form method="POST" action="{{ route('admin.migration.wizard.confirm', [$item, $step]) }}">@csrf
                            <button class="btn btn-primary" type="submit" @disabled($current['state'] === 'bad')><x-icon name="check" />{{ __('Looks good, next step') }}</button></form>
                    @endif
                </div>
            @endif
        </div>
    </div>
    @include('migration::admin.styles')
    @include('migration::admin.wizard.styles')
</x-layouts.admin>
