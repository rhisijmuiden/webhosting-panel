@php $c = $payload['run']['counts']; @endphp
<x-layouts.admin :title="$run->actionLabel()">
    @include('wordpress::bulk.styles')
    <script src="{{ asset('assets/wp-bulk.js') }}?v={{ @filemtime(public_path('assets/wp-bulk.js')) }}"></script>
    <div class="wpb" x-data="wpBulkRun(@js(['payload' => $payload, 'url' => route('admin.wordpress.bulk.status', $run)]))">
        <div class="page-head">
            <div>
                <div class="crumbs"><a href="{{ route('admin.wordpress.index') }}">WordPress</a> › <a href="{{ route('admin.wordpress.bulk.index') }}">{{ __('Bulk actions') }}</a></div>
                <h1>{{ $run->actionLabel() }}</h1>
                <p class="sub">
                    {{ trans_choice(':count site|:count sites', $run->total) }}@if ($run->summary) · {{ $run->summary }}@endif
                    · {{ __('started :time by :user', ['time' => $run->started_at?->diffForHumans(), 'user' => $run->user_name ?? __('the system')]) }}
                    · {{ __(':n at the same time', ['n' => $run->concurrency]) }}
                </p>
            </div>
            <div class="actions">
                @if ($run->status === 'running' || $run->status === 'paused')
                    @if ($run->status === 'paused')
                        <form method="POST" action="{{ route('admin.wordpress.bulk.resume', $run) }}">@csrf<x-ui.button variant="primary" icon="refresh">{{ __('Resume') }}</x-ui.button></form>
                    @endif
                    <form method="POST" action="{{ route('admin.wordpress.bulk.cancel', $run) }}" onsubmit="return confirm(@js(__('Cancel the sites that have not started yet?')))">@csrf<x-ui.button icon="close">{{ __('Cancel') }}</x-ui.button></form>
                @elseif ($c['failed'] + $c['cancelled'] > 0 && $run->definition())
                    <form method="POST" action="{{ route('admin.wordpress.bulk.retry', $run) }}">@csrf<x-ui.button variant="primary" icon="refresh">{{ trans_choice('Retry :count site|Retry :count sites', $c['failed'] + $c['cancelled']) }}</x-ui.button></form>
                @endif
                <x-ui.button icon="plus" :href="route('admin.wordpress.bulk.index')">{{ __('New bulk action') }}</x-ui.button>
            </div>
        </div>

        @if (session('status'))<div class="wpb-banner" style="background:var(--info-bg);color:var(--info-ink)" role="status">{{ session('status') }}</div>@endif
        @if ($run->stop_reason)
            <div class="wpb-banner" role="alert"><x-icon name="info" /><span>{{ $run->stop_reason }}</span></div>
        @endif

        <x-ui.card>
            <div class="wpb-head">
                <div>
                    <div class="wpb-pct"><span x-text="run.counts.percent">{{ $c['percent'] }}</span>%</div>
                    <div class="muted small" aria-live="polite">
                        <span x-text="run.status_label">{{ $run->statusLabel() }}</span>
                        <span x-show="run.status === 'running'"> · <span class="wpb-spin" aria-hidden="true"></span></span>
                        · <span x-text="duration(run.elapsed)"></span>
                    </div>
                </div>
                <div class="muted small" x-text="@js(__(':done of :total sites done')).replace(':done', run.counts.done).replace(':total', run.counts.total)"></div>
            </div>
            <div class="wpb-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="run.counts.percent" aria-label="{{ __('Progress') }}">
                <span class="ok" :style="'width:' + pct('succeeded') + '%'"></span>
                <span class="att" :style="'width:' + pct('attention') + '%'"></span>
                <span class="bad" :style="'width:' + pct('failed') + '%'"></span>
                <span class="skip" :style="'width:' + pct('skipped', 'cancelled') + '%'"></span>
                <span class="busy" :style="'width:' + pct('running') + '%'"></span>
            </div>
            <div class="wpb-kpis">
                <div class="is-ok"><span>{{ __('Done') }}</span><strong x-text="run.counts.succeeded">{{ $c['succeeded'] }}</strong></div>
                <div class="is-att"><span>{{ __('Needs attention') }}</span><strong x-text="run.counts.attention">{{ $c['attention'] }}</strong></div>
                <div class="is-bad"><span>{{ __('Failed') }}</span><strong x-text="run.counts.failed">{{ $c['failed'] }}</strong></div>
                <div><span>{{ __('Skipped') }}</span><strong x-text="run.counts.skipped + run.counts.cancelled">{{ $c['skipped'] + $c['cancelled'] }}</strong></div>
                <div class="is-busy"><span>{{ __('Busy') }}</span><strong x-text="run.counts.running">{{ $c['running'] }}</strong></div>
                <div><span>{{ __('Waiting') }}</span><strong x-text="run.counts.pending">{{ $c['pending'] }}</strong></div>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Result per site')" flush>
            <div class="wpb-chips" role="group" aria-label="{{ __('Show') }}">
                @foreach (['all' => __('All'), 'done' => __('Done'), 'attention' => __('Needs attention'), 'failed' => __('Failed'), 'skipped' => __('Skipped'), 'waiting' => __('Waiting')] as $k => $l)
                    <button type="button" class="wpb-chip" :aria-pressed="show === @js($k)" @click="show = @js($k)">{{ $l }} <span class="n" x-text="count(@js($k))"></span></button>
                @endforeach
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Status') }}</th><th>{{ __('Website') }}</th><th class="wpb-hide-sm">{{ __('Customer') }}</th><th>{{ __('Result') }}</th><th class="num wpb-hide-sm">{{ __('Time') }}</th></tr></thead>
                    <tbody>
                        <template x-for="i in shownItems" :key="i.id">
                            <tr>
                                <td><span class="pill" :class="pill(i.status)"><span class="wpb-spin" x-show="i.status === 'running'" aria-hidden="true"></span><span x-text="i.status_label"></span></span></td>
                                <td><template x-if="i.install_url"><a class="strong" :href="i.install_url" x-text="i.label"></a></template><template x-if="! i.install_url"><span x-text="i.label"></span></template></td>
                                <td class="wpb-hide-sm" x-text="i.customer || '–'"></td>
                                <td>
                                    <div class="wpb-row-msg" x-text="i.message || (i.status === 'pending' ? @js(__('Waiting for its turn')) : '')"></div>
                                    <template x-if="lines(i.details).length">
                                        <div>
                                            <button type="button" class="btn btn-sm" style="min-height:26px;padding:0 8px;margin-top:4px" @click="open[i.id] = ! open[i.id]" x-text="open[i.id] ? @js(__('Hide details')) : @js(__('Details'))"></button>
                                            <ul class="wpb-details" x-show="open[i.id]"><template x-for="l in lines(i.details)"><li x-text="l"></li></template></ul>
                                        </div>
                                    </template>
                                </td>
                                <td class="num muted wpb-hide-sm" x-text="duration(i.seconds)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="! shownItems.length" x-cloak><x-ui.empty icon="check">{{ __('Nothing here.') }}</x-ui.empty></div>
        </x-ui.card>
    </div>
</x-layouts.admin>
