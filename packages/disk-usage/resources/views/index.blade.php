@php
    use Illuminate\Support\Number;
    $size = fn ($b) => Number::fileSize((int) $b, 1);
    $max = max(1, (int) $rows->max('total'));
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Disk usage')">
    @include('disk-usage::partials.styles')
    <div class="du">
    <div class="page-head">
        <div>
            <h1>{{ __('Disk usage') }}</h1>
            <div class="sub">{{ $area === 'admin'
                ? __('Which websites, folders, databases and mailboxes fill the disks. Open a website to drill down.')
                : __('See which folders, databases and mailboxes take up your disk space. Open a website to drill down.') }}</div>
        </div>
    </div>

    @if ($agentError)
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The server agent is not reachable.') }}</strong><p class="small">{{ $agentError }}</p></div></div>
    @endif

    <section class="bento" style="margin-top: 20px" aria-label="{{ __('Totals') }}">
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon" style="background: color-mix(in srgb, var(--du-files) 16%, transparent); color: var(--accent-text)">@include('disk-usage::partials.icon', ['name' => 'files'])</span>{{ __('Files') }}</span>
            <strong class="kpi-value">{{ $size($totals['files']) }}</strong>
            <span class="kpi-foot">{{ trans_choice(':count website|:count websites', $totals['websites'], ['count' => $totals['websites']]) }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon" style="background: color-mix(in srgb, var(--du-db) 16%, transparent); color: var(--du-db)">@include('disk-usage::partials.icon', ['name' => 'db'])</span>{{ __('Databases') }}</span>
            <strong class="kpi-value">{{ $size($totals['databases']) }}</strong>
            <span class="kpi-foot">MariaDB</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon" style="background: color-mix(in srgb, var(--du-mail) 16%, transparent); color: var(--du-mail)">@include('disk-usage::partials.icon', ['name' => 'mail'])</span>{{ __('Email') }}</span>
            <strong class="kpi-value">{{ $size($totals['mail']) }}</strong>
            <span class="kpi-foot">{{ __('All mailboxes') }}</span>
        </div>
        <a class="card kpi span-3" href="{{ request()->fullUrlWithQuery(['full' => $full ? null : 1]) }}">
            <span class="kpi-label"><span class="kpi-icon" style="{{ $totals['near_full'] ? 'background: var(--warn-bg); color: var(--warn-ink)' : '' }}"><x-icon name="info" /></span>{{ __('Nearly full') }}</span>
            <strong class="kpi-value">{{ $totals['near_full'] }}</strong>
            <span class="kpi-foot">{{ __('At :percent% of the disk space or more', ['percent' => (int) config('disk-usage.warn_percent', 85)]) }}</span>
        </a>
    </section>

    @if ($area === 'admin')
        <div class="list-toolbar" style="margin-top: 16px">
            <div>
                @if ($full)<x-ui.pill status="warn">{{ __('Only nearly full websites') }}</x-ui.pill> <a class="small" href="{{ request()->fullUrlWithQuery(['full' => null]) }}">{{ __('Show all') }}</a>@endif
            </div>
            <form class="list-filters" method="GET" role="search">
                @if ($full)<input type="hidden" name="full" value="1">@endif
                <label class="search"><x-icon name="search" /><input type="search" name="q" value="{{ $q }}" placeholder="{{ __('Domain or owner') }}" aria-label="{{ __('Search') }}"></label>
            </form>
        </div>
    @endif

    <x-ui.card flush style="margin-top: 16px">
        @if ($rows->isEmpty())
            <x-ui.empty icon="globe">{{ $q || $full ? __('No website matches.') : __('No websites yet.') }}</x-ui.empty>
        @else
            <ul class="du-legend" style="padding: 14px 20px 0; margin: 0">
                <li><i class="du-files"></i>{{ __('Files') }}</li><li><i class="du-db"></i>{{ __('Databases') }}</li><li><i class="du-mail"></i>{{ __('Email') }}</li>
            </ul>
            <div class="table-wrap"><table class="table du-table">
                <thead><tr>
                    <th>{{ __('Website') }}</th>
                    <th class="du-col-bar du-hide-sm">{{ __('Use') }}</th>
                    <th class="num">{{ __('Files') }}</th>
                    <th class="num du-hide-sm">{{ __('Databases') }}</th>
                    <th class="num du-hide-sm">{{ __('Email') }}</th>
                    <th class="num">{{ __('Disk space') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($rows as $row)
                    @php $w = $row['website']; $p = $row['percent']; @endphp
                    <tr>
                        <td>
                            <div class="du-site-name">
                                <a href="{{ route($area.'.disk-usage.show', $w) }}">{{ $w->domain }}</a>
                                <span class="muted small">
                                    @if ($area === 'admin' && $w->customer){{ $w->customer->displayName() }} · @endif
                                    @if ($row['subdomains']){{ trans_choice('+:count subdomain|+:count subdomains', $row['subdomains'], ['count' => $row['subdomains']]) }} · @endif
                                    {{ $row['scanned_at'] ? __('scanned :ago', ['ago' => \Illuminate\Support\Carbon::createFromTimestamp($row['scanned_at'])->diffForHumans()]) : __('not scanned yet') }}
                                </span>
                            </div>
                        </td>
                        <td class="du-hide-sm">@include('disk-usage::partials.stack', ['files' => $row['files'], 'databases' => $row['databases'], 'mail' => $row['mail'], 'max' => $max, 'thin' => true])</td>
                        <td class="num">{{ $size($row['files']) }}</td>
                        <td class="num du-hide-sm">{{ $size($row['databases']) }}</td>
                        <td class="num du-hide-sm">{{ $size($row['mail']) }}</td>
                        <td class="num">
                            @if ($p !== null)
                                <x-ui.pill :status="$p >= 95 ? 'bad' : ($row['near_full'] ? 'warn' : 'ok')" :dot="false">{{ round($p) }}%</x-ui.pill>
                                <div class="muted small">{{ __('of :total', ['total' => $size($row['quota_bytes'])]) }}</div>
                            @else
                                <span class="muted small">{{ __('No disk limit') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-ui.card>
    </div>
</x-dynamic-component>
