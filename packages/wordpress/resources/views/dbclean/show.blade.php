{{-- Database cleanup of one WordPress install: preview, choose, clean. Shared by admin and client. --}}
@php
    $dbcleanItems = \Rhis\WordPress\DbCleanup\Cleaner::labels();
    $num = fn ($n) => number_format((int) $n, 0, app()->getLocale() === 'nl' ? ',' : '.', app()->getLocale() === 'nl' ? '.' : ',');
    $fs = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1);
    $items = $preview['items'] ?? [];
    $chosen = old('items', \Rhis\WordPress\DbCleanup\Cleaner::DEFAULT_ITEMS);
    $estimate = collect($items)->only(array_diff($chosen, ['all_transients']))->sum('bytes')
        + (in_array('all_transients', $chosen, true) ? ($items['all_transients']['bytes'] ?? 0) - (in_array('expired_transients', $chosen, true) ? ($items['expired_transients']['bytes'] ?? 0) : 0) : 0);
    $running = $history->firstWhere('status', 'running');
@endphp
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / <a href="{{ route($area.'.wordpress.show', $install) }}">{{ $install->label() }}</a> / {{ __('Database cleanup') }}</div>
        <h1>{{ __('Database cleanup') }}</h1>
        <p class="sub">{{ __('See what can go, choose, and clean up. Nothing is removed before you press the button.') }}</p>
    </div>
    <div class="actions">
        <x-ui.button :href="route($area.'.wordpress.dbclean.show', [$install, 'keep' => $keep])" icon="refresh">{{ __('Check again') }}</x-ui.button>
    </div>
</div>

@foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
@error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
@error('items')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
@if ($running)
    <div class="alert alert-warn" role="status"><x-icon name="info" /><div><strong>{{ __('A cleanup is running.') }}</strong>
        <a href="{{ route($area.'.wordpress.dbclean.status', $running) }}">{{ __('Follow it') }}</a></div></div>
@endif

@if ($preview)
    <section class="grid grid-stats">
        <div class="card stat"><span class="label">{{ __('Database size') }}</span><strong class="value" style="font-size:24px">{{ $fs($preview['db_size'] ?? 0) }}</strong>
            <span class="hint">{{ trans_choice(':count table|:count tables', $preview['tables'] ?? 0) }} · {{ __('prefix') }} <span class="mono">{{ $preview['prefix'] ?? '' }}</span></span></div>
        <div class="card stat"><span class="label">{{ __('Can be removed') }}</span><strong class="value" style="font-size:24px">{{ $num(collect($items)->except(['all_transients', 'optimize'])->sum('count')) }}</strong>
            <span class="hint">{{ __('rows, without all transients') }}</span></div>
        <div class="card stat"><span class="label">{{ __('Free space in tables') }}</span><strong class="value" style="font-size:24px">{{ $fs($items['optimize']['bytes'] ?? 0) }}</strong>
            <span class="hint">{{ __('given back by optimizing') }}</span></div>
    </section>

    @if ($preview['multisite'] ?? false)
        <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('This is a multisite. Only the content of the main site is cleaned up.') }}</div>
    @endif

    <x-ui.card :title="__('What will be removed')">
        <form method="GET" action="{{ route($area.'.wordpress.dbclean.show', $install) }}" class="actions" style="margin-bottom:12px">
            <label for="keep" class="small strong">{{ __('Keep the newest revisions per post') }}</label>
            <select id="keep" name="keep" class="select" style="width:auto" onchange="this.form.submit()">
                @foreach (\Rhis\WordPress\DbCleanup\Cleaner::KEEP_REVISIONS as $n)
                    <option value="{{ $n }}" @selected($keep === $n)>{{ $n === 0 ? __('none') : $n }}</option>
                @endforeach
            </select>
            <noscript><button class="btn btn-sm" type="submit">{{ __('Apply') }}</button></noscript>
        </form>

        <form method="POST" action="{{ route($area.'.wordpress.dbclean.run', $install) }}" class="form"
              onsubmit="return confirm(@js(__('Clean up the database now? A snapshot is made first, so you can put it back.')))">
            @csrf
            <input type="hidden" name="keep_revisions" value="{{ $keep }}">
            <x-ui.table>
                <x-slot:head><th style="width:36px"><span class="sr-only">{{ __('Select') }}</span></th><th>{{ __('What') }}</th><th class="num">{{ __('Found') }}</th><th class="num">{{ __('About') }}</th></x-slot:head>
                @foreach ($dbcleanItems as $key => [$label, $hint])
                    @php $it = $items[$key] ?? ['count' => 0, 'bytes' => 0, 'examples' => []]; @endphp
                    <tr>
                        <td><input type="checkbox" name="items[]" value="{{ $key }}" id="dbc-{{ $key }}" @checked(in_array($key, $chosen, true)) aria-describedby="dbc-{{ $key }}-hint"></td>
                        <td>
                            <label for="dbc-{{ $key }}" class="strong">{{ $label }}</label>
                            @if ($key === 'all_transients')<x-ui.pill status="neutral">{{ __('optional') }}</x-ui.pill>@endif
                            <div class="muted small" id="dbc-{{ $key }}-hint">{{ $hint }}
                                @if ($key === 'revisions' && $keep > 0) {{ trans_choice('The newest :count revision of every post is kept.|The newest :count revisions of every post are kept.', $keep) }}@endif
                                @if ($key === 'orphaned_meta' && $it['count']) ({{ __(':posts of posts, :comments of comments', ['posts' => $it['post_meta'] ?? 0, 'comments' => $it['comment_meta'] ?? 0]) }})@endif
                            </div>
                            @if (! empty($it['examples']))
                                <details class="small"><summary class="muted">{{ __('Examples') }}</summary>
                                    <ul class="small" style="margin:4px 0 0">
                                        @foreach ($it['examples'] as $ex)
                                            <li>{{ $ex['label'] }}@if (isset($ex['count'])) <span class="muted">· {{ trans_choice(':count revision|:count revisions', $ex['count']) }}</span>@endif
                                                @if (isset($ex['bytes'])) <span class="muted">· {{ $fs($ex['bytes']) }}</span>@endif
                                                @if (isset($ex['type'])) <span class="muted">· {{ $ex['type'] }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </td>
                        <td class="num">@if ($key === 'optimize'){{ trans_choice(':count table|:count tables', $it['count']) }}@else{{ $num($it['count']) }}@endif</td>
                        <td class="num muted">{{ $it['bytes'] ? $fs($it['bytes']) : '–' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <p class="small muted">{{ __('Sizes are estimates. Removed rows leave free space in the tables; optimizing gives it back to the disk.') }}</p>
            <div class="actions">
                <button type="submit" class="btn btn-dark" @disabled($running)><x-icon name="database" />{{ __('Clean up now') }}</button>
                <span class="small muted">{{ __('About :size of data in the chosen rows.', ['size' => $fs($estimate)]) }}</span>
            </div>
            <div class="alert alert-ok" role="note" style="margin-top:8px"><x-icon name="backup" /><div>{{ __('First a snapshot of the database is made. If the website stops working after the cleanup, the database is put back automatically. You can also restore the snapshot yourself on the WordPress page.') }}</div></div>
        </form>
    </x-ui.card>
@endif

<x-ui.card :title="__('Earlier cleanups')" flush>
    @if ($history->isEmpty())
        <x-ui.empty icon="database">{{ __('This database has not been cleaned up from the panel yet.') }}</x-ui.empty>
    @else
        <x-ui.table>
            <x-slot:head><th>{{ __('When') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Removed') }}</th><th class="num">{{ __('Smaller') }}</th><th>{{ __('By') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($history as $c)
                <tr>
                    <td>{{ $c->created_at->isoFormat('LLL') }}</td>
                    <td>@include('wordpress::dbclean.pill', ['cleanup' => $c])</td>
                    <td class="num">{{ $c->status === 'succeeded' ? $num($c->removedTotal()) : '–' }}</td>
                    <td class="num">{{ $c->freed() !== null ? $fs($c->freed()) : '–' }}</td>
                    <td class="small muted">{{ $c->user?->name ?? __('Panel') }}</td>
                    <td class="num"><a class="btn btn-sm" href="{{ route($area.'.wordpress.dbclean.status', $c) }}">{{ __('Details') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
</x-ui.card>
