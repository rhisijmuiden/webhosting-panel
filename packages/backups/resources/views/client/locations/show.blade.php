@php
    $bytes = function ($b) { if ($b === null) return '–'; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, $i ? 1 : 0).' '.$u[$i]; };
@endphp
<x-layouts.client :title="$target->name">
    @include('backups::partials.styles')
    <div class="page-head">
        <div><h1>{{ $target->name }}</h1><p class="sub mono">{{ $target->type === 'onedrive' ? '' : strtoupper($target->type).' · ' }}{{ $target->location() }}</p></div>
        <div class="actions">
            <form method="POST" action="{{ route('client.backups.locations.test', $target->id) }}">@csrf<x-ui.button icon="refresh">{{ __('Test') }}</x-ui.button></form>
            <x-ui.button :href="route('client.backups.locations.edit', $target->id)" icon="edit">{{ __('Settings') }}</x-ui.button>
            <x-ui.button :href="route('client.backups.index')" icon="backup">{{ __('Back to backups') }}</x-ui.button>
        </div>
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @error('agent')<div class="alert alert-bad" role="alert">{{ $message }}</div>@enderror
    @if ($error)<div class="alert alert-bad" role="alert">{{ $error }}</div>@endif

    @if ($websites->isEmpty())
        <x-ui.card><x-ui.empty icon="globe">{{ __('You have no websites yet.') }}</x-ui.empty></x-ui.card>
    @else
    <x-ui.card :title="__('Backups on this location')" flush>
        <x-slot:actions>
            @if ($websites->count() > 1)
                <form method="GET" action="{{ route('client.backups.locations.show', $target->id) }}">
                    <select name="website" class="select" aria-label="{{ __('Website') }}" x-data @change="$el.form.submit()">
                        @foreach ($websites as $w)<option value="{{ $w->id }}" @selected($w->id === $website->id)>{{ $w->domain }}</option>@endforeach
                    </select>
                </form>
            @endif
            <form method="POST" action="{{ route('client.backups.run') }}">
                @csrf<input type="hidden" name="target" value="{{ $target->id }}"><input type="hidden" name="website" value="{{ $website->id }}"><input type="hidden" name="kind" value="full">
                <x-ui.button variant="primary" icon="backup">{{ __('Back up now') }}</x-ui.button>
            </form>
        </x-slot:actions>
        @if (empty($backups))
            <x-ui.empty icon="backup">{{ __('No backups of :domain on this location yet.', ['domain' => $website->domain]) }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Made on') }}</th><th>{{ __('Kind') }}</th><th class="num">{{ __('Size') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($backups as $b)
                <tr x-data="{ open: false }">
                    <td>{{ \Illuminate\Support\Carbon::parse($b['created'])->setTimezone(config('app.timezone'))->isoFormat('LLL') }}<br><span class="small mono muted">{{ $b['file'] }}</span></td>
                    <td>@if ($b['kind'] === 'full')<x-ui.pill status="info" :dot="false">{{ __('Full') }}</x-ui.pill>@else<x-ui.pill :dot="false">{{ __('Changes') }}</x-ui.pill>@endif</td>
                    <td class="num small" style="white-space:nowrap">{{ $bytes($b['size']) }}</td>
                    <td class="num">
                        <div class="actions" style="justify-content:flex-end">
                            <button type="button" class="btn btn-sm btn-dark" @click="open = !open" :aria-expanded="open">{{ __('Restore') }}</button>
                            <form method="POST" action="{{ route('client.backups.locations.files.destroy', $target->id) }}" data-confirm="{{ __('Delete this backup from the location?') }}{{ $b['kind'] === 'full' ? ' '.__('Backups with changes that build on it can then no longer be restored.') : '' }}">
                                @csrf @method('DELETE')<input type="hidden" name="website" value="{{ $website->id }}"><input type="hidden" name="file" value="{{ $b['file'] }}">
                                <button class="btn btn-sm btn-danger" type="submit" aria-label="{{ __('Delete') }}"><x-icon name="trash" /></button>
                            </form>
                        </div>
                        <form x-show="open" x-cloak method="POST" action="{{ route('client.backups.locations.restore', $target->id) }}" class="form" style="text-align:left; margin-top: 12px"
                              data-confirm="{{ __('Put :domain back as it was on :date? Files that are not in the backup are removed and the selected databases are overwritten.', ['domain' => $website->domain, 'date' => \Illuminate\Support\Carbon::parse($b['created'])->setTimezone(config('app.timezone'))->isoFormat('LLL')]) }}">
                            @csrf<input type="hidden" name="website" value="{{ $website->id }}"><input type="hidden" name="file" value="{{ $b['file'] }}">
                            <div class="bk-parts">
                                <label class="check"><input type="checkbox" name="parts[]" value="files" checked><span>{{ __('Website files') }}</span></label>
                                <label class="check"><input type="checkbox" name="parts[]" value="databases" checked><span>{{ __('Databases') }}</span></label>
                                <label class="check"><input type="checkbox" name="parts[]" value="mail"><span>{{ __('Mail (missing messages are added)') }}</span></label>
                            </div>
                            <label class="check"><input type="hidden" name="safety" value="0"><input type="checkbox" name="safety" value="1" checked><span>{{ __('First make a backup of the current state') }}</span></label>
                            <div><button class="btn btn-sm btn-dark" type="submit">{{ __('Restore this backup') }}</button></div>
                        </form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    @endif
</x-layouts.client>
