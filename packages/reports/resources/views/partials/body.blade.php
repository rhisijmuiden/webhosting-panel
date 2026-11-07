@php
    $font = "font-family:Lexend,'Segoe UI',Helvetica,Arial,sans-serif;";
    $c = ['ink' => '#0A2348', 'muted' => '#4D607C', 'line' => '#DFE7F0', 'soft' => '#F6F9FC', 'coral' => '#3FA9F5',
        'link' => '#0F6CB5', 'ok' => '#1C8A57', 'okbg' => '#E7F6EE', 'warn' => '#A86B00', 'warnbg' => '#FFF6E0', 'bad' => '#C23434', 'badbg' => '#FDECEC',
        'blue' => '#2E5AAC', 'bluebg' => '#EAF2FF'];
    $v = $r['visitors'];
    $up = $r['uptime'];
    $bk = $r['backups'];
    $sec = $r['security'];
    $upd = $r['updates'];
    $mail = $r['mail'];
    $use = $r['usage'];
    $siteCount = count($r['sites']);
    $prevMonth = \Rhis\Reports\Format::monthName($r['from']->subMonthNoOverflow());

    // The four headline tiles: the best of what we have for this customer.
    $tiles = [];
    if ($v) {
        $tiles[] = ['label' => __('Visitors'), 'value' => \Rhis\Reports\Format::number($v['total']),
            'sub' => $v['change'] === null ? __(':n page views', ['n' => \Rhis\Reports\Format::number($v['pageviews'])])
                : ($v['change'] >= 0 ? '+'.$v['change'].'% '.__('vs :month', ['month' => $prevMonth]) : $v['change'].'% '.__('vs :month', ['month' => $prevMonth])),
            'tone' => $v['change'] !== null && $v['change'] > 0 ? 'ok' : 'muted'];
    }
    if ($up) {
        $tiles[] = ['label' => __('Online'), 'value' => \Rhis\Reports\Format::percent($up['average']),
            'sub' => $up['downtime_minutes'] > 0 ? __(':time offline', ['time' => \Rhis\Reports\Format::duration($up['downtime_minutes'])]) : __('Not a minute offline'),
            'tone' => $up['average'] >= 99.9 ? 'ok' : 'warn'];
    }
    if ($bk) {
        $tiles[] = ['label' => __('Backups'), 'value' => \Rhis\Reports\Format::number($bk['total']),
            'sub' => $bk['last'] ? __('Latest on :date', ['date' => \Rhis\Reports\Format::day($bk['last'])]) : __('None this month'),
            'tone' => $bk['total'] > 0 ? 'ok' : 'warn'];
    }
    $tiles[] = $sec['open_urgent'] > 0
        ? ['label' => __('Security'), 'value' => \Rhis\Reports\Format::number($sec['open_urgent']), 'sub' => trans_choice('problem to fix|problems to fix', $sec['open_urgent']), 'tone' => 'bad']
        : ($sec['resolved'] > 0
            ? ['label' => __('Security'), 'value' => \Rhis\Reports\Format::number($sec['resolved']), 'sub' => trans_choice('problem fixed|problems fixed', $sec['resolved']), 'tone' => 'ok']
            : ['label' => __('Security'), 'value' => '✓', 'sub' => $sec['scanned'] ? __('Nothing found') : __('HTTPS on :n of :total', ['n' => $sec['ssl_active'], 'total' => $sec['sites']]), 'tone' => 'ok']);
    if ($mail && count($tiles) < 4) {
        $tiles[] = ['label' => __('Spam stopped'), 'value' => \Rhis\Reports\Format::number($mail['spam']), 'sub' => __(':n mails delivered', ['n' => \Rhis\Reports\Format::number($mail['received'])]), 'tone' => 'ok'];
    }
    if (count($tiles) < 4) {
        $tiles[] = ['label' => __('Disk space'), 'value' => \Rhis\Reports\Format::megabytes($use['disk_used_mb']),
            'sub' => $use['disk_quota_mb'] ? __('of :total', ['total' => \Rhis\Reports\Format::megabytes($use['disk_quota_mb'])]) : __('in use'), 'tone' => 'muted'];
    }
    $tiles = array_slice($tiles, 0, 4);
    $tone = fn ($t) => ['ok' => $c['ok'], 'warn' => $c['warn'], 'bad' => $c['bad'], 'muted' => $c['muted']][$t];

    // One friendly sentence with the highlights.
    $parts = [];
    if ($up) {
        $parts[] = $up['average'] >= 99.9 ? trans_choice('your website was online :pct of the time|your :count websites were online :pct of the time', $siteCount, ['pct' => \Rhis\Reports\Format::percent($up['average'])])
            : trans_choice('your website was online :pct of the time|your websites were online :pct of the time on average', $siteCount, ['pct' => \Rhis\Reports\Format::percent($up['average'])]);
    }
    if ($v && $v['total'] > 0) {
        $parts[] = __(':n visitors came by', ['n' => \Rhis\Reports\Format::number($v['total'])]);
    }
    if ($bk && $bk['total'] > 0) {
        $parts[] = trans_choice('we made :count backup|we made :count backups', $bk['total'], ['count' => \Rhis\Reports\Format::number($bk['total'])]);
    }
    if ($mail && $mail['spam'] > 0) {
        $parts[] = trans_choice('we stopped :count spam mail|we stopped :count spam mails', $mail['spam'], ['count' => \Rhis\Reports\Format::number($mail['spam'])]);
    }
    $last = count($parts) > 1 ? array_pop($parts) : null;
    $sentence = $parts ? ucfirst(implode(', ', $parts).($last ? ' '.__('and').' '.$last : '')).'.' : null;

    // Bars for the visitors per day.
    $daily = $v['daily'] ?? [];
    $max = $daily ? max(max($daily), 1) : 1;
    $bestDay = $v['best_day'] ?? null;

    $checks = [];
    $checks[] = $sec['ssl_active'] === $sec['sites']
        ? ['ok', trans_choice('Your website is reachable over a secure connection (HTTPS).|All :count websites are reachable over a secure connection (HTTPS).', $sec['sites']), null]
        : ['warn', __(':n of :total websites have a secure connection (HTTPS).', ['n' => $sec['ssl_active'], 'total' => $sec['sites']]), [__('Check the certificate'), $panelUrl]];
    if ($sec['scanned']) {
        if ($sec['open_urgent'] > 0) {
            $checks[] = ['bad', trans_choice(':count security problem still needs your attention.|:count security problems still need your attention.', $sec['open_urgent']), [__('Fix it now'), $securityUrl]];
        } elseif ($sec['open'] > 0) {
            $checks[] = ['warn', trans_choice('Our nightly check found :count minor security point.|Our nightly check found :count minor security points.', $sec['open']), [__('View'), $securityUrl]];
        } else {
            $checks[] = ['ok', __('Every night we checked your websites for known vulnerabilities and malware: nothing open.'), null];
        }
        if ($sec['resolved'] > 0) {
            $checks[] = ['ok', trans_choice(':count security problem was fixed this month.|:count security problems were fixed this month.', $sec['resolved']), null];
        }
    }
    if ($bk) {
        $checks[] = $bk['total'] > 0
            ? ['ok', trans_choice(':count backup was made, the latest on :date. It is stored at a separate location.|:count backups were made, the latest on :date. They are stored at a separate location.', $bk['total'], ['date' => \Rhis\Reports\Format::day($bk['last'])]), null]
            : ['warn', __('No backup was made of your websites this month.'), [__('Make a backup'), $backupsUrl]];
        if ($bk['own_failed'] > 0) {
            $checks[] = ['warn', trans_choice(':count backup to your own location failed.|:count backups to your own location failed.', $bk['own_failed']), [__('View backups'), $backupsUrl]];
        }
    }
    if ($upd['wordpress_sites'] > 0) {
        if ($upd['wordpress'] > 0) {
            $checks[] = ['ok', trans_choice('WordPress was updated :count time, with a backup first.|WordPress was updated :count times, with a backup first.', $upd['wordpress']), null];
        }
        if ($upd['waiting'] > 0) {
            $checks[] = ['warn', trans_choice(':count WordPress update is waiting for you.|:count WordPress updates are waiting for you.', $upd['waiting']), [__('Update now'), $wordpressUrl]];
        } elseif ($upd['wordpress'] === 0) {
            $checks[] = ['ok', __('WordPress, plugins and themes are up to date.'), null];
        }
    }
    if ($upd['server']) {
        $checks[] = ['ok', trans_choice('We updated the server software :count time, security patches included.|We updated the server software :count times, security patches included.', $upd['server']), null];
    }
    if ($upd['php']) {
        $checks[] = ['info', __('Your websites run on PHP :versions.', ['versions' => implode(', ', $upd['php'])]), null];
    }
    $icon = ['ok' => ['✓', $c['ok'], $c['okbg']], 'warn' => ['!', $c['warn'], $c['warnbg']], 'bad' => ['!', $c['bad'], $c['badbg']], 'info' => ['i', $c['blue'], $c['bluebg']]];
    $diskPct = $use['disk_quota_mb'] ? min(100, (int) round($use['disk_used_mb'] / max(1, $use['disk_quota_mb']) * 100)) : null;
@endphp
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;{{ $font }}color:{{ $c['ink'] }}">

    {{-- Header --}}
    <tr><td style="background:#0A2348;border-radius:16px 16px 0 0;padding:28px 32px 24px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td><img src="{{ isset($message) ? $message->embed($logo) : asset(\App\Support\Brand::logo()) }}" width="132" height="34" alt="{{ config('panel.brand.name') }}" style="display:block;border:0"></td>
            <td align="right" style="{{ $font }}font-size:12px;color:#8CCBFA;letter-spacing:.08em;text-transform:uppercase">{{ __('Monthly report') }} · {{ $monthName }}</td>
        </tr></table>
    </td></tr>
    <tr><td style="height:4px;background:{{ $c['coral'] }};font-size:0;line-height:0">&nbsp;</td></tr>

    {{-- Intro --}}
    <tr><td style="background:#FFFFFF;padding:32px 32px 8px">
        <h1 style="margin:0 0 16px;{{ $font }}font-size:28px;line-height:1.2;font-weight:800;color:{{ $c['ink'] }}">{{ __('Your hosting in :month', ['month' => $monthName]) }}</h1>
        <p style="margin:0 0 12px;font-size:16px;line-height:1.6">{{ __('Hello :name,', ['name' => $customer->name]) }}</p>
        <p style="margin:0 0 8px;font-size:16px;line-height:1.6;color:{{ $c['muted'] }}">
            {{ __('Here is what your hosting did for you last month.') }}
            @if ($sentence) {{ $sentence }} @endif
        </p>
    </td></tr>

    {{-- Headline tiles --}}
    <tr><td style="background:#FFFFFF;padding:16px 26px 8px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        @foreach (array_chunk($tiles, 2) as $row)
            <tr>
            @foreach ($row as $t)
                <td width="50%" valign="top" style="padding:6px">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $c['soft'] }};border-radius:12px"><tr><td style="padding:16px 18px">
                        <p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{{ $c['muted'] }}">{{ $t['label'] }}</p>
                        <p style="margin:0 0 4px;{{ $font }}font-size:30px;line-height:1.1;font-weight:800;font-stretch:115%;color:{{ $c['ink'] }}">{{ $t['value'] }}</p>
                        <p style="margin:0;font-size:13px;line-height:1.4;font-weight:600;color:{{ $tone($t['tone']) }}">{{ $t['sub'] }}</p>
                    </td></tr></table>
                </td>
            @endforeach
            @if (count($row) === 1)<td width="50%" style="padding:6px">&nbsp;</td>@endif
            </tr>
        @endforeach
        </table>
    </td></tr>

    {{-- Visitors per day --}}
    @if ($v)
    <tr><td style="background:#FFFFFF;padding:24px 32px 8px">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{{ $c['muted'] }}">{{ __('Visitors per day') }}</p>
        @if ($bestDay)
            <p style="margin:0 0 14px;font-size:14px;line-height:1.5;color:{{ $c['muted'] }}">{{ __('Busiest day: :date with :n visitors.', ['date' => \Rhis\Reports\Format::day($bestDay), 'n' => \Rhis\Reports\Format::number($v['best_day_visitors'])]) }}</p>
        @endif
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="height:96px">
            <tr>
            @foreach ($daily as $i => $count)
                @php $h = $count > 0 ? max(3, (int) round($count / $max * 88)) : 2; $isBest = $bestDay && $bestDay->day === $i + 1; @endphp
                <td valign="bottom" style="padding:0 1px;height:96px" title="{{ \Rhis\Reports\Format::day($r['from']->addDays($i)) }}: {{ \Rhis\Reports\Format::number($count) }}">
                    <div style="height:{{ $h }}px;line-height:{{ $h }}px;font-size:0;background:{{ $isBest ? $c['coral'] : ($count > 0 ? '#2B4C8C' : $c['line']) }};border-radius:3px 3px 0 0">&nbsp;</div>
                </td>
            @endforeach
            </tr>
        </table>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid {{ $c['line'] }}"><tr>
            <td style="padding-top:6px;font-size:11px;color:{{ $c['muted'] }}">1</td>
            <td align="center" style="padding-top:6px;font-size:11px;color:{{ $c['muted'] }}">15</td>
            <td align="right" style="padding-top:6px;font-size:11px;color:{{ $c['muted'] }}">{{ count($daily) }}</td>
        </tr></table>
        <p style="margin:12px 0 0;font-size:13px;line-height:1.6;color:{{ $c['muted'] }}">
            {{ __(':n page views', ['n' => \Rhis\Reports\Format::number($v['pageviews'])]) }} &nbsp;·&nbsp; {{ __(':size sent to visitors', ['size' => \Rhis\Reports\Format::bytes($v['bytes'])]) }}
            @if ($v['previous'] > 0) &nbsp;·&nbsp; {{ __(':n visitors in :month', ['n' => \Rhis\Reports\Format::number($v['previous']), 'month' => $prevMonth]) }} @endif
        </p>
    </td></tr>
    @endif

    {{-- Per website --}}
    @if ($siteCount > 0)
    <tr><td style="background:#FFFFFF;padding:24px 32px 8px">
        <p style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{{ $c['muted'] }}">{{ trans_choice('Your website|Your websites', $siteCount) }}</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $c['line'] }};border-radius:10px;border-collapse:separate">
            <tr style="background:{{ $c['soft'] }}">
                <td style="padding:10px 14px;font-size:12px;font-weight:700;color:{{ $c['muted'] }};border-radius:10px 0 0 0">{{ __('Website') }}</td>
                @if ($v)<td align="right" style="padding:10px 8px;font-size:12px;font-weight:700;color:{{ $c['muted'] }}">{{ __('Visitors') }}</td>@endif
                @if ($up)<td align="right" style="padding:10px 8px;font-size:12px;font-weight:700;color:{{ $c['muted'] }}">{{ __('Online') }}</td>@endif
                @if ($bk && $bk['server'] !== null)<td align="right" style="padding:10px 8px;font-size:12px;font-weight:700;color:{{ $c['muted'] }}">{{ __('Backups') }}</td>@endif
                <td align="right" style="padding:10px 14px;font-size:12px;font-weight:700;color:{{ $c['muted'] }}">HTTPS</td>
            </tr>
            @foreach ($r['sites'] as $s)
            <tr>
                <td style="padding:10px 14px;border-top:1px solid {{ $c['line'] }};font-size:14px;font-weight:600;word-break:break-all">{{ $s['domain'] }}</td>
                @if ($v)<td align="right" style="padding:10px 8px;border-top:1px solid {{ $c['line'] }};font-size:14px;white-space:nowrap">{{ \Rhis\Reports\Format::number($s['visitors'] ?? 0) }}</td>@endif
                @if ($up)<td align="right" style="padding:10px 8px;border-top:1px solid {{ $c['line'] }};font-size:14px;white-space:nowrap;color:{{ isset($s['uptime']) && $s['uptime'] < 99.9 ? $c['warn'] : $c['ink'] }}">{{ isset($s['uptime']) ? \Rhis\Reports\Format::percent($s['uptime']) : '–' }}</td>@endif
                @if ($bk && $bk['server'] !== null)<td align="right" style="padding:10px 8px;border-top:1px solid {{ $c['line'] }};font-size:14px">{{ \Rhis\Reports\Format::number($s['backups'] ?? 0) }}</td>@endif
                <td align="right" style="padding:10px 14px;border-top:1px solid {{ $c['line'] }};font-size:14px;font-weight:700;color:{{ $s['ssl'] ? $c['ok'] : $c['warn'] }}">{{ $s['ssl'] ? '✓' : '!' }}</td>
            </tr>
            @endforeach
        </table>
    </td></tr>
    @endif

    {{-- Security and maintenance --}}
    <tr><td style="background:#FFFFFF;padding:24px 32px 8px">
        <p style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{{ $c['muted'] }}">{{ __('Security and maintenance') }}</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        @foreach ($checks as [$kind, $text, $action])
            <tr>
                <td width="34" valign="top" style="padding:0 0 12px">
                    <div style="width:24px;height:24px;line-height:24px;border-radius:12px;background:{{ $icon[$kind][2] }};color:{{ $icon[$kind][1] }};font-size:13px;font-weight:800;text-align:center">{{ $icon[$kind][0] }}</div>
                </td>
                <td valign="top" style="padding:2px 0 12px;font-size:14px;line-height:1.5;color:{{ $c['ink'] }}">
                    {{ $text }}
                    @if ($action) <a href="{{ $action[1] }}" style="color:{{ $c['link'] }};font-weight:700;text-decoration:none;white-space:nowrap">{{ $action[0] }} &rarr;</a>@endif
                </td>
            </tr>
        @endforeach
        </table>
    </td></tr>

    {{-- Mail --}}
    @if ($mail)
    <tr><td style="background:#FFFFFF;padding:16px 32px 8px">
        <p style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{{ $c['muted'] }}">{{ __('Email') }}</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $c['line'] }};border-radius:10px;border-collapse:separate"><tr>
            @foreach ([[__('Received'), $mail['received']], [__('Spam stopped'), $mail['spam']], [__('Sent'), $mail['sent']]] as $i => [$label, $value])
                <td width="33%" align="center" style="padding:14px 6px;{{ $i ? 'border-left:1px solid '.$c['line'].';' : '' }}">
                    <p style="margin:0 0 2px;{{ $font }}font-size:22px;font-weight:800;color:{{ $i === 1 ? $c['ok'] : $c['ink'] }}">{{ \Rhis\Reports\Format::number($value) }}</p>
                    <p style="margin:0;font-size:12px;color:{{ $c['muted'] }}">{{ $label }}</p>
                </td>
            @endforeach
        </tr></table>
        @if ($mail['spam'] > 0)
            <p style="margin:10px 0 0;font-size:13px;line-height:1.6;color:{{ $c['muted'] }}">{{ trans_choice('Our spam and virus filter stopped :count message before it reached your inbox.|Our spam and virus filter stopped :count messages before they reached your inbox.', $mail['spam'], ['count' => \Rhis\Reports\Format::number($mail['spam'])]) }}</p>
        @endif
    </td></tr>
    @endif

    {{-- Usage --}}
    <tr><td style="background:#FFFFFF;padding:16px 32px 8px">
        <p style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{{ $c['muted'] }}">{{ __('Usage') }}</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td valign="top" style="font-size:14px;line-height:1.5;padding-bottom:6px">{{ __('Disk space') }}</td>
            <td align="right" valign="top" style="font-size:14px;line-height:1.5;font-weight:700;padding-bottom:6px">
                {{ \Rhis\Reports\Format::megabytes($use['disk_used_mb']) }}@if ($use['disk_quota_mb']) <span style="font-weight:400;color:{{ $c['muted'] }}">{{ __('of :total', ['total' => \Rhis\Reports\Format::megabytes($use['disk_quota_mb'])]) }}</span>@endif
            </td>
        </tr>
        @if ($diskPct !== null)
        <tr><td colspan="2" style="padding-bottom:12px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $c['line'] }};border-radius:4px"><tr>
                <td width="{{ max(1, $diskPct) }}%" style="height:8px;line-height:8px;font-size:0;background:{{ $diskPct >= 90 ? $c['bad'] : ($diskPct >= 75 ? $c['warn'] : '#2B4C8C') }};border-radius:4px">&nbsp;</td>
                @if ($diskPct < 100)<td style="height:8px;line-height:8px;font-size:0">&nbsp;</td>@endif
            </tr></table>
        </td></tr>
        @endif
        @if ($use['traffic_bytes'] > 0)
        <tr>
            <td style="font-size:14px;line-height:1.5">{{ __('Data traffic') }}</td>
            <td align="right" style="font-size:14px;line-height:1.5;font-weight:700">{{ \Rhis\Reports\Format::bytes($use['traffic_bytes']) }}</td>
        </tr>
        @endif
        </table>
    </td></tr>

    {{-- CTA --}}
    <tr><td style="background:#FFFFFF;border-radius:0;padding:24px 32px 32px" align="center">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:10px;background:{{ $c['coral'] }}">
            <a href="{{ $panelUrl }}" style="display:inline-block;padding:14px 28px;{{ $font }}font-size:16px;font-weight:700;color:{{ $c['ink'] }};text-decoration:none">{{ __('Open the control panel') }}</a>
        </td></tr></table>
        <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:{{ $c['muted'] }}">
            {{ __('Behind the scenes we keep your server up to date, make backups and watch over your websites every day.') }}
            @if ($supportEmail)<br>{{ __('Questions or wishes? Mail us at') }} <a href="mailto:{{ $supportEmail }}" style="color:{{ $c['link'] }}">{{ $supportEmail }}</a>.@endif
        </p>
    </td></tr>

    {{-- Footer --}}
    <tr><td style="background:#0A2348;border-radius:0 0 16px 16px;padding:24px 32px">
        <p style="margin:0;font-size:13px;line-height:1.6;color:#C6D5E8">{{ __('You receive this report once a month because you host your website with :app.', ['app' => \App\Support\Brand::provider()]) }}
            <a href="{{ $settingsUrl }}" style="color:#8CCBFA">{{ __('Change who receives it, or stop it') }}</a></p>
    </td></tr>
</table>
