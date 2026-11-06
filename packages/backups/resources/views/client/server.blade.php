{{-- The nightly server backups (made by the hosting provider), restore only. --}}
<p class="muted">{{ __('Restore points of your websites. A backup is made every night.') }}</p>
@forelse ($websites as $site)
    <x-ui.card :title="$site->domain" flush>
        @if (empty($snapshots[$site->domain] ?? []))
            <x-ui.empty icon="backup">{{ __('No restore points yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Date') }}</th><th>{{ __('Kind') }}</th><th class="num"><span class="sr-only">{{ __('Restore') }}</span></th></x-slot:head>
            @foreach ($snapshots[$site->domain] as $s)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($s['time'])->isoFormat('LLL') }}</td>
                    <td class="small">{{ $s['kind'] === 'full' ? __('Full') : __('Incremental') }}</td>
                    <td class="num">
                        <form method="POST" action="{{ route('client.backups.restore') }}" data-confirm="{{ __('Put :domain back as it was on :date? Changes made after that are lost.', ['domain' => $site->domain, 'date' => \Illuminate\Support\Carbon::parse($s['time'])->isoFormat('LLL')]) }}">
                            @csrf<input type="hidden" name="website" value="{{ $site->id }}"><input type="hidden" name="snapshot" value="{{ $s['short_id'] }}">
                            <button class="btn btn-sm btn-dark" type="submit">{{ __('Restore') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
@empty
    <x-ui.card><x-ui.empty icon="globe">{{ __('You have no websites yet.') }}</x-ui.empty></x-ui.card>
@endforelse

@if (! empty($jobs))
    <x-ui.card :title="__('Recent restores')" flush>
        <x-ui.table>
            @foreach ($jobs as $j)<tr><td><a href="{{ route('client.backups.jobs.show', $j['id']) }}">{{ $j['label'] }}</a></td><td class="small muted">{{ \Illuminate\Support\Carbon::parse($j['started_at'])->diffForHumans() }}</td></tr>@endforeach
        </x-ui.table>
    </x-ui.card>
@endif
