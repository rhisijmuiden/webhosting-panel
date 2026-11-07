<x-layouts.admin :title="__('Security center')">
    @include('vulnerabilities::partials.styles')
    @php
        $okCount = collect($posture['checks'])->where('status', 'ok')->count();
        $gradeLabel = match ($posture['grade']) { 'ok' => __('Well protected'), 'warn' => __('Needs attention'), 'bad' => __('At risk'), default => __('Unknown') };
        $sevs = ['critical' => __('Critical'), 'high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low'), 'malware' => __('Malware')];
        $sum = max(1, $totals['critical'] + $totals['high'] + $totals['medium'] + $totals['low'] + $totals['malware']);
        $initials = fn ($name) => collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    @endphp

    <div class="page-head">
        <div>
            <p class="eyebrow">
                @if ($posture['error'])<span class="live is-bad">{{ __('Server agent not reachable') }}</span>@else<span class="live">{{ __('Protection active') }}</span>@endif
                <span>· {{ __('Source: :source', ['source' => $source === 'wordfence' ? 'Wordfence Intelligence' : 'WPVulnerability.net']) }}</span>
            </p>
            <h1>{{ __('Security center') }}</h1>
            <p class="sub">{{ __('How the server is doing, which customers run the most risk, and what was found on their websites.') }}</p>
        </div>
        <div class="actions">
            <x-ui.button :href="route('admin.vulns.settings')" icon="settings">{{ __('Settings') }}</x-ui.button>
            <form method="POST" action="{{ route('admin.vulns.scan') }}">@csrf<x-ui.button variant="primary" icon="refresh">{{ __('Check all websites now') }}</x-ui.button></form>
        </div>
    </div>
    @if (! $mailEnabled)<div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('Customer mails are switched off. Problems are only shown in the panel.') }}</div>@endif
    @if ($posture['error'])<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $posture['error'] }}</div>@endif

    <section class="bento">
        {{-- Server score --}}
        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Server') }}</h2>
                <form method="POST" action="{{ route('admin.vulns.posture') }}">@csrf<button class="btn btn-sm btn-ghost" type="submit" title="{{ __('Check again') }}"><x-icon name="refresh" /><span class="sr-only">{{ __('Check again') }}</span></button></form></div>
            <div class="score">
                <x-ui.ring :value="$posture['score'] ?? 0" :tone="in_array($posture['grade'], ['ok', 'warn', 'bad'], true) ? $posture['grade'] : 'warn'"
                           aria-label="{{ __('Security score :n of 100', ['n' => $posture['score'] ?? '–']) }}"><strong>{{ $posture['score'] ?? '–' }}</strong><small>{{ __('of 100') }}</small></x-ui.ring>
                <div class="score-text">
                    <x-ui.pill :status="$posture['grade'] ?? 'neutral'">{{ $gradeLabel }}</x-ui.pill>
                    <p class="muted small">{{ __(':ok of :total checks are fine', ['ok' => $okCount, 'total' => count($posture['checks'])]) }}</p>
                </div>
            </div>
        </div>

        {{-- Problems by severity --}}
        <div class="card span-8">
            <div class="card-head"><h2>{{ __('Open problems on websites') }}</h2><x-ui.button size="sm" :href="route('admin.vulns.findings')">{{ __('View all') }}</x-ui.button></div>
            <div class="sev-tiles sev-tiles-5">
                @foreach ($sevs as $s => $label)
                    <a class="sev-tile sev-{{ $s }}" href="{{ route('admin.vulns.findings', ['severity' => $s]) }}"><span>{{ $label }}</span><strong>{{ $totals[$s] }}</strong></a>
                @endforeach
            </div>
            @if ($totals['total'] > 0)
                <div class="sev-stack" role="img" aria-label="{{ __(':n open problems', ['n' => $totals['total']]) }}">
                    @foreach (array_keys($sevs) as $s)@if ($totals[$s] > 0)<i class="sev-{{ $s }}" style="flex:{{ $totals[$s] }}"></i>@endif @endforeach
                </div>
            @endif
            <p class="muted small">{{ trans_choice(':count website affected|:count websites affected', $totals['sites']) }} · {{ __(':n without a fix yet', ['n' => $totals['no_fix']]) }}</p>
        </div>

        {{-- Customers most at risk --}}
        <div class="card span-8 card-flush">
            <div class="card-head"><h2>{{ __('Customers most at risk') }}</h2><span class="muted small">{{ __('ranked by risk score') }}</span></div>
            @if ($ranking->isEmpty())
                <x-ui.empty icon="shield">{{ __('No customer has open security problems.') }}</x-ui.empty>
            @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('Risk') }}</th><th>{{ __('Problems') }}</th><th>{{ __('Worst') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                @foreach ($ranking as $r)
                    @php $lvl = $r['level'] === 'none' ? 'low' : $r['level']; $tone = ['critical' => 'bad', 'high' => 'bad', 'medium' => 'warn', 'low' => 'ok'][$lvl] ?? 'ok'; @endphp
                    <tr>
                        <td><div class="who"><span class="avatar avatar-soft" aria-hidden="true">{{ $initials($r['customer']->displayName()) }}</span>
                            <span><a class="strong" href="{{ route('admin.vulns.findings', ['customer' => $r['customer']->id]) }}">{{ $r['customer']->displayName() }}</a>
                            <small class="muted">{{ trans_choice(':count website|:count websites', $r['sites']) }}</small></span></div></td>
                        <td><div class="risk"><span class="bar"><span class="{{ $tone }}" style="width:{{ max(3, $r['score']) }}%"></span></span><strong>{{ $r['score'] }}</strong></div></td>
                        <td><div class="tags">
                            @if ($r['malware'])<span class="vx-chip sev-malware">{{ trans_choice(':count malware file|:count malware files', $r['malware']) }}</span>@endif
                            @foreach (['critical', 'high', 'medium', 'low'] as $s)
                                @if ($r['counts'][$s])<span class="vx-chip sev-{{ $s }}">{{ $r['counts'][$s] }} {{ __(ucfirst($s)) }}</span>@endif
                            @endforeach
                            @if ($r['outdated'])<span class="pill pill-neutral">{{ trans_choice(':count outdated part|:count outdated parts', $r['outdated']) }}</span>@endif
                        </div></td>
                        <td class="small">
                            @if ($r['top'])
                                @if ($r['top']->cvss_score)<span class="cvss cvss-{{ $r['top']->severity }}">{{ number_format($r['top']->cvss_score, 1) }}</span>@endif
                                <span class="nowrap">{{ $r['top']->isMalware() ? __('Suspicious file') : $r['top']->componentLabel() }}</span>
                                @if ($r['top']->cve)<span class="mono muted block">{{ $r['top']->cve }}</span>@endif
                            @else<span class="muted">–</span>@endif
                        </td>
                        <td class="num"><div class="actions" style="justify-content:flex-end;flex-wrap:nowrap">
                            <a class="btn btn-sm btn-ghost icon-btn" href="{{ route('admin.vulns.preview', $r['customer']) }}" target="_blank" title="{{ __('Preview the mail') }}"><x-icon name="mail" /><span class="sr-only">{{ __('Preview the mail') }}</span></a>
                            <x-ui.button size="sm" :href="route('admin.vulns.findings', ['customer' => $r['customer']->id])">{{ __('Fix') }}</x-ui.button>
                        </div></td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </div>

        {{-- Server checks --}}
        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Server checks') }}</h2><span class="muted small">{{ __(':ok of :total checks are fine', ['ok' => $okCount, 'total' => count($posture['checks'])]) }}</span></div>
            <ul class="checks">
                @foreach ($posture['checks'] as $c)
                    <li class="is-{{ $c['status'] }}"><x-icon :name="$c['status'] === 'ok' ? 'check' : 'info'" />
                        <span>{{ $c['label'] }} <span class="sr-only">({{ $c['status'] }})</span></span><small>{{ $c['detail'] }}</small></li>
                @endforeach
            </ul>
        </div>

        @includeIf('waf::admin.security-center-card')

        {{-- Last days --}}
        <div class="card span-12">
            <div class="kpi-row">
                <div><span>{{ __('Attacks detected, 24 h') }}</span><strong>{{ $posture['alerts_24h'] ?? '–' }}</strong></div>
                <div><span>{{ __('IP addresses blocked') }}</span><strong>{{ $posture['blocked'] ?? '–' }}</strong></div>
                <div><span>{{ __('Problems solved, 30 days') }}</span><strong>{{ $totals['resolved_30d'] }}</strong></div>
                <div><span>{{ __('Customers mailed, 30 days') }}</span><strong>{{ $notices->where('sent_at', '>=', now()->subDays(30))->count() }}</strong></div>
            </div>
        </div>
    </section>

    <x-ui.card :title="__('Most urgent problems')" flush>
        @if ($latest->isEmpty())
            <x-ui.empty icon="shield">{{ __('Nothing found. Websites are checked every night.') }}</x-ui.empty>
        @else
            @include('vulnerabilities::admin.table', ['findings' => $latest])
        @endif
    </x-ui.card>

    @if ($notices->isNotEmpty())
    <x-ui.card :title="__('Recent security mails')" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Sent') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Type') }}</th><th>{{ __('To') }}</th><th class="num">{{ __('Problems') }}</th></x-slot:head>
            @foreach ($notices as $n)
                <tr><td class="small">{{ $n->sent_at->diffForHumans() }}</td><td>{{ $n->customer?->displayName() }}</td>
                    <td><x-ui.pill :status="$n->kind === 'alert' ? 'warn' : 'info'">{{ ['alert' => __('Alert'), 'reminder' => __('Reminder'), 'manual' => __('Sent by hand')][$n->kind] ?? $n->kind }}</x-ui.pill></td>
                    <td class="small">{{ implode(', ', $n->recipients) }}</td><td class="num">{{ count($n->finding_ids) }}</td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    @endif
</x-layouts.admin>
