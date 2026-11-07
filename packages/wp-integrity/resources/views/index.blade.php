<x-dynamic-component :component="'layouts.'.$area" :title="__('File integrity')">
    @include('wp-integrity::partials.styles')
    <div class="wpi">
    <div class="page-head">
        <div>
            <h1>{{ __('File integrity') }}</h1>
            <div class="sub">{{ $area === 'admin'
                ? __('Every night the WordPress core and plugin files of all websites are compared with the originals on WordPress.org. Changed files are often the first sign of a hack.')
                : __('Every night the WordPress core and plugin files of your websites are compared with the originals on WordPress.org.') }}</div>
        </div>
    </div>

    <section class="bento" style="margin-top:20px" aria-label="{{ __('Totals') }}">
        <a class="card kpi span-3" href="{{ request()->fullUrlWithQuery(['filter' => $filter === 'changed' ? null : 'changed']) }}">
            <span class="kpi-label"><span class="wpi-kpi-icon {{ $totals['changed'] ? 'is-warn' : 'is-ok' }}"><x-icon name="wordpress" /></span>{{ __('Websites with changes') }}</span>
            <strong class="kpi-value">{{ $totals['changed'] }}<span class="muted" style="font-size:16px"> / {{ $totals['installs'] }}</span></strong>
            <span class="kpi-foot">{{ $filter === 'changed' ? __('Showing only these') : __('Click to show only these') }}</span>
        </a>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="wpi-kpi-icon {{ $totals['files'] ? 'is-warn' : 'is-ok' }}"><x-icon name="edit" /></span>{{ __('Changed files') }}</span>
            <strong class="kpi-value">{{ $totals['files'] }}</strong>
            <span class="kpi-foot">{{ __('Not counting intended changes') }}</span>
        </div>
        <a class="card kpi span-3" href="{{ request()->fullUrlWithQuery(['filter' => $filter === 'malware' ? null : 'malware']) }}">
            <span class="kpi-label"><span class="wpi-kpi-icon {{ $totals['malware'] ? 'is-bad' : 'is-ok' }}"><x-icon name="shield" /></span>{{ __('Malware in these files') }}</span>
            <strong class="kpi-value">{{ $totals['malware'] }}</strong>
            <span class="kpi-foot">{{ __('From the nightly malware scan') }}</span>
        </a>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="wpi-kpi-icon"><x-icon name="clock" /></span>{{ __('Not checked yet') }}</span>
            <strong class="kpi-value">{{ $totals['unchecked'] }}</strong>
            <span class="kpi-foot">{{ __('Checked every night at :time', ['time' => config('wp-integrity.check_at')]) }}</span>
        </div>
    </section>

    <x-ui.card style="margin-top:20px" flush>
        @if ($rows->isEmpty())
            <x-ui.empty icon="wordpress">{{ $filter === 'all' ? __('No WordPress websites found.') : __('Nothing to show with this filter.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Website') }}</th>@if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif<th>{{ __('Status') }}</th><th>{{ __('Changed parts') }}</th><th>{{ __('Checked') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($rows as $row)
                @php $rep = $row['report']; $parts = collect($rep?->components() ?? [])->where('status', 'changed'); @endphp
                <tr>
                    <td><a class="strong" href="{{ route($area.'.wp-integrity.show', $row['install']) }}">{{ $row['install']->label() }}</a>
                        <br><span class="muted small">WordPress {{ $row['install']->version ?? '?' }}</span></td>
                    @if ($area === 'admin')<td class="small">{{ $row['install']->website->customer?->displayName() ?? '–' }}</td>@endif
                    <td>@include('wp-integrity::partials.status', ['report' => $rep])</td>
                    <td class="small">{{ $parts->isEmpty() ? '–' : $parts->map(fn ($c) => $c['type'] === 'core' ? 'WordPress' : $c['name'])->implode(', ') }}</td>
                    <td class="small">{{ $rep?->checked_at?->diffForHumans() ?? __('never') }}</td>
                    <td class="num"><a class="btn btn-sm" href="{{ route($area.'.wp-integrity.show', $row['install']) }}">{{ __('Open') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    </div>
</x-dynamic-component>
