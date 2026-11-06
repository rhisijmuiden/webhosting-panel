@php
    use Rhis\Backups\RestoreTests\Labels;
    $items = collect($run->items());
    $bad = $items->whereIn('status', ['failed', 'warning', 'skipped']);
    $cov = $run->coverage();
@endphp
<x-mail::message>
# {{ $recovered ? __('Restore test passed again') : match ($run->status) { 'ok' => __('Restore test passed'), 'warning' => __('Restore test passed with warnings'), 'error' => __('Restore test could not run'), default => __('Restore test failed') } }}

@if ($run->status === 'error')
{{ __('The automatic restore test of the backups could not run: :error', ['error' => $run->error]) }}
@elseif ($run->isProblem())
{{ __('The automatic restore test found backups that cannot be fully restored. Check them before you need them.') }}
@elseif ($recovered)
{{ __('The previous restore test failed. This time every tested restore point could be restored.') }}
@else
{{ __(':n restore points were restored in an isolated test location, checked and removed again.', ['n' => $run->tested]) }}
@endif

@if ($bad->isNotEmpty())
**{{ __('Problems') }}**

@foreach ($bad as $item)
- **{{ Labels::scope($item['scope']) }}** ({{ $item['target'] }}, {{ Labels::kind($item['kind'] ?? null) }} {{ \Illuminate\Support\Carbon::parse($item['time'])->isoFormat('lll') }}): {{ collect($item['checks'] ?? [])->where('result', '!=', 'ok')->map(fn ($c) => Labels::check($c['check']).': '.Labels::detail($c['detail']))->implode('; ') }}
@endforeach
@endif

@if ($cov['missing'])
**{{ __('No backup at all') }}**

@foreach ($cov['missing'] as $m)
- {{ Labels::scope($m['scope']) }} ({{ $m['target'] }})
@endforeach
@endif

@if ($cov['stale'])
**{{ __('Backup too old') }}**

@foreach ($cov['stale'] as $s)
- {{ Labels::scope($s['scope']) }} ({{ $s['target'] }}): {{ __(':h hours ago', ['h' => $s['age_hours']]) }}
@endforeach
@endif

@foreach ($run->report['targets'] ?? [] as $t)
@if (! empty($t['error']))
- **{{ $t['target'] }}**: {{ $t['error'] }}
@endif
@endforeach

{{ __('Tested: :n restore points · Server: :server', ['n' => $run->tested, 'server' => $server]) }}

<x-mail::button :url="route('admin.backups.restore-tests.show', $run)">
{{ __('Open the report') }}
</x-mail::button>
</x-mail::message>
