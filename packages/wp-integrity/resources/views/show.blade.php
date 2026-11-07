@php
    use Illuminate\Support\Number;
    $r = fn (string $name) => route($area.'.wp-integrity.'.$name, $install);
    $changed = collect($components)->where('status', 'changed');
    $clean = collect($components)->where('status', 'ok');
    $unverifiable = collect($components)->where('status', 'unverifiable');
    $failed = collect($components)->where('status', 'error');
    $running = $job && $job['status'] === 'running';
    $data = $report->report ?? [];
    $restore = $report->last_restore;
    $openFiles = (int) $changed->sum('open_files');
    $base = fn ($c, $f) => ($c['base'] ? $c['base'].'/' : '').$f['file'];
    // The file manager works from the site root; the report knows where the install lives.
    $rootRel = trim((string) ($data['root_rel'] ?? 'public'), '/.');
    $folderOf = fn (string $p) => trim($rootRel.'/'.(str_contains($p, '/') ? substr($p, 0, strrpos($p, '/')) : ''), '/');
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('File integrity').' · '.$install->label()">
    @include('wp-integrity::partials.styles')
    <div class="wpi">
    <div class="page-head">
        <div>
            <div class="crumbs">
                @if ($wordpressUrl)<a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / <a href="{{ $wordpressUrl }}">{{ $install->label() }}</a>@else{{ $install->label() }}@endif
                / {{ __('File integrity') }}
            </div>
            <div class="actions"><h1>{{ __('File integrity') }}</h1>@include('wp-integrity::partials.status', ['report' => $report])</div>
            <p class="sub">
                @if ($report->checked_at)
                    {{ __('WordPress :version · compared with the original files on WordPress.org :time', ['version' => $data['version'] ?? $install->version ?? '?', 'time' => $report->checked_at->diffForHumans()]) }}
                @else
                    {{ __('Compares the files of WordPress and your plugins with the original files on WordPress.org.') }}
                @endif
            </p>
        </div>
        <div class="actions">
            <form method="POST" action="{{ $r('check') }}">@csrf<x-ui.button icon="refresh" :disabled="$running">{{ __('Check now') }}</x-ui.button></form>
            @if ($openFiles > 0)
                <form method="POST" action="{{ $r('restore') }}" onsubmit="return confirm(@js(__('Put the original version of every changed file back? Files that should not be there are moved to a quarantine folder outside your website, so nothing is lost.')))">
                    @csrf<input type="hidden" name="all" value="1">
                    <x-ui.button variant="primary" icon="backup" :disabled="$running">{{ __('Restore all original files') }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>

    @if ($errors->has('agent'))<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $errors->first('agent') }}</div>@endif

    @if ($running)
        <div class="alert alert-info" role="status" aria-live="polite"><x-icon name="refresh" />
            <div><strong>{{ $report->job_kind === 'restore' ? __('Putting the original files back…') : __('Checking the files…') }}</strong>
                <p class="small">{{ __('This page refreshes by itself.') }}</p>
                @if ($job['log'])<pre class="wpi-log">{{ implode("\n", $job['log']) }}</pre>@endif</div>
        </div>
        <script>setTimeout(() => location.reload(), 4000);</script>
    @elseif ($job && $job['status'] === 'failed')
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('That did not work') }}</strong><p class="small mono">{{ $job['error'] }}</p></div></div>
    @endif

    @if ($restore && ! $running)
        <div class="alert {{ $restore['ok'] && empty($restore['errors']) ? 'alert-ok' : 'alert-warn' }}" role="status"><x-icon name="{{ $restore['ok'] ? 'check' : 'info' }}" />
            <div>
                <strong>{{ __('Last restore :time', ['time' => \Illuminate\Support\Carbon::parse($restore['at'])->diffForHumans()]) }}</strong>
                <p class="small">{{ __(':restored original files put back, :quarantined files moved to quarantine.', ['restored' => $restore['restored'], 'quarantined' => $restore['quarantined']]) }}
                    @if ($restore['quarantine_dir']) {{ __('Quarantine folder:') }} <code>{{ $restore['quarantine_dir'] }}</code>@endif
                    @if ($restore['malware_rescanned']) {{ __('The website was scanned for malware again.') }}@endif</p>
                @foreach (array_filter([$restore['error'] ?? null, ...($restore['errors'] ?? [])]) as $err)<p class="small mono">{{ $err }}</p>@endforeach
            </div>
        </div>
    @endif

    @if (! $report->checked_at && ! $running)
        <x-ui.card>
            <x-ui.empty icon="shield">{{ __('This website has not been checked yet. The check runs every night; you can also start it now.') }}
                <x-slot:action><form method="POST" action="{{ $r('check') }}">@csrf<x-ui.button variant="dark" icon="refresh">{{ __('Check now') }}</x-ui.button></form></x-slot:action>
            </x-ui.empty>
        </x-ui.card>
    @endif

    @if ($report->checked_at)
    <section class="bento" style="margin-top:20px" aria-label="{{ __('Totals') }}">
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="wpi-kpi-icon {{ $openFiles ? 'is-warn' : 'is-ok' }}"><x-icon name="{{ $openFiles ? 'edit' : 'check' }}" /></span>{{ __('Changed files') }}</span>
            <strong class="kpi-value">{{ $openFiles }}</strong>
            <span class="kpi-foot">{{ trans_choice(':count part|:count parts', $changed->count(), ['count' => $changed->count()]) }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="wpi-kpi-icon {{ $report->malware_files ? 'is-bad' : 'is-ok' }}"><x-icon name="shield" /></span>{{ __('Malware in these files') }}</span>
            <strong class="kpi-value">{{ $report->malware_files }}</strong>
            <span class="kpi-foot">{{ ! empty($data['malware_scanned_at']) ? __('Malware scan :time', ['time' => \Illuminate\Support\Carbon::parse($data['malware_scanned_at'])->diffForHumans()]) : __('No malware scan yet') }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="wpi-kpi-icon is-ok"><x-icon name="check" /></span>{{ __('Verified and original') }}</span>
            <strong class="kpi-value">{{ $clean->count() }}<span class="muted" style="font-size:16px"> / {{ count($components) }}</span></strong>
            <span class="kpi-foot">{{ __('WordPress core and plugins') }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="wpi-kpi-icon {{ $unverifiable->count() ? 'is-warn' : '' }}"><x-icon name="info" /></span>{{ __('Cannot be verified') }}</span>
            <strong class="kpi-value">{{ $unverifiable->count() + $failed->count() }}</strong>
            <span class="kpi-foot">{{ __('Commercial or custom plugins, themes') }}</span>
        </div>
    </section>
    @endif

    @foreach ($changed as $c)
        @php $key = $c['type'].':'.$c['slug']; @endphp
        <x-ui.card class="wpi-comp is-{{ $c['type'] }} {{ $c['malware_files'] ? 'has-malware' : '' }}" style="margin-top:20px" flush>
            <form method="POST" action="{{ $r('restore') }}" x-data="{ n: {{ collect($c['files'])->reject(fn ($f) => $f['accepted'])->count() }} }"
                  x-on:change="n = $el.querySelectorAll('input[name=\'files[]\']:checked').length">
                @csrf<input type="hidden" name="component" value="{{ $key }}">
                <div class="card-head" style="padding:18px 20px 0">
                    <div>
                        <div class="eyebrow">{{ $c['type'] === 'core' ? __('WordPress core') : __('Plugin') }}@if (isset($c['active']) && ! $c['active']) · {{ __('switched off') }}@endif</div>
                        <h2>{{ $c['name'] }} <span class="muted mono" style="font-weight:500;font-size:14px">{{ $c['version'] }}</span></h2>
                    </div>
                    <div class="actions">
                        @if ($c['malware_files'])<span class="wpi-mal"><x-icon name="shield" style="width:13px;height:13px" />{{ trans_choice(':count malware file|:count malware files', $c['malware_files'], ['count' => $c['malware_files']]) }}</span>@endif
                        <x-ui.pill status="warn">{{ trans_choice(':count changed file|:count changed files', $c['open_files'], ['count' => $c['open_files']]) }}</x-ui.pill>
                    </div>
                </div>
                <x-ui.table>
                    <x-slot:head><th style="width:36px"><span class="sr-only">{{ __('Select') }}</span></th><th>{{ __('File') }}</th><th>{{ __('What is wrong') }}</th><th>{{ __('Last changed') }}</th><th class="num">{{ __('Size') }}</th></x-slot:head>
                    @foreach ($c['files'] as $f)
                        @php $path = $base($c, $f); @endphp
                        <tr class="{{ $f['accepted'] ? 'is-accepted' : '' }}">
                            <td><input type="checkbox" name="files[]" value="{{ $f['file'] }}" @checked(! $f['accepted']) aria-label="{{ __('Select :file', ['file' => $f['file']]) }}"></td>
                            <td><span class="wpi-file">{{ $path }}</span>
                                @if (! empty($f['malware']))<br><span class="wpi-mal" title="{{ __('Found by the malware scan') }}">{{ __('Malware: :signature', ['signature' => $f['malware']]) }}</span>@endif
                                @if ($f['accepted'])<br><span class="muted small">{{ __('Marked as an intended change') }}</span>@endif
                                @if ($fileManager && $f['issue'] !== 'missing')<br><a class="small" href="{{ $fileManager($folderOf($path)) }}">{{ __('Open folder') }}</a>@endif
                            </td>
                            <td>@include('wp-integrity::partials.issue', ['issue' => $f['issue']])</td>
                            <td class="small">{{ isset($f['mtime']) ? \Illuminate\Support\Carbon::createFromTimestamp($f['mtime'])->diffForHumans() : '–' }}</td>
                            <td class="num small">{{ isset($f['size']) ? Number::fileSize($f['size'], 1) : '–' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
                @if (($c['total_files'] ?? 0) > count($c['files']))
                    <p class="small muted" style="padding:0 20px">{{ __('Only the first :shown of :total files are listed.', ['shown' => count($c['files']), 'total' => $c['total_files']]) }}</p>
                @endif
                <div class="actions" style="padding:14px 20px 18px;flex-wrap:wrap">
                    <button type="submit" class="btn btn-dark" x-bind:disabled="n === 0" @disabled($running)
                            onclick="return confirm(@js(__('Put the original version of the selected files back? Selected files that should not be there are moved to quarantine.')))"><x-icon name="backup" />{{ __('Restore selected') }}</button>
                    <button type="submit" class="btn" formaction="{{ $r('accept') }}" name="action" value="accept" x-bind:disabled="n === 0">{{ __('Mark as intended change') }}</button>
                    @if ($c['accepted_files'] > 0)
                        <button type="submit" class="btn btn-sm" formaction="{{ $r('accept') }}" name="action" value="undo">{{ __('Undo for selected') }}</button>
                    @endif
                    <span class="small muted">{{ $c['type'] === 'core'
                        ? __('Changed WordPress core files are almost never intended. Restore them unless you know who changed them and why.')
                        : __('Plugin files change when someone edits the plugin. If that was not you or your developer, restore them.') }}</span>
                </div>
            </form>
        </x-ui.card>
    @endforeach

    @if (! empty($data['malware_elsewhere']))
        <x-ui.card :title="__('Malware outside WordPress and plugin files')" style="margin-top:20px" flush>
            <p class="small muted" style="padding:0 20px">{{ __('The malware scan found these files. They are not part of WordPress or a plugin from WordPress.org, so there is no original to put back. Remove them, or ask us for help.') }}</p>
            <x-ui.table>
                <x-slot:head><th>{{ __('File') }}</th><th>{{ __('Detected as') }}</th></x-slot:head>
                @foreach ($data['malware_elsewhere'] as $m)
                    <tr><td><span class="wpi-file">{{ $m['file'] }}</span>@if ($fileManager)<br><a class="small" href="{{ $fileManager($folderOf($m['file'])) }}">{{ __('Open folder') }}</a>@endif</td><td><span class="wpi-mal">{{ $m['signature'] }}</span></td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($report->checked_at)
    <section class="grid grid-2" style="align-items:start;margin-top:20px">
        <x-ui.card :title="__('Checked')">
            <ul class="checks">
                @foreach (collect($components)->where('status', '!=', 'changed') as $c)
                    <li class="{{ $c['status'] === 'ok' ? 'is-ok' : ($c['status'] === 'unverifiable' ? 'is-warn' : 'is-bad') }}">
                        <x-icon name="{{ $c['status'] === 'ok' ? 'check' : 'info' }}" />
                        <span><span class="strong">{{ $c['name'] }}</span> <span class="muted small mono">{{ $c['version'] }}</span>
                            @if (($c['accepted_files'] ?? 0) > 0)<br><span class="small muted">{{ trans_choice(':count intended change|:count intended changes', $c['accepted_files'], ['count' => $c['accepted_files']]) }}</span>@endif
                            @if ($c['status'] !== 'ok' && $c['message'])<br><span class="small muted">{{ __($c['message']) }}</span>@endif</span>
                        <small>{{ match ($c['status']) { 'ok' => __('Original'), 'unverifiable' => __('Cannot be verified'), default => __('Check failed') } }}</small>
                    </li>
                @endforeach
            </ul>
            @foreach ($data['errors'] ?? [] as $err)<p class="small mono muted">{{ $err }}</p>@endforeach
        </x-ui.card>
        <x-ui.card :title="__('How this works')">
            <div class="small" style="display:flex;flex-direction:column;gap:12px">
                <p>{{ __('Every night the server compares each file of WordPress and of plugins from WordPress.org with the official checksums. A file that differs, is missing or should not be there is listed here.') }}</p>
                <p>{{ __('Hackers often hide code in core or plugin files. Restoring downloads the original version from WordPress.org and puts it back; files that should not be there go to a quarantine folder outside your website.') }}</p>
                <p class="muted">{{ __('Themes and commercial plugins have no public checksums. The malware scan still checks them every night.') }}</p>
            </div>
        </x-ui.card>
    </section>
    @endif
    </div>
</x-dynamic-component>
