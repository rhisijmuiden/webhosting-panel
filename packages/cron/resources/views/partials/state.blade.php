{{-- Pill for a job: running / paused / last result. $job, $st (live status or null). --}}
@php $run = $st['running'] ?? false; @endphp
@if ($run)
    <x-ui.pill status="info"><span class="live"></span> {{ __('Running') }}</x-ui.pill>
@elseif (! $job->enabled)
    <x-ui.pill status="off">{{ __('Paused') }}</x-ui.pill>
@elseif ($job->website?->status === 'suspended')
    <x-ui.pill status="suspended">{{ __('Website suspended') }}</x-ui.pill>
@elseif ($job->last_status)
    @php [$pill, $label] = \Rhis\Cron\Models\CronRun::STATUSES[$job->last_status] ?? ['bad', 'Failed']; @endphp
    <x-ui.pill :status="$pill">{{ __($label) }}</x-ui.pill>
@else
    <x-ui.pill status="neutral">{{ __('Not run yet') }}</x-ui.pill>
@endif
