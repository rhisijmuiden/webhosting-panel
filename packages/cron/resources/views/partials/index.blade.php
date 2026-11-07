{{-- Cron jobs overview, shared by the admin and client screens. --}}
@php $tz = config('app.timezone'); @endphp
<div class="page-head">
    <div>
        <h1>{{ __('Cron jobs') }}</h1>
        <p class="sub">{{ __('Run a command, a PHP script or a URL on a schedule, for example the Laravel scheduler or a nightly import. Jobs run as the website\'s own user, within its limits, and every run is logged.') }}</p>
    </div>
    <div class="actions">
        @if ($canCreate)<x-ui.button variant="primary" icon="plus" :href="route($area.'.cron.create', $filter ? ['website' => $filter] : [])">{{ __('New cron job') }}</x-ui.button>@endif
    </div>
</div>

@if (session('status'))<div class="alert alert-ok" role="status"><x-icon name="check" /><div>{{ session('status') }}</div></div>@endif

<x-ui.card :title="__('Cron jobs')" flush>
    @if ($websites->count() > 1 || $filter)
        <x-slot:actions>
            <form method="GET" class="cj-filter">
                <label class="sr-only" for="cj-website">{{ __('Website') }}</label>
                <select id="cj-website" name="website" class="select" onchange="this.form.submit()">
                    <option value="">{{ __('All websites') }}</option>
                    @foreach ($websites as $w)<option value="{{ $w->id }}" @selected($filter === $w->id)>{{ $w->domain }}</option>@endforeach
                </select>
                <noscript><x-ui.button size="sm">{{ __('Show') }}</x-ui.button></noscript>
            </form>
        </x-slot:actions>
    @endif
    @if ($list->isEmpty())
        <x-ui.empty icon="clock">
            {{ __('No cron jobs yet.') }}
            @if ($canCreate) <a href="{{ route($area.'.cron.create', $filter ? ['website' => $filter] : []) }}">{{ __('Add the first one') }}</a>@endif
        </x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head>
            <th>{{ __('Cron job') }}</th><th>{{ __('Website') }}</th>
            @if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif
            <th>{{ __('Schedule') }}</th><th>{{ __('Last run') }}</th><th>{{ __('Next run') }}</th><th class="num"><span class="sr-only">{{ __('Actions') }}</span></th>
        </x-slot:head>
        @foreach ($list as $j)
            @php $st = $statuses[$j->uid] ?? null; @endphp
            <tr>
                <td><div class="cj-name">@include('cron::partials.badge', ['job' => $j])
                    <div><a class="strong" href="{{ route($area.'.cron.show', $j) }}">{{ $j->name }}</a>
                    <span class="cj-cmd" title="{{ $j->summary() }}">{{ $j->summary() }}</span></div></div></td>
                <td>{{ $j->website->domain }}</td>
                @if ($area === 'admin')<td>{{ $j->website->customer?->name ?? '–' }}</td>@endif
                <td><div class="cj-when"><span>{{ $j->scheduleLabel() }}</span>@if (\Rhis\Cron\Schedule::describe($j->schedule))<span class="mono">{{ $j->schedule }}</span>@endif</div></td>
                <td><div class="cj-when">@include('cron::partials.state', ['job' => $j, 'st' => $st])
                    @if ($j->last_run_at)<span class="muted small" title="{{ $j->last_run_at->timezone($tz)->isoFormat('D MMM YYYY HH:mm:ss') }}">{{ $j->last_run_at->diffForHumans() }} · {{ \Rhis\Cron\Models\CronRun::duration($j->last_duration_ms) }}</span>@endif</div></td>
                <td>{{ $j->enabled && ($st['next_run'] ?? null) ? \Illuminate\Support\Carbon::createFromTimestamp($st['next_run'])->timezone($tz)->isoFormat('dd D MMM HH:mm') : '–' }}</td>
                <td class="num">
                    <form method="POST" action="{{ route($area.'.cron.run', $j) }}">@csrf
                        <x-ui.button size="sm" icon="play" :disabled="($st['running'] ?? false) || $j->website->status === 'suspended'">{{ __('Run now') }}</x-ui.button></form>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>

<x-ui.card :title="__('Good to know')">
    <ul class="cj-examples">
        <li>{!! __('Laravel: run :cmd every minute.', ['cmd' => '<code>cd app && php artisan schedule:run</code>']) !!}</li>
        <li>{!! __('WordPress: switch WP-cron to the server on the WordPress tab of the website, no cron job needed.') !!}</li>
        <li>{{ __('The command php uses the PHP version of the website. A job starts in the website folder, with HOME, SITE_ROOT and DOCUMENT_ROOT set.') }}</li>
        <li>{{ __('A job never runs twice at the same time: while it still runs, the next run is skipped.') }}</li>
        <li>{{ __('Times are in the server\'s time zone (:tz).', ['tz' => $tz]) }}</li>
    </ul>
</x-ui.card>
