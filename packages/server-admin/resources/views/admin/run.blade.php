<x-layouts.admin :title="$run->label()">
    @include('server-admin::partials.styles')
    <script src="{{ route('server-admin.assets.js') }}" defer></script>
    <div class="page-head">
        <div><p class="crumbs"><a href="{{ route('admin.server.index') }}">{{ __('Server management') }}</a></p>
            <h1>{{ $run->label() }}</h1>
            <p class="sub">{{ \Rhis\ServerAdmin\Tasks::trigger($run->trigger) }} · {{ $run->started_at->setTimezone($o->timezone())->isoFormat('LLL') }}@if ($run->user) · {{ $run->user->name }}@endif</p></div>
        <div class="actions">
            @if ($run->task === 'maintenance' && $run->isRunning())
                <form method="POST" action="{{ route('admin.server.cancel', $run) }}">@csrf
                    <x-ui.button>{{ ($run->result['phase'] ?? '') === 'rebooting' ? __('Cancel the reboot') : __('Stop') }}</x-ui.button></form>
            @endif
        </div>
    </div>
    @include('server-admin::partials.tabs')

    <div class="sv-stack" data-run-poll="{{ route('admin.server.run.status', $run) }}" @if ($run->isRunning()) data-reload-on-done="1" @endif>
        <x-ui.card>
            <div class="sv-inline" style="justify-content:space-between;align-items:center">
                <div>
                    <x-ui.pill :status="$run->status" data-run-status>{{ $run->isRunning() ? __('Running') : ($run->status === 'succeeded' ? __('Done') : __('Failed')) }}</x-ui.pill>
                    <span class="small" data-run-summary>{{ $run->summary }}</span>
                </div>
                @if ($run->durationLabel())<span class="muted small">{{ __('Took :t', ['t' => $run->durationLabel()]) }}</span>@endif
            </div>
            @if ($run->task === 'maintenance')
                @php
                    $phases = $run->trigger === 'manual'
                        ? ['reboot_precheck' => __('Check websites'), 'rebooting' => __('Reboot'), 'postcheck' => __('Check again')]
                        : ['precheck' => __('Check websites'), 'upgrading' => __('Install updates'), 'rebooting' => __('Reboot when needed'), 'postcheck' => __('Check again')];
                    $now = $run->result['phase'] ?? null;
                    if ($now === 'postcheck_retry') { $now = 'postcheck'; }
                    $keys = array_keys($phases); $pos = $run->isRunning() ? array_search($now, $keys, true) : count($keys);
                @endphp
                <ol class="sv-phase" aria-label="{{ __('Steps') }}" style="margin-top:14px">
                    @foreach ($phases as $k => $label)
                        @php $i = array_search($k, $keys, true); @endphp
                        <li class="{{ $pos !== false && $i < $pos ? 'done' : ($i === $pos ? 'now' : '') }}">{{ $label }}</li>
                    @endforeach
                </ol>
                @if ($now === 'rebooting' && $run->isRunning())
                    <p class="alert alert-info" style="margin-top:14px"><x-icon name="refresh" />{{ __('The server is rebooting. This page keeps checking and updates by itself when it is back.') }}</p>
                @endif
            @endif
        </x-ui.card>
        <x-ui.card :title="__('Log')">
            <div class="log sv-log" data-run-log aria-live="polite">{{ $run->log ?: '…' }}</div>
        </x-ui.card>
        @if ($run->task === 'security_check' && ! empty($run->result['packages']))
            <x-ui.card :title="__('Security updates and their CVEs')" flush>
                <x-ui.table>
                    <x-slot:head><th>{{ __('Package') }}</th><th>{{ __('New') }}</th><th>CVE</th></x-slot:head>
                    @foreach ($run->result['packages'] as $p)
                        <tr><td class="strong">{{ $p['name'] }}</td><td class="mono small">{{ $p['candidate'] }}</td>
                            <td>@forelse ($p['cves'] ?? [] as $c)
                                    <span class="sv-cvss {{ ($c['score'] ?? 0) >= 9 ? 'crit' : (($c['score'] ?? 0) >= 7 ? 'high' : (($c['score'] ?? 0) >= 4 ? 'med' : '')) }}">{{ $c['id'] }} · {{ $c['score'] ?? '?' }}</span>
                                @empty<span class="muted small">{{ __('No CVEs in the changelog') }}</span>@endforelse</td></tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>
</x-layouts.admin>
