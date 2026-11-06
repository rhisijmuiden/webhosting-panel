@php
    $kindLabel = fn ($k) => match ($k) { 'full' => __('Full'), 'incremental' => __('Incremental'), default => $k };
    $total = collect($groups)->sum(fn ($g) => count($g['items']));
@endphp
<x-layouts.admin :title="__('Browse by date')">
    <div class="page-head">
        <div><h1>{{ __('Backups') }}</h1><p class="sub">{{ __('Every backup that was made, per day. Pick a day, then choose what to put back: one website, a whole customer or everything.') }}</p></div>
        @if (count($targets) > 1)
            <form method="GET" action="{{ route('admin.backups.browse') }}" class="actions">
                <select name="target" class="select" aria-label="{{ __('Location') }}" x-data @change="$el.form.submit()">
                    @foreach ($targets as $t)<option value="{{ $t['name'] }}" @selected($t['name'] === $selected)>{{ $t['name'] }}</option>@endforeach
                </select>
                <noscript><button class="btn btn-sm" type="submit">{{ __('Show') }}</button></noscript>
            </form>
        @endif
    </div>
    @include('backups::partials.admin-tabs')

    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @error('items')<div class="alert alert-bad" role="alert">{{ $message }}</div>@enderror

    @if (! $selected)
        <x-ui.card><x-ui.empty icon="backup">{{ __('Add a backup location first.') }}</x-ui.empty></x-ui.card>
    @elseif (empty($days))
        <x-ui.card><x-ui.empty icon="backup">{{ __('No restore points on this location yet.') }}</x-ui.empty></x-ui.card>
    @else
    <div class="bk-browser">
        <x-ui.card :title="__('Days')" flush>
            <ul class="bk-days">
                @foreach ($days as $d)
                    <li><a href="{{ route('admin.backups.browse', ['target' => $selected, 'date' => $d['date']]) }}" @if ($d['date'] === $date) aria-current="date" @endif>
                        <span>{{ \Illuminate\Support\Carbon::parse($d['date'])->isoFormat('ddd D MMM YYYY') }}</span>
                        <span class="bk-count">@if ($d['full'])<x-ui.pill status="info" :dot="false">{{ __('Full') }}</x-ui.pill> @endif{{ trans_choice(':count item|:count items', $d['count']) }}</span>
                    </a></li>
                @endforeach
            </ul>
        </x-ui.card>

        <form method="POST" action="{{ route('admin.backups.browse.restore') }}" class="card card-flush"
              x-data="{ mode: 'in_place', n: 0,
                        count() { this.n = this.$root.querySelectorAll('input[name=\'items[]\']:checked').length },
                        all(on) { this.$root.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = on); this.count() },
                        group(key, on) { this.$root.querySelectorAll('input[data-group=\'' + key + '\']').forEach(c => c.checked = on); this.count() } }"
              x-init="count()" @change="count()"
              @submit="if (!confirm(mode === 'in_place' ? @js(__('Put the selected items back as they were on this day? The current state of each item is saved first as a restore point.')) : @js(__('Restore a copy of the selected items to /root/restore on the server?')))) $event.preventDefault()">
            @csrf
            <input type="hidden" name="target" value="{{ $selected }}"><input type="hidden" name="date" value="{{ $date }}">
            <div class="card-head">
                <h2>{{ \Illuminate\Support\Carbon::parse($date)->isoFormat('dddd D MMMM YYYY') }}</h2>
                <div class="actions">
                    <label class="check"><input type="checkbox" @change="all($event.target.checked)" aria-label="{{ __('Select everything') }}"><span>{{ __('Everything (:count)', ['count' => $total]) }}</span></label>
                </div>
            </div>
            <div style="padding: 0 16px 8px">
                @foreach ($groups as $g)
                    <section class="bk-group" @if ($focus && $focus === $g['key']) style="outline: 2px solid var(--lucht, #4aa3df)" @endif>
                        <header>
                            <label><input type="checkbox" @change="group(@js($g['key']), $event.target.checked)" @checked($focus === $g['key'])>
                                @if ($g['key'] === 'server')<x-icon name="server" />@elseif ($g['customer'])<x-icon name="user" />@else<x-icon name="info" />@endif
                                {{ $g['title'] }}</label>
                            <span class="muted small">{{ trans_choice(':count item|:count items', count($g['items'])) }}</span>
                        </header>
                        <ul class="bk-items">
                            @foreach ($g['items'] as $it)
                                <li><label>
                                    <input type="checkbox" name="items[]" value="{{ $it['id'] }}|{{ $it['scope'] }}" data-group="{{ $g['key'] }}" @checked($focus === $g['key'])>
                                    <span>{{ $it['label'] }} @if ($it['database'] && $it['website'])<span class="muted small">· {{ $it['website'] }}</span>@endif</span>
                                    <span class="small muted">{{ $it['time']->format('H:i') }} · {{ $kindLabel($it['kind']) }} · <span class="mono">{{ $it['short_id'] }}</span></span>
                                </label></li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
            <div class="bk-sticky">
                <div class="seg" role="radiogroup" aria-label="{{ __('How') }}">
                    <label><input type="radio" name="mode" value="in_place" x-model="mode" checked><span>{{ __('Put back in place') }}</span></label>
                    <label><input type="radio" name="mode" value="copy" x-model="mode"><span>{{ __('Copy to /root/restore') }}</span></label>
                </div>
                <button type="submit" class="btn btn-dark" :disabled="n === 0"><x-icon name="backup" /><span x-text="@js(__('Restore selected')) + ' (' + n + ')'">{{ __('Restore selected') }}</span></button>
            </div>
        </form>
    </div>
    @endif
</x-layouts.admin>
