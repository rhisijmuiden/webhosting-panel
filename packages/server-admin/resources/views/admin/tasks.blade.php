<x-layouts.admin :title="__('Scheduled tasks')">
    @include('server-admin::partials.styles')
    <div class="page-head">
        <div><p class="crumbs"><a href="{{ route('admin.server.index') }}">{{ __('Server management') }}</a></p>
            <h1>{{ __('Scheduled tasks') }}</h1>
            <p class="sub">{{ __('Maintenance that keeps the server fast and tidy. Tasks skip their turn while updates are installed or the server reboots.') }}</p></div>
    </div>
    @include('server-admin::partials.tabs')

    @php
        $optionLabels = [
            'journal_days' => __('System logs (days)'), 'site_tmp_days' => __('Temporary files of websites (days)'),
            'site_sessions_days' => __('PHP sessions (days)'), 'jobs_days' => __('Agent job files (days)'), 'apt' => __('Old kernels and package cache'),
            'activity_days' => __('Activity log (days)'), 'runs_days' => __('Run history (days)'), 'failed_jobs_days' => __('Failed queue jobs (days)'),
            'log_days' => __('Panel log files (days)'), 'min_free_mb' => __('Optimize tables from (MB unused)'), 'analyze' => __('Refresh index statistics'),
        ];
        $days = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 0 => __('Sunday')];
    @endphp

    @foreach ($tasks as $task)
        @php $last = $last[$task->key] ?? null; @endphp
        <x-ui.card>
            <div class="sv-task">
                <div>
                    <h2 style="margin:0;font-size:17px">{{ \Rhis\ServerAdmin\Tasks::label($task->key) }}
                        @if (! $task->enabled)<x-ui.pill status="off" :dot="false">{{ __('Off') }}</x-ui.pill>@endif</h2>
                    <p class="desc">{{ \Rhis\ServerAdmin\Tasks::description($task->key) }}</p>
                    <p class="small" style="margin-top:10px">
                        @if ($last)
                            <x-ui.pill :status="$last->status">{{ $last->isRunning() ? __('Running') : ($last->status === 'succeeded' ? __('Done') : __('Failed')) }}</x-ui.pill>
                            <a href="{{ route('admin.server.run', $last) }}">{{ $last->started_at->diffForHumans() }}</a>
                            @if ($last->summary)<span class="muted">· {{ $last->summary }}</span>@endif
                        @else<span class="muted">{{ __('Not run yet') }}</span>@endif
                    </p>
                    <p class="small muted">{{ $task->scheduleLabel() }}@if ($task->enabled && $task->next_run_at) · {{ __('next :time', ['time' => $task->next_run_at->setTimezone(config('server-admin.timezone'))->isoFormat('dd D MMM HH:mm')]) }}@endif</p>
                </div>
                <form method="POST" action="{{ route('admin.server.tasks.save', $task->key) }}" class="form">
                    @csrf @method('PUT')
                    <input type="hidden" name="enabled" value="0">
                    <label class="check"><input type="checkbox" name="enabled" value="1" @checked($task->enabled)><span>{{ __('On') }}</span></label>
                    <div class="sv-inline">
                        <x-ui.field name="frequency" type="select" :label="__('How often')">
                            @foreach (['hourly' => __('Every hour'), 'daily' => __('Every day'), 'weekly' => __('Every week'), 'monthly' => __('Every month')] as $v => $l)
                                <option value="{{ $v }}" @selected($task->frequency === $v)>{{ $l }}</option>
                            @endforeach
                        </x-ui.field>
                        <x-ui.field name="time" type="time" :label="__('Time')" :value="$task->time" :help="$task->frequency === 'hourly' ? __('Only the minutes count') : null" />
                        @if ($task->frequency === 'weekly')
                            <x-ui.field name="weekday" type="select" :label="__('Day')">
                                @foreach ($days as $n => $l)<option value="{{ $n }}" @selected($task->weekday === $n)>{{ $l }}</option>@endforeach
                            </x-ui.field>
                        @elseif ($task->frequency === 'monthly')
                            <x-ui.field name="day" type="number" min="1" max="28" :label="__('Day of the month')" :value="$task->day" />
                        @endif
                    </div>
                    @if ($defaults = config('server-admin.tasks.'.$task->key.'.options'))
                        <div class="sv-inline">
                            @foreach ($defaults as $k => $default)
                                @if (is_bool($default))
                                    <input type="hidden" name="options[{{ $k }}]" value="0">
                                    <label class="check"><input type="checkbox" name="options[{{ $k }}]" value="1" @checked($task->option($k, $default))><span>{{ $optionLabels[$k] ?? $k }}</span></label>
                                @else
                                    <x-ui.field name="options[{{ $k }}]" type="number" min="0" max="3650" :label="$optionLabels[$k] ?? $k" :value="$task->option($k, $default)" :help="__('0 = skip')" />
                                @endif
                            @endforeach
                        </div>
                    @endif
                    <div class="actions">
                        <x-ui.button size="sm">{{ __('Save') }}</x-ui.button>
                        <button class="btn btn-sm" type="submit" form="run-{{ $task->key }}" @disabled($last?->isRunning())>{{ __('Run now') }}</button>
                    </div>
                </form>
                <form id="run-{{ $task->key }}" method="POST" action="{{ route('admin.server.tasks.run', $task->key) }}">@csrf</form>
            </div>
        </x-ui.card>
    @endforeach

    <x-ui.card :title="__('History')" flush>
        @include('server-admin::partials.runs', ['runs' => $runs])
    </x-ui.card>
</x-layouts.admin>
