@php
    $blocked = $current['state'] === 'blocked';
    $switched = $current['state'] === 'ok';
    $mode = $dns['mode'] ?? 'error';
    $chosen = $kept = collect();
    if ($mode === 'cloudflare') {
        $changes = collect($dns['rows'])->filter->isChange();
        $savedKeys = $dns['saved'] ?? [];
        $chosen = $changes->filter(fn ($r) => in_array($r->key(), $savedKeys, true))->values();
        $kept = $changes->reject(fn ($r) => in_array($r->key(), $savedKeys, true))->values();
    }
    $snapshots = $item->dnsSnapshots;
@endphp
<x-ui.card :title="__('Summary')">
    <ul class="wz-summary">
        @foreach ($steps as $k => $s)
            @continue($k === 'final')
            <li class="is-{{ $s['state'] }}"><a href="{{ route('admin.migration.wizard.show', [$item, $k]) }}"><span class="wz-num">@if (in_array($s['state'], ['ok', 'skip'], true))✓@else!@endif</span>
                <strong>{{ $s['title'] }}</strong><small>{{ $stateLabels[$s['state']] ?? '' }}</small></a></li>
        @endforeach
    </ul>
</x-ui.card>

@if (! $switched)
    @if ($mode === 'cloudflare')
        @if ($dns['stale'] ?? false)<div class="alert alert-bad"><x-icon name="info" />{{ __('The records in Cloudflare changed since you made your choice. Check the DNS step again.') }}</div>@endif
        <x-ui.card :title="__('These DNS changes go to Cloudflare')">
            @if ($chosen->isEmpty())
                <p class="small muted">{{ __('No changes chosen. The switch only marks the domain as live on this server.') }}</p>
            @else
                @include('migration::admin.wizard.dns-rows', ['rows' => $chosen->all(), 'saved' => null, 'stale' => false, 'editable' => false])
            @endif
            @if ($kept->isNotEmpty())
                <details><summary class="small">{{ trans_choice(':count proposed change is not made (you kept the current record)|:count proposed changes are not made (you kept the current records)', $kept->count()) }}</summary>
                    @include('migration::admin.wizard.dns-rows', ['rows' => $kept->all(), 'saved' => null, 'stale' => false, 'editable' => false])</details>
            @endif
            <p class="small muted">{{ __('A snapshot of the current records is saved first, so you can put them back with one click.') }}</p>
        </x-ui.card>
    @elseif ($mode !== 'error')
        <x-ui.card :title="__('Switch by hand')"><p class="small">{{ __('After the switch the panel shows the zone file to load at the DNS provider of the domain.') }}</p></x-ui.card>
    @endif

    <x-ui.card :title="__('Switch DNS definitively')">
        @if ($blocked)
            <p class="small">{{ __('Not ready yet:') }}</p>
        @else
            <p class="small">{{ __('Sync once more right before the switch, so the latest orders, posts and mail are here. Visitors reach this server within minutes after the switch.') }}</p>
            @php $last = $item->synced_at ?? $item->copied_at; @endphp
            @if ($last)<p class="small"><x-ui.pill :status="$last->gt(now()->subHours(1)) ? 'ok' : 'warn'">{{ __('Last copy :time', ['time' => $last->diffForHumans()]) }}</x-ui.pill></p>@endif
            <form method="POST" action="{{ route('admin.migration.wizard.switch', $item) }}" class="form">
                @csrf
                <label class="check"><input type="checkbox" name="understood" value="1"><span>{{ __('I checked every step and want to switch :domain to this server now', ['domain' => $item->domain]) }}</span></label>
                <x-ui.field name="confirm_domain" :label="__('Type the domain name to confirm')" :placeholder="$item->domain" autocomplete="off" />
                <div><button class="btn btn-dark" type="submit" @disabled($dns['stale'] ?? false)><x-icon name="globe" />{{ __('Switch DNS definitively') }}</button></div>
            </form>
        @endif
    </x-ui.card>
@else
    @php $r = $item->result['dns'] ?? []; @endphp
    <x-ui.card :title="__('DNS switched')">
        @if (($r['mode'] ?? '') === 'cloudflare')
            <p>{{ __(':changed changes were made in Cloudflare; :kept records were kept as they were.', ['changed' => $r['changed'] ?? 0, 'kept' => $r['kept'] ?? 0]) }}</p>
        @elseif (($r['mode'] ?? '') === 'manual')
            <p class="small">{{ __('Load this zone at the DNS provider of the domain to finish the switch.') }}</p><pre class="log">{{ $r['zone'] ?? '' }}</pre>
        @endif
        <p class="small muted">{{ __('When everything works here, mark the migration as finished on the progress page. You can then remove the domain from Plesk.') }}</p>
    </x-ui.card>
@endif

@if ($snapshots->isNotEmpty())
    <x-ui.card :title="__('DNS snapshots')">
        <p class="small muted">{{ __('The records as they were right before each switch. Putting a snapshot back points the domain to the old server again; records added later by hand stay.') }}</p>
        <table class="table">
            <thead><tr><th>{{ __('Taken') }}</th><th>{{ __('Records') }}</th><th>{{ __('Changes') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($snapshots as $snap)
                <tr>
                    <td>{{ $snap->created_at->timezone(config('app.timezone'))->format('d-m-Y H:i') }}<br><span class="small muted">{{ $snap->created_by }}</span></td>
                    <td>{{ count($snap->records ?? []) }}</td>
                    <td><details><summary class="small">{{ count($snap->changes ?? []) }}</summary><ul class="small mono">@foreach ($snap->changes ?? [] as $c)<li>{{ $c['action'] }} {{ $c['type'] }} {{ $c['name'] }}: {{ $c['old'] ?? '–' }} → {{ $c['new'] ?? '–' }}</li>@endforeach</ul></details></td>
                    <td>
                        @if ($snap->rolled_back_at)<x-ui.pill status="neutral">{{ __('Put back :time', ['time' => $snap->rolled_back_at->diffForHumans()]) }}</x-ui.pill>
                        @elseif ($loop->first && in_array($item->status, ['live', 'done'], true))
                            <form method="POST" action="{{ route('admin.migration.wizard.rollback', [$item, $snap]) }}" class="form">@csrf
                                <label class="check small"><input type="checkbox" name="sure" value="1" required><span>{{ __('Yes, put the old records back') }}</span></label>
                                <button class="btn btn-sm" type="submit"><x-icon name="refresh" />{{ __('Put old DNS back') }}</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-ui.card>
@endif
