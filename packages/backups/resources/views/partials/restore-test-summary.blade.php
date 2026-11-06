@php $rt = \Rhis\Backups\Models\RestoreTest::whereIn('status', \Rhis\Backups\Models\RestoreTest::FINISHED)->latest('id')->first(); @endphp
<a class="alert {{ ! $rt ? 'alert-info' : ($rt->status === 'ok' ? 'alert-ok' : ($rt->status === 'warning' ? 'alert-warn' : 'alert-bad')) }}" href="{{ route('admin.backups.restore-tests.index') }}" style="text-decoration:none;color:inherit">
    <x-icon name="check" />
    <div><strong>{{ __('Restore test') }}: {{ $rt ? $rt->statusLabel() : __('not run yet') }}</strong>
        <p class="small">{{ $rt ? __(':n restore points restored and checked :time.', ['n' => $rt->tested, 'time' => $rt->started_at->diffForHumans()]) : __('Prove that the backups can be restored: set up automatic restore tests.') }}</p></div>
</a>
