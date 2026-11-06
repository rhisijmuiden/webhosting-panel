<x-layouts.admin :title="__('Security overview')">
    @include('vulnerabilities::partials.styles')
    <div class="page-head">
        <div>
            <h1>{{ __('Security overview') }}</h1>
            <p class="sub">{{ __('How the server is doing, which customers run the most risk, and what was found on their websites.') }}</p>
        </div>
        <div class="actions">
            <x-ui.button :href="route('admin.vulns.settings')" icon="settings">{{ __('Settings') }}</x-ui.button>
            <form method="POST" action="{{ route('admin.vulns.scan') }}">@csrf<x-ui.button variant="primary" icon="refresh">{{ __('Check all websites now') }}</x-ui.button></form>
        </div>
    </div>
    @if (! $mailEnabled)<div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('Customer mails are switched off. Problems are only shown in the panel.') }}</div>@endif
    @if ($posture['error'])<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $posture['error'] }}</div>@endif

    <section class="vx-hero">
        {{-- Server score --}}
        <x-ui.card :title="__('Server')">
            <x-slot:actions><form method="POST" action="{{ route('admin.vulns.posture') }}">@csrf<button class="btn btn-sm btn-ghost" type="submit" title="{{ __('Check again') }}"><x-icon name="refresh" /><span class="sr-only">{{ __('Check again') }}</span></button></form></x-slot:actions>
            @php $okCount = collect($posture['checks'])->where('status', 'ok')->count(); @endphp
            <div class="vx-score">
                <div class="vx-ring {{ $posture['grade'] }}" style="--p: {{ $posture['score'] ?? 0 }}" role="img" aria-label="{{ __('Security score :n of 100', ['n' => $posture['score'] ?? '–']) }}"><strong>{{ $posture['score'] ?? '–' }}</strong></div>
                <div>
                    <strong style="font-size:18px">{{ match ($posture['grade']) { 'ok' => __('Well protected'), 'warn' => __('Needs attention'), 'bad' => __('At risk'), default => __('Unknown') } }}</strong>
                    <p class="muted small" style="margin:4px 0 0">{{ __(':ok of :total checks are fine', ['ok' => $okCount, 'total' => count($posture['checks'])]) }}</p>
                </div>
            </div>
        </x-ui.card>

        {{-- Problems by severity --}}
        <x-ui.card :title="__('Open problems on websites')">
            <x-slot:actions><x-ui.button size="sm" :href="route('admin.vulns.findings')">{{ __('View all') }}</x-ui.button></x-slot:actions>
            @php $sum = max(1, $totals['critical'] + $totals['high'] + $totals['medium'] + $totals['low'] + $totals['malware']); @endphp
            <div class="vx-stack" role="img" aria-label="{{ __(':n open problems', ['n' => $totals['total']]) }}">
                @foreach (['malware', 'critical', 'high', 'medium', 'low'] as $s)
                    @if ($totals[$s] > 0)<span class="sev-{{ $s }}" style="width:{{ $totals[$s] / $sum * 100 }}%;background:var(--sc)"></span>@endif
                @endforeach
            </div>
            <div class="vx-sev">
                @foreach (['critical' => __('Critical'), 'high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low'), 'malware' => __('Malware')] as $s => $label)
                    <a class="sev-{{ $s }}" href="{{ route('admin.vulns.findings', ['severity' => $s]) }}"><strong>{{ $totals[$s] }}</strong><span>{{ $label }}</span></a>
                @endforeach
            </div>
            <p class="muted small" style="margin:12px 0 0">{{ trans_choice(':count website affected|:count websites affected', $totals['sites']) }} · {{ __(':n without a fix yet', ['n' => $totals['no_fix']]) }} · {{ __('Source: :source', ['source' => $source === 'wordfence' ? 'Wordfence Intelligence' : 'WPVulnerability.net']) }}</p>
        </x-ui.card>

        {{-- Activity --}}
        <x-ui.card :title="__('Last days')">
            <div class="vx-kpis">
                <div><strong>{{ $posture['alerts_24h'] ?? '–' }}</strong><span>{{ __('Attacks detected, 24 h') }}</span></div>
                <div><strong>{{ $posture['blocked'] ?? '–' }}</strong><span>{{ __('IP addresses blocked') }}</span></div>
                <div><strong>{{ $totals['resolved_30d'] }}</strong><span>{{ __('Problems solved, 30 days') }}</span></div>
                <div><strong>{{ $notices->where('sent_at', '>=', now()->subDays(30))->count() }}</strong><span>{{ __('Customers mailed, 30 days') }}</span></div>
            </div>
        </x-ui.card>
    </section>

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Customers most at risk')" flush>
            @if ($ranking->isEmpty())
                <x-ui.empty icon="shield">{{ __('No customer has open security problems.') }}</x-ui.empty>
            @else
            <ol class="vx-risk">
                @foreach ($ranking as $i => $r)
                    <li class="sev-{{ $r['level'] === 'none' ? 'low' : $r['level'] }}">
                        <span class="vx-rank">{{ $i + 1 }}</span>
                        <a class="name" href="{{ route('admin.vulns.findings', ['customer' => $r['customer']->id]) }}">{{ $r['customer']->displayName() }}</a>
                        <span style="display:flex;align-items:center;gap:6px">
                            <span class="vx-chip sev-{{ $r['level'] === 'none' ? 'low' : $r['level'] }}">{{ __('Risk :n', ['n' => $r['score']]) }}</span>
                            <a class="btn btn-sm btn-ghost" href="{{ route('admin.vulns.preview', $r['customer']) }}" target="_blank" title="{{ __('Preview the mail') }}"><x-icon name="mail" /><span class="sr-only">{{ __('Preview the mail') }}</span></a>
                        </span>
                        <span class="vx-bar"><span style="width:{{ $r['score'] }}%"></span></span>
                        <span class="meta">
                            @if ($r['malware'])<span class="vx-chip sev-malware">{{ trans_choice(':count malware file|:count malware files', $r['malware']) }}</span>@endif
                            @foreach (['critical', 'high', 'medium', 'low'] as $s)
                                @if ($r['counts'][$s])<span class="vx-chip sev-{{ $s }}">{{ $r['counts'][$s] }} {{ __(ucfirst($s)) }}</span>@endif
                            @endforeach
                            @if ($r['outdated'])<span>{{ trans_choice(':count outdated part|:count outdated parts', $r['outdated']) }}</span>@endif
                            @if ($r['top'])<span>{{ __('Worst: :what', ['what' => $r['top']->componentLabel()]) }}</span>@endif
                        </span>
                    </li>
                @endforeach
            </ol>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Server checks')" flush>
            <ul class="vx-checks">
                @foreach ($posture['checks'] as $c)
                    <li><span class="vx-dot {{ $c['status'] }}" aria-hidden="true">{{ ['ok' => '✓', 'warn' => '!', 'bad' => '✕'][$c['status']] }}</span>
                        <div><strong>{{ $c['label'] }} <span class="sr-only">({{ $c['status'] }})</span></strong><span>{{ $c['detail'] }}</span></div></li>
                @endforeach
            </ul>
        </x-ui.card>
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
