@php
    use Rhis\Backups\RestoreTests\Labels;
    $tone = $last ? match ($last->status) { 'ok' => 'ok', 'warning' => 'warn', default => 'bad' } : 'none';
    $cov = $last?->coverage();
    [$checksOk, $checksAll] = $last ? $last->checksPassed() : [0, 0];
    $freq = ['daily' => __('Every day'), 'weekly' => __('Every week'), 'monthly' => __('Every month')];
    $dayNames = ['Mon' => __('Monday'), 'Tue' => __('Tuesday'), 'Wed' => __('Wednesday'), 'Thu' => __('Thursday'), 'Fri' => __('Friday'), 'Sat' => __('Saturday'), 'Sun' => __('Sunday')];
@endphp
<x-layouts.admin :title="__('Restore tests')">
    @include('backups::partials.restore-test-styles')
    <div class="page-head">
        <div><h1>{{ __('Backups') }}</h1><p class="sub">{{ __('Automatic restore tests prove that the backups can really be restored, before you need them.') }}</p></div>
        <div class="actions">
            <form method="POST" action="{{ route('admin.backups.restore-tests.store') }}" class="actions">
                @csrf
                @if (count($targets) > 1)
                    <select name="target" class="select" aria-label="{{ __('Location') }}">
                        <option value="">{{ __('All locations') }}</option>
                        @foreach ($targets as $t)<option value="{{ $t['name'] }}">{{ $t['name'] }}</option>@endforeach
                    </select>
                @endif
                <button type="submit" class="btn btn-dark" @disabled($running || empty($targets))><x-icon name="play" />{{ __('Test now') }}</button>
            </form>
        </div>
    </div>
    @include('backups::partials.admin-tabs')

    @if ($agentError)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __('The server agent does not answer: :error', ['error' => $agentError]) }}</div>@endif
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @if ($running)
        <div class="alert alert-info" role="status"><x-icon name="refresh" />
            <div><strong>{{ __('A restore test is running') }}</strong>
                <p class="small">{{ __('Started :time', ['time' => $running->started_at->diffForHumans()]) }} · <a href="{{ route('admin.backups.restore-tests.show', $running) }}">{{ __('Follow it') }}</a></p></div>
        </div>
    @endif

    <section class="rt-hero" aria-label="{{ __('Status') }}">
        <div class="rt-state rt-{{ $tone }}">
            <x-ui.ring :value="$passRate ?? 0" :tone="$tone === 'none' ? 'ok' : $tone" :size="128">
                <strong>{{ $passRate !== null ? $passRate.'%' : '–' }}</strong><small class="muted">{{ __('passed') }}</small>
            </x-ui.ring>
            <div>
                <div class="eyebrow">{{ __('Last restore test') }}@if ($last)<x-ui.pill :status="$last->tone()">{{ $last->statusLabel() }}</x-ui.pill>@endif</div>
                @if (! $last)
                    <h2>{{ __('No restore test yet') }}</h2>
                    <p>{{ __('The first test runs on the schedule below, or start one now. Nothing on the live websites changes.') }}</p>
                @elseif ($last->status === 'ok')
                    <h2>{{ __('Your backups can be restored') }}</h2>
                    <p>{{ __(':n restore points were downloaded, restored in an isolated test location, checked and removed again.', ['n' => $last->tested]) }}</p>
                @elseif ($last->status === 'warning')
                    <h2>{{ __('Restorable, with warnings') }}</h2>
                    <p>{{ __('Points that need attention: :n. Open the report to see what.', ['n' => $last->problems]) }}</p>
                @elseif ($last->status === 'error')
                    <h2>{{ __('The restore test could not run') }}</h2>
                    <p>{{ $last->error }}</p>
                @else
                    <h2>{{ __('Some backups cannot be restored') }}</h2>
                    <p>{{ __('Problems found: :n. The administrators were mailed.', ['n' => $last->problems]) }}</p>
                @endif
                @if ($last)
                    <div class="rt-meta">
                        <span><x-icon name="activity" />{{ $last->started_at->diffForHumans() }}</span>
                        @if ($last->durationForHumans())<span><x-icon name="refresh" />{{ $last->durationForHumans() }}</span>@endif
                        <span><x-icon name="check" />{{ __(':ok of :all checks passed', ['ok' => $checksOk, 'all' => $checksAll]) }}</span>
                        <a href="{{ route('admin.backups.restore-tests.show', $last) }}">{{ __('Open the report') }}</a>
                    </div>
                @endif
            </div>
        </div>
        <div class="rt-tiles">
            <div class="rt-tile"><span class="ic"><x-icon name="activity" /></span><span>{{ __('Next test') }}</span>
                <strong>{{ $next ? $next->isoFormat('dd D MMM HH:mm') : __('Off') }}</strong></div>
            <div class="rt-tile"><span class="ic {{ $cov && ($cov['missing'] || $cov['stale']) ? 'bad' : 'ok' }}"><x-icon name="shield" /></span><span>{{ __('Everything has a recent backup') }}</span>
                <strong>@if ($cov && $cov['items']){{ $cov['with_backup'] - count($cov['stale']) }} / {{ $cov['items'] }}@else – @endif</strong></div>
            <div class="rt-tile"><span class="ic {{ $lastOk ? 'ok' : 'warn' }}"><x-icon name="check" /></span><span>{{ __('Last passed') }}</span>
                <strong>{{ $lastOk ? $lastOk->started_at->isoFormat('D MMM YYYY') : __('Never') }}</strong></div>
        </div>
    </section>

    <div class="bento">
        <x-ui.card class="span-8" :title="__('History')" flush>
            @if ($history->isEmpty())
                <x-ui.empty icon="backup">{{ __('No restore tests yet.') }}</x-ui.empty>
            @else
                <x-ui.table>
                    <x-slot:head><th>{{ __('Date') }}</th><th>{{ __('Started by') }}</th><th>{{ __('Result') }}</th><th class="num">{{ __('Tested') }}</th><th class="num">{{ __('Problems') }}</th><th class="num">{{ __('Duration') }}</th></x-slot:head>
                    @foreach ($history as $h)
                        <tr>
                            <td><a href="{{ route('admin.backups.restore-tests.show', $h) }}">{{ $h->started_at?->isoFormat('lll') }}</a>@if ($h->target)<br><span class="small muted">{{ $h->target }}</span>@endif</td>
                            <td class="small">{{ $h->trigger === 'manual' ? ($h->user?->name ?? __('Administrator')) : __('Schedule') }}</td>
                            <td><x-ui.pill :status="$h->tone()">{{ $h->statusLabel() }}</x-ui.pill></td>
                            <td class="num">{{ $h->tested }}</td>
                            <td class="num">{{ $h->problems ?: '–' }}</td>
                            <td class="num small" style="white-space:nowrap">{{ $h->durationForHumans() ?? '–' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
                @if ($history->hasPages())<div style="padding:12px 16px">{{ $history->links() }}</div>@endif
            @endif
        </x-ui.card>

        <x-ui.card class="span-4" :title="__('What a restore test does')">
            <ol class="rt-steps">
                <li><div><strong>{{ __('Pick restore points') }}</strong><small>{{ __('The newest backup and the newest full backup of the server configuration and of websites, mailboxes and databases, the ones tested longest ago first.') }}</small></div></li>
                <li><div><strong>{{ __('Restore them in isolation') }}</strong><small>{{ __('Downloaded from every backup location (SFTP, SCP, FTP(S), OneDrive) into a private scratch folder; databases into a private, throw-away MariaDB. Live data is never touched.') }}</small></div></li>
                <li><div><strong>{{ __('Check them') }}</strong><small>{{ __('Every file content is verified against its checksum, file counts and sizes must match, PHP must parse, dumps must be complete, import and pass CHECK TABLE.') }}</small></div></li>
                <li><div><strong>{{ __('Clean up and report') }}</strong><small>{{ __('Everything restored is removed again. On a failure the administrators get an email.') }}</small></div></li>
            </ol>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Schedule and alerts')">
        <form method="POST" action="{{ route('admin.backups.restore-tests.settings') }}" class="form rt-form" x-data="{ freq: @js($options['frequency']) }">
            @csrf @method('PUT')
            <label class="check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($options['enabled'] === '1')><span>{{ __('Test the backups automatically') }}<small>{{ __('Runs after the nightly backups. Restore tests and backups never run at the same time.') }}</small></span></label>
            <div class="form-grid">
                <x-ui.field name="frequency" type="select" :label="__('How often')" x-model="freq">
                    @foreach ($freq as $k => $l)<option value="{{ $k }}" @selected($options['frequency'] === $k)>{{ $l }}</option>@endforeach
                </x-ui.field>
                <div x-show="freq === 'weekly'">
                    <x-ui.field name="day" type="select" :label="__('Day')">
                        @foreach ($days as $d)<option value="{{ $d }}" @selected($options['day'] === $d)>{{ $dayNames[$d] }}</option>@endforeach
                    </x-ui.field>
                </div>
                <div x-show="freq === 'monthly'" x-cloak>
                    <x-ui.field name="monthday" type="number" min="1" max="28" :label="__('Day of the month')" :value="$options['monthday']" />
                </div>
                <x-ui.field name="time" type="time" :label="__('Time')" :value="$options['time']" />
                <x-ui.field name="target" type="select" :label="__('Location')">
                    <option value="">{{ __('All locations') }}</option>
                    @foreach ($targets as $t)<option value="{{ $t['name'] }}" @selected($options['target'] === $t['name'])>{{ $t['name'] }}</option>@endforeach
                </x-ui.field>
            </div>
            <h3>{{ __('What is tested per run') }}</h3>
            <div class="form-grid">
                <x-ui.field name="sites" type="number" min="0" max="50" :label="__('Websites')" :value="$options['sites']" />
                <x-ui.field name="mail" type="number" min="0" max="50" :label="__('Mail domains')" :value="$options['mail']" />
                <x-ui.field name="databases" type="number" min="0" max="50" :label="__('Databases')" :value="$options['databases']" />
                <x-ui.field name="kinds" type="select" :label="__('Restore points')">
                    <option value="both" @selected($options['kinds'] === 'both')>{{ __('Newest and newest full backup') }}</option>
                    <option value="latest" @selected($options['kinds'] === 'latest')>{{ __('Only the newest backup') }}</option>
                </x-ui.field>
            </div>
            <label class="check"><input type="hidden" name="system" value="0"><input type="checkbox" name="system" value="1" @checked($options['system'] === '1')><span>{{ __('Also the server configuration') }}</span></label>
            <p class="muted small">{{ __('Every run takes the items that were tested longest ago, so after a few runs every website, mail domain and database has been restored once. Every run also checks that everything on this server has a backup.') }}</p>
            <h3>{{ __('Alerts') }}</h3>
            <div class="form-grid">
                <x-ui.field name="max_age_hours" type="number" min="0" max="2000" :label="__('Warn when the newest backup is older than (hours)')" :value="$options['max_age_hours']" :help="__('0 = do not check.')" />
                <x-ui.field name="notify" type="select" :label="__('Email')">
                    <option value="problems" @selected($options['notify'] === 'problems')>{{ __('When a test fails') }}</option>
                    <option value="warnings" @selected($options['notify'] === 'warnings')>{{ __('On failures and warnings') }}</option>
                    <option value="always" @selected($options['notify'] === 'always')>{{ __('After every test') }}</option>
                    <option value="never" @selected($options['notify'] === 'never')>{{ __('Never') }}</option>
                </x-ui.field>
                <x-ui.field name="mail_to" :label="__('Send to')" :value="$options['mail_to']" placeholder="beheer@example.nl" :help="__('Comma separated. Empty = every administrator.')" />
            </div>
            <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
        </form>
    </x-ui.card>
</x-layouts.admin>
