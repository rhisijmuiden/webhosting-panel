<x-layouts.admin :title="__('Server management')">
    @include('server-admin::partials.styles')
    <script src="{{ route('server-admin.assets.js') }}" defer></script>
    <div class="page-head">
        <div><h1>{{ __('Server management') }}</h1>
            <p class="sub">{{ __('Debian updates, reboots, the maintenance window and scheduled maintenance of :host.', ['host' => $hostname]) }}</p></div>
        <div class="actions">
            <form method="POST" action="{{ route('admin.server.refresh') }}">@csrf<x-ui.button icon="refresh">{{ __('Check for updates') }}</x-ui.button></form>
            <button type="button" class="btn btn-dark" data-open-dialog="sv-reboot" @disabled(! $status || $session)><x-icon name="refresh" />{{ __('Reboot server') }}</button>
        </div>
    </div>
    @include('server-admin::partials.tabs')

    @if ($agentError)
        <div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __('The server agent does not answer: :error', ['error' => $agentError]) }}</div>
    @endif

    @if ($session)
        <div class="alert alert-info" role="status" data-run-poll="{{ route('admin.server.run.status', $session) }}" data-reload-on-done="1">
            <x-icon name="refresh" />
            <div><strong>{{ __('Maintenance is running') }}</strong>
                <p class="small">{{ \Rhis\ServerAdmin\Tasks::trigger($session->trigger) }} · {{ __('started :time', ['time' => $session->started_at->diffForHumans()]) }} ·
                    <a href="{{ route('admin.server.run', $session) }}">{{ __('Follow it') }}</a></p></div>
        </div>
    @endif

    @php
        $reasons = $status['reboot_reasons'] ?? [];
        $pkgs = collect($updates['packages'] ?? []);
        $sec = $pkgs->where('security', true);
        $maxScore = $scan['max_score'] ?? null;
    @endphp

    <section class="sv-hero" aria-label="{{ __('Status') }}">
        <div class="sv-tile {{ $sec->count() ? 'sv-warn' : 'sv-ok' }}">
            <span class="k">{{ __('Security updates') }}</span>
            <span class="v">{{ $updates ? $sec->count() : '–' }}</span>
            <span class="h">{{ $updates ? __(':n updates in total', ['n' => $pkgs->count()]) : __('Not checked yet') }}</span>
        </div>
        <div class="sv-tile {{ $maxScore === null ? 'sv-info' : ($maxScore >= $o->get('critical_threshold') ? 'sv-bad' : ($maxScore >= 7 ? 'sv-warn' : 'sv-ok')) }}">
            <span class="k">{{ __('Highest CVSS score') }}</span>
            <span class="v">{{ $maxScore ?? '–' }}</span>
            <span class="h">{{ $scan ? __('Checked :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($scan['at'])->diffForHumans()]) : __('Not checked yet') }}</span>
        </div>
        <div class="sv-tile {{ $reasons ? 'sv-warn' : 'sv-ok' }}">
            <span class="k">{{ __('Reboot') }}</span>
            <span class="v">{{ $status ? ($reasons ? __('Needed') : __('Not needed')) : '–' }}</span>
            <span class="h">{{ $reasons ? ($nextWindow && $o->get('window_reboot') ? __('Next maintenance window: :time', ['time' => $nextWindow->isoFormat('dd D MMM HH:mm')]) : __('Reboot by hand or turn on reboots in the window')) : __('Kernel :k', ['k' => $status['kernel'] ?? '?']) }}</span>
        </div>
        <div class="sv-tile sv-info">
            <span class="k">{{ __('Up for') }}</span>
            <span class="v">{{ isset($status['uptime_seconds']) ? \Carbon\CarbonInterval::seconds($status['uptime_seconds'])->cascade()->forHumans(['parts' => 2, 'short' => true]) : '–' }}</span>
            <span class="h">{{ $status['os'] ?? '' }}</span>
        </div>
    </section>

    @if ($reasons)
        <div class="alert alert-warn" role="status"><x-icon name="info" />
            <div><strong>{{ __('A reboot finishes the installed updates') }}</strong>
                <ul class="sv-reasons">
                    @foreach ($reasons as $r)
                        <li>{{ match ($r['kind']) { 'kernel' => __('New kernel installed: :v', ['v' => $r['detail']]), 'libraries' => __('Services still use replaced libraries: :v', ['v' => $r['detail']]), default => __('Packages ask for a reboot: :v', ['v' => $r['detail']]) } }}</li>
                    @endforeach
                </ul></div>
        </div>
    @endif

    <div class="bento">
        <x-ui.card class="span-8" :title="__('Waiting updates')" flush>
            <x-slot:actions>
                @if ($upgrade)
                    <a class="btn btn-sm" href="{{ route('admin.server.run', $upgrade) }}">{{ __('Installing… follow it') }}</a>
                @elseif ($pkgs->isNotEmpty())
                    @if ($sec->isNotEmpty())
                        <form method="POST" action="{{ route('admin.server.upgrade') }}">@csrf<input type="hidden" name="mode" value="security">
                            <x-ui.button variant="primary" size="sm" :disabled="(bool) $session">{{ __('Install security updates') }}</x-ui.button></form>
                    @endif
                    <form method="POST" action="{{ route('admin.server.upgrade') }}">@csrf<input type="hidden" name="mode" value="all">
                        <x-ui.button size="sm" :disabled="(bool) $session">{{ __('Install all') }}</x-ui.button></form>
                @endif
            </x-slot:actions>
            @if (! $updates)
                <x-ui.empty icon="refresh">{{ __('Click "Check for updates" to fetch the list.') }}</x-ui.empty>
            @elseif ($pkgs->isEmpty())
                <x-ui.empty icon="check">{{ __('Everything is up to date') }}</x-ui.empty>
            @else
                <x-ui.table>
                    <x-slot:head><th>{{ __('Package') }}</th><th>{{ __('Installed') }}</th><th>{{ __('New') }}</th><th>{{ __('Kind') }}</th><th>CVE</th></x-slot:head>
                    @foreach ($pkgs->sortByDesc(fn ($p) => [$p['security'], $scores[$p['name']]['max_score'] ?? 0]) as $p)
                        @php $sc = $scores[$p['name']]['max_score'] ?? null; $cves = $scores[$p['name']]['cves'] ?? []; @endphp
                        <tr>
                            <td class="strong">{{ $p['name'] }}@if ($p['kernel']) <x-ui.pill status="info" :dot="false">{{ __('Kernel') }}</x-ui.pill>@endif</td>
                            <td class="mono small">{{ $p['current'] ?? __('new') }}</td>
                            <td class="mono small">{{ $p['candidate'] }}</td>
                            <td>@if ($p['security'])<x-ui.pill status="warn">{{ __('Security') }}</x-ui.pill>@else<span class="muted small">{{ __('Regular') }}</span>@endif</td>
                            <td>@if ($sc !== null)<span class="sv-cvss {{ $sc >= 9 ? 'crit' : ($sc >= 7 ? 'high' : ($sc >= 4 ? 'med' : '')) }}" title="{{ collect($cves)->pluck('id')->implode(', ') }}">{{ number_format($sc, 1) }}</span>
                                <span class="muted small">{{ count($cves) }} CVE</span>@else<span class="muted small">–</span>@endif</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <div class="span-4 sv-stack">
            <x-ui.card :title="__('Automatic maintenance')">
                <dl class="kv">
                    <dt>{{ __('Window') }}</dt><dd>{{ $o->get('window_enabled') ? $o->get('window_start').'–'.$o->get('window_end') : __('Off') }}</dd>
                    <dt>{{ __('Next') }}</dt><dd>{{ $nextWindow?->isoFormat('dd D MMM HH:mm') ?? '–' }}</dd>
                    <dt>{{ __('Installs') }}</dt><dd>{{ $o->get('window_install') === 'all' ? __('All updates') : __('Security updates') }}</dd>
                    <dt>{{ __('Reboot') }}</dt><dd>{{ $o->get('window_reboot') ? __('When needed') : __('Never automatically') }}</dd>
                    <dt>{{ __('Critical CVEs') }}</dt><dd>{{ $o->get('critical_enabled') ? __('Patched at once from CVSS :s', ['s' => $o->get('critical_threshold')]) : __('Off') }}</dd>
                </dl>
                <div class="actions"><x-ui.button :href="route('admin.server.maintenance')" icon="settings" size="sm">{{ __('Change') }}</x-ui.button></div>
            </x-ui.card>
            <x-ui.card :title="__('Recent activity')" flush>
                @include('server-admin::partials.runs', ['runs' => $runs])
            </x-ui.card>
        </div>
    </div>

    <dialog class="modal" id="sv-reboot" aria-labelledby="sv-reboot-title">
        <form method="POST" action="{{ route('admin.server.reboot') }}">
            @csrf
            <div class="modal-head"><h2 id="sv-reboot-title">{{ __('Reboot :host?', ['host' => $hostname]) }}</h2>
                <button type="button" class="btn btn-ghost btn-sm" data-close-dialog aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <p>{{ __('All websites, mail and the panel are offline for one or two minutes. Every website is checked before and after the reboot; you get a mail when something does not come back.') }}</p>
                <p class="small muted">{{ __('Switching the server off is not possible from the panel, on purpose: a server that is off can only be started again from the hosting provider.') }}</p>
                <x-ui.field name="confirm" :label="__('Type :host to confirm', ['host' => $hostname])" autocomplete="off" data-confirm-match="{{ $hostname }}" />
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-close-dialog>{{ __('Cancel') }}</button>
                <x-ui.button variant="primary" data-confirm-submit disabled>{{ __('Reboot now') }}</x-ui.button>
            </div>
        </form>
    </dialog>
    @if ($errors->has('confirm'))<span data-open-on-load="sv-reboot" hidden></span>@endif
</x-layouts.admin>
