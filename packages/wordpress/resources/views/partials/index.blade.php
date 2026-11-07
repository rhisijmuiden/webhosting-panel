{{-- List of WordPress installs, shared by the admin and client screens. --}}
<div class="page-head">
    <div>
        <h1>{{ __('WordPress') }}</h1>
        <p class="sub">{{ __('Update WordPress, plugins and themes in one go, let the server run WP-cron, manage administrators and log in with one click.') }}</p>
    </div>
    <div class="actions">
        @if ($area === 'admin')
            <form method="POST" action="{{ route('admin.wordpress.scan') }}">@csrf<x-ui.button icon="refresh">{{ __('Check all sites now') }}</x-ui.button></form>
            @if (Route::has('admin.wordpress.bulk.index'))<x-ui.button icon="bolt" :href="route('admin.wordpress.bulk.index')">{{ __('Bulk actions') }}</x-ui.button>@endif
        @endif
        @if ($summary['outdated_installs'] > 0)
            <form method="POST" action="{{ route($area.'.wordpress.update-outdated') }}" onsubmit="return confirm(@js(__('Update everything that is outdated? A snapshot is taken first and a site that breaks is rolled back.')))">
                @csrf<x-ui.button variant="primary" icon="refresh">{{ __('Update all outdated (:n)', ['n' => $summary['outdated_installs']]) }}</x-ui.button>
            </form>
        @endif
    </div>
</div>

<section class="grid grid-stats">
    <div class="card stat"><span class="label">{{ __('WordPress sites') }}</span><strong class="value">{{ $summary['installs'] }}</strong>
        <span class="hint">{{ $summary['checked_at'] ? __('Checked :time', ['time' => \Illuminate\Support\Carbon::parse($summary['checked_at'])->diffForHumans()]) : __('Not checked yet') }}</span></div>
    <div class="card stat"><span class="label">{{ __('Core updates') }}</span><strong class="value">{{ $summary['core'] }}</strong></div>
    <div class="card stat"><span class="label">{{ __('Outdated plugins') }}</span><strong class="value">{{ $summary['plugins'] }}</strong></div>
    <div class="card stat"><span class="label">{{ __('Outdated themes') }}</span><strong class="value">{{ $summary['themes'] }}</strong></div>
</section>

<x-ui.card :title="__('Sites')" flush>
    <x-slot:actions>
        <a href="{{ route($area.'.wordpress.index') }}" @class(['btn', 'btn-sm', 'btn-dark' => $filter === 'all']) @if ($filter === 'all') aria-current="page" @endif>{{ __('All') }}</a>
        <a href="{{ route($area.'.wordpress.index', ['filter' => 'outdated']) }}" @class(['btn', 'btn-sm', 'btn-dark' => $filter === 'outdated']) @if ($filter === 'outdated') aria-current="page" @endif>{{ __('Outdated') }}</a>
    </x-slot:actions>
    @if ($installs->isEmpty())
        <x-ui.empty icon="wordpress">{{ $filter === 'outdated' ? __('Everything is up to date.') : __('No WordPress found yet. Sites are checked every night.') }}</x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head>
            <th>{{ __('Website') }}</th>
            @if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif
            <th>{{ __('Version') }}</th><th>{{ __('Updates') }}</th><th>WP-cron</th><th>{{ __('Last update') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th>
        </x-slot:head>
        @foreach ($installs as $i)
            <tr>
                <td><a class="strong" href="{{ route($area.'.wordpress.show', $i) }}">{{ $i->label() }}</a></td>
                @if ($area === 'admin')<td>{{ $i->website->customer?->displayName() }}</td>@endif
                <td>{{ $i->version ?? '–' }} @if ($i->core_update)<x-ui.pill status="warn">{{ __(':v available', ['v' => $i->core_update]) }}</x-ui.pill>@endif</td>
                <td>
                    @if ($i->isOutdated())
                        <x-ui.pill status="warn">{{ trans_choice(':count update|:count updates', $i->outdatedCount()) }}</x-ui.pill>
                        <span class="muted small">{{ __(':p plugins, :t themes', ['p' => $i->outdated_plugins, 't' => $i->outdated_themes]) }}</span>
                    @elseif ($i->checked_at)
                        <x-ui.pill status="ok">{{ __('Up to date') }}</x-ui.pill>
                    @else
                        <x-ui.pill>{{ __('Not checked yet') }}</x-ui.pill>
                    @endif
                </td>
                <td>{{ $i->cron_mode === 'system' ? __('Server, every :n min', ['n' => $i->cron_interval ?? 5]) : __('WordPress') }}</td>
                <td class="small">
                    @if ($i->last_update_at)
                        <x-ui.pill :status="$i->last_update_status === 'succeeded' ? 'ok' : 'bad'">{{ __(ucfirst(str_replace('_', ' ', $i->last_update_status))) }}</x-ui.pill>
                        <span class="muted">{{ $i->last_update_at->diffForHumans() }}</span>
                    @else – @endif
                </td>
                <td class="num">
                    <div class="actions" style="justify-content:flex-end">
                        <form method="POST" action="{{ route($area.'.wordpress.login', $i) }}" target="_blank">@csrf<button class="btn btn-sm" type="submit" title="{{ __('Log in to wp-admin') }}"><x-icon name="external" />{{ __('WP admin') }}</button></form>
                        @if ($i->isOutdated())
                            <form method="POST" action="{{ route($area.'.wordpress.update', $i) }}">@csrf<input type="hidden" name="scope" value="all"><button class="btn btn-sm btn-dark" type="submit">{{ __('Update') }}</button></form>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>

@if (! empty($jobs))
<x-ui.card :title="__('Recent updates')" flush>
    <x-ui.table>
        <x-slot:head><th>{{ __('Started') }}</th><th>{{ __('What') }}</th><th>{{ __('By') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
        @foreach ($jobs as $j)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($j['started_at'])->diffForHumans() }}</td>
                <td>{{ ['update' => __('Update'), 'update_many' => __('Update of :n sites', ['n' => count($j['installs'])]), 'restore' => __('Restore')][$j['kind']] ?? $j['kind'] }}</td>
                <td>{{ $j['user'] ?? __('Scheduler') }}</td>
                <td class="num"><a class="btn btn-sm" href="{{ route($area.'.wordpress.jobs.show', $j['id']) }}">{{ __('Details') }}</a></td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
@endif
