@php
    $bytes = function ($b) { if ($b === null) return '–'; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, $i ? 1 : 0).' '.$u[$i]; };
    $pill = ['succeeded' => 'ok', 'failed' => 'bad', 'running' => 'warn'];
    $schedules = ['off' => __('Off'), 'daily' => __('Every day'), 'weekly' => __('Every week')];
    $own = config('backups.customer_targets');
    $upload = $own && config('backups.customer_upload_restore');
    $server = config('backups.customer_restore');
    if (! $own && $tab !== 'server') { $tab = 'server'; }
@endphp
<x-layouts.client :title="__('Backups')">
    <div class="page-head">
        <div><h1>{{ __('Backups') }}</h1><p class="sub">{{ __('Back up your websites to your own FTP or SFTP server, and put a backup back when you need it.') }}</p></div>
        @if ($own)<div class="actions"><x-ui.button :href="route('client.backups.locations.create')" variant="primary" icon="plus">{{ __('Add backup location') }}</x-ui.button></div>@endif
    </div>
    @include('backups::partials.styles')
    <nav class="bk-tabs" aria-label="{{ __('Backups') }}">
        @if ($own)<a href="{{ route('client.backups.index') }}" @if ($tab === 'own') aria-current="page" @endif><x-icon name="backup" />{{ __('My backups') }}</a>@endif
        @if ($upload)<a href="{{ route('client.backups.index', ['tab' => 'upload']) }}" @if ($tab === 'upload') aria-current="page" @endif><x-icon name="refresh" />{{ __('Restore from a file') }}</a>@endif
        @if ($server)<a href="{{ route('client.backups.index', ['tab' => 'server']) }}" @if ($tab === 'server') aria-current="page" @endif><x-icon name="server" />{{ __('Nightly backups') }}</a>@endif
    </nav>

    @if ($error && $tab === 'server')<div class="alert alert-bad" role="alert">{{ $error }}</div>@endif
    @error('agent')<div class="alert alert-bad" role="alert">{{ $message }}</div>@enderror
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif

    @if ($tab === 'own')
        @if ($targets->isEmpty())
            <x-ui.card>
                <x-ui.empty icon="backup">
                    <strong>{{ __('No backup location yet') }}</strong><br>
                    {{ __('Add your own FTP, FTPS or SFTP server. The panel then makes a full backup every week and saves the changes every day, as a zip file you can always download and keep.') }}
                    <div style="margin-top: 12px"><x-ui.button :href="route('client.backups.locations.create')" variant="primary" icon="plus">{{ __('Add backup location') }}</x-ui.button></div>
                </x-ui.empty>
            </x-ui.card>
        @else
            <section class="grid grid-2" style="align-items:start">
                <x-ui.card :title="__('Back up now')">
                    <form method="POST" action="{{ route('client.backups.run') }}" class="form">
                        @csrf
                        <div class="seg" role="radiogroup" aria-label="{{ __('Kind') }}">
                            <label><input type="radio" name="kind" value="full" checked><span>{{ __('Full') }}</span></label>
                            <label><input type="radio" name="kind" value="incremental"><span>{{ __('Only changes') }}</span></label>
                        </div>
                        <div class="form-grid">
                            <x-ui.field name="website" type="select" :label="__('Website')">
                                <option value="all">{{ __('All websites of this location') }}</option>
                                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->domain }}</option>@endforeach
                            </x-ui.field>
                            <x-ui.field name="target" type="select" :label="__('Location')">
                                @foreach ($targets as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </x-ui.field>
                        </div>
                        <p class="muted small">{{ __('"Only changes" saves what changed since the last full backup. Restoring it needs that full backup too; the panel takes care of that.') }}</p>
                        <div><button type="submit" class="btn btn-dark" @disabled($websites->isEmpty())><x-icon name="backup" />{{ __('Start backup') }}</button></div>
                    </form>
                </x-ui.card>

                <x-ui.card :title="__('My backup locations')" flush>
                    <x-ui.table>
                        <x-slot:head><th>{{ __('Name') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                        @foreach ($targets as $t)
                            <tr>
                                <td><a class="strong" href="{{ route('client.backups.locations.show', $t->id) }}">{{ $t->name }}</a><br>
                                    <span class="small muted mono">{{ $t->type === 'onedrive' ? '' : strtoupper($t->type).' · ' }}{{ $t->location() }}</span>
                                    <br><span class="small">{{ $schedules[$t->schedule] ?? $t->schedule }}@if ($t->schedule !== 'off') {{ $t->schedule_time }} · {{ __('full on :day', ['day' => __($t->full_day)]) }}@endif</span>
                                    @if ($t->last_error)<br><span class="small" style="color:var(--bad, #c0392b)">{{ \Illuminate\Support\Str::limit($t->last_error, 120) }}</span>@endif</td>
                                <td class="num">
                                    <div class="actions" style="justify-content:flex-end">
                                        <a class="btn btn-sm btn-dark" href="{{ route('client.backups.locations.show', $t->id) }}">{{ __('Backups') }}</a>
                                        <a class="btn btn-sm" href="{{ route('client.backups.locations.edit', $t->id) }}" aria-label="{{ __('Edit') }}"><x-icon name="edit" /></a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            </section>
        @endif

        @if ($history->isNotEmpty())
            <x-ui.card :title="__('Recent backups and restores')" flush>
                <x-ui.table>
                    <x-slot:head><th>{{ __('Started') }}</th><th>{{ __('Website') }}</th><th>{{ __('What') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Size') }}</th></x-slot:head>
                    @foreach ($history as $r)
                        <tr>
                            <td class="small">{{ $r->started_at?->isoFormat('lll') }}<br><span class="muted">{{ $r->trigger === 'schedule' ? __('Scheduled') : ($r->user ?? '') }}</span></td>
                            <td>{{ $r->domain }}</td>
                            <td>@if ($r->job_id)<a href="{{ route('client.backups.jobs.show', $r->job_id) }}">{{ $r->label() }}</a>@else{{ $r->label() }}@endif
                                <br><span class="small muted">{{ $r->target?->name }}</span></td>
                            <td><x-ui.pill :status="$pill[$r->status] ?? 'neutral'">{{ __(ucfirst($r->status)) }}</x-ui.pill>
                                @if ($r->status === 'failed' && $r->message)<br><span class="small muted">{{ \Illuminate\Support\Str::limit($r->message, 120) }}</span>@endif</td>
                            <td class="num small" style="white-space:nowrap">{{ $bytes($r->size) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    @elseif ($tab === 'upload')
        @include('backups::client.upload')
    @else
        @include('backups::client.server')
    @endif
</x-layouts.client>
