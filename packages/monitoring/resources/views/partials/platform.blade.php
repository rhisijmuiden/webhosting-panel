{{-- Platform components + notices; used by the client page and the public page. --}}
@foreach ($notices as $n)
    <div class="mo-notice {{ $n->level }}" role="status">
        <h3>{{ $n->isUpcoming() && $n->level !== 'maintenance' ? __('Planned').': ' : '' }}{{ $n->title }}</h3>
        @if ($n->body)<p>{{ $n->body }}</p>@endif
        @if ($n->starts_at || $n->ends_at)<p class="small" style="margin-top:6px">{{ $n->starts_at?->timezone(config('app.timezone'))->isoFormat('dd D MMM HH:mm') }}@if ($n->ends_at) – {{ $n->ends_at->timezone(config('app.timezone'))->isoFormat('dd D MMM HH:mm') }}@endif</p>@endif
    </div>
@endforeach
<x-ui.card flush>
    @foreach ($components as $key => $c)
        <div class="mo-comp">
            <strong>{{ $c['label'] }}</strong>
            <span class="mo-state {{ $c['status'] }}">{{ $c['maintenance'] ? __('Maintenance') : match ($c['status']) { 'critical' => __('Outage'), 'warning' => __('Partly unavailable'), default => __('Working') } }}</span>
            <div class="bars">
                @include('monitoring::partials.bars', ['days' => $c['days'], 'size' => $barSize ?? ''])
                <div class="mo-axis"><span>{{ trans_choice(':count day ago|:count days ago', count($c['days'])) }}</span><span>{{ $c['uptime'] !== null ? __(':pct% uptime', ['pct' => rtrim(rtrim(number_format($c['uptime'], 2), '0'), '.')]) : '' }}</span><span>{{ __('Today') }}</span></div>
            </div>
        </div>
    @endforeach
</x-ui.card>
