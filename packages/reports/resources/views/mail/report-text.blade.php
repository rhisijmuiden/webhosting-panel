{{ __('Your hosting in :month', ['month' => $monthName]) }}

{{ __('Hello :name,', ['name' => $customer->name]) }}

{{ __('Here is what your hosting did for you last month.') }}

@if ($r['visitors'])
{{ __('Visitors') }}: {{ \Rhis\Reports\Format::number($r['visitors']['total']) }} ({{ __(':n page views', ['n' => \Rhis\Reports\Format::number($r['visitors']['pageviews'])]) }})
@endif
@if ($r['uptime'])
{{ __('Online') }}: {{ \Rhis\Reports\Format::percent($r['uptime']['average']) }}@if ($r['uptime']['downtime_minutes'] > 0) ({{ __(':time offline', ['time' => \Rhis\Reports\Format::duration($r['uptime']['downtime_minutes'])]) }})@endif

@endif
@if ($r['backups'])
{{ __('Backups') }}: {{ \Rhis\Reports\Format::number($r['backups']['total']) }}@if ($r['backups']['last']) ({{ __('Latest on :date', ['date' => \Rhis\Reports\Format::day($r['backups']['last'])]) }})@endif

@endif
@if ($r['security']['scanned'])
{{ __('Security') }}: {{ $r['security']['open_urgent'] > 0 ? trans_choice(':count security problem still needs your attention.|:count security problems still need your attention.', $r['security']['open_urgent']) : __('Every night we checked your websites for known vulnerabilities and malware: nothing open.') }}
@endif
@if ($r['mail'])
{{ __('Email') }}: {{ __('Received') }} {{ \Rhis\Reports\Format::number($r['mail']['received']) }}, {{ __('Spam stopped') }} {{ \Rhis\Reports\Format::number($r['mail']['spam']) }}, {{ __('Sent') }} {{ \Rhis\Reports\Format::number($r['mail']['sent']) }}
@endif
{{ __('Disk space') }}: {{ \Rhis\Reports\Format::megabytes($r['usage']['disk_used_mb']) }}

@foreach ($r['sites'] as $s)
- {{ $s['domain'] }}@if ($r['visitors']): {{ \Rhis\Reports\Format::number($s['visitors'] ?? 0) }} {{ __('Visitors') }}@endif @if (isset($s['uptime'])), {{ \Rhis\Reports\Format::percent($s['uptime']) }} {{ __('Online') }}@endif

@endforeach

{{ __('Open the control panel') }}: {{ $panelUrl }}
{{ __('View this report online') }}: {{ $reportUrl }}
@if ($supportEmail)
{{ __('Questions or wishes? Mail us at') }} {{ $supportEmail }}
@endif

{{ __('You receive this report once a month because you host your website with :app.', ['app' => \App\Support\Brand::provider()]) }}
{{ __('Change who receives it, or stop it') }}: {{ $settingsUrl }}
