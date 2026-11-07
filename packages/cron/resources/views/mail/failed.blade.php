<x-mail::message>
# {{ __('A cron job failed') }}

{{ __('The cron job :name of :domain did not finish successfully.', ['name' => $job->name, 'domain' => $job->website?->domain]) }}

<x-mail::table>
| | |
|:--|:--|
| {{ __('Result') }} | {{ $run->reason() }} |
| {{ __('Finished') }} | {{ $run->finished_at?->timezone(config('app.timezone'))->isoFormat('D MMM YYYY HH:mm') }} |
| {{ __('Duration') }} | {{ \Rhis\Cron\Models\CronRun::duration($run->duration_ms) }} |
| {{ __('Schedule') }} | {{ $job->scheduleLabel() }} |
| {{ __('Failed in a row') }} | {{ $job->failures }} |
</x-mail::table>

@if ($output !== '')
{{ __('Last lines of the output:') }}

<x-mail::panel>
@foreach (explode("\n", $output) as $line)
`{{ \Illuminate\Support\Str::limit($line, 160) }}`<br>
@endforeach
</x-mail::panel>
@else
{{ __('The job wrote no output.') }}
@endif

<x-mail::button :url="$url">
{{ __('View the cron job') }}
</x-mail::button>

{{ __('You get this mail once when a job starts failing, and again after :hours hours if it keeps failing. You can turn these mails off on the cron job page.', ['hours' => $remindHours]) }}
</x-mail::message>
