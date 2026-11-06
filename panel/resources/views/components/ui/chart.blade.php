{{--
  Line chart as inline SVG with a crosshair tooltip.
  $points: list of arrays with 'label' and one value per series key.
  $series: [['key', 'label', 'format' => fn]], max 2 series; colors are --series-1/2.
--}}
@props(['points', 'series', 'max' => null, 'axis' => null, 'title'])
@php
    $W = 800; $H = 200; $padL = 0; $padR = 0; $padT = 6; $padB = 0;
    $n = count($points);
    $values = collect($series)->flatMap(fn ($s) => collect($points)->pluck($s['key']));
    $top = $max ?? max(1, $values->max() ?? 1) * 1.1;
    $axisFmt = $axis ?? ($series[0]['format'] ?? fn ($v) => $v);
    $x = fn ($i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * ($W - $padL - $padR);
    $y = fn ($v) => $padT + (1 - min($v, $top) / $top) * ($H - $padT - $padB);
    $base = $H - $padB;
    $tooltip = collect($points)->map(fn ($p) => [
        'label' => $p['label'],
        'values' => collect($series)->map(fn ($s) => ['label' => $s['label'], 'value' => ($s['format'])($p[$s['key']])])->all(),
    ])->values();
@endphp
<figure class="chart" x-data="{ i: null, n: {{ $n }}, tip: @js($tooltip),
        move(e) { const r = this.$refs.plot.getBoundingClientRect(); const px = (e.clientX - r.left) / r.width * {{ $W }};
                  const f = (px - {{ $padL }}) / ({{ $W - $padL - $padR }}); this.i = this.n > 1 ? Math.max(0, Math.min(this.n - 1, Math.round(f * (this.n - 1)))) : 0; },
        get left() { return this.n > 1 ? ({{ $padL }} + this.i / (this.n - 1) * {{ $W - $padL - $padR }}) / {{ $W }} * 100 : 50; } }">
    <figcaption class="chart-head">
        <h3>{{ $title }}</h3>
        @if (count($series) > 1)
            <span class="legend">@foreach ($series as $s)<span><i class="swatch s{{ $loop->iteration }}"></i>{{ $s['label'] }}</span>@endforeach</span>
        @endif
    </figcaption>
    @if ($n < 2)
        <p class="muted small chart-empty">{{ __('Not enough data yet. The server takes a sample every minute.') }}</p>
    @else
    <div class="chart-plot" x-ref="plot" @mousemove="move($event)" @mouseleave="i = null">
        <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $title }}" preserveAspectRatio="none">
            @foreach ([0, 0.5, 1] as $f)
                <line class="grid" x1="0" x2="{{ $W }}" y1="{{ $y($top * $f) }}" y2="{{ $y($top * $f) }}" />
            @endforeach
            @foreach ($series as $s)
                @php $pts = collect($points)->map(fn ($p, $i) => round($x($i), 1).','.round($y($p[$s['key']]), 1))->implode(' '); @endphp
                @if (count($series) === 1)
                    <polygon class="area s{{ $loop->iteration }}" points="{{ $padL }},{{ $base }} {{ $pts }} {{ round($x($n - 1), 1) }},{{ $base }}" />
                @endif
                <polyline class="line s{{ $loop->iteration }}" points="{{ $pts }}" vector-effect="non-scaling-stroke" />
            @endforeach
        </svg>
        @foreach ([0, 0.5, 1] as $f)
            <span class="tick-y" style="top:{{ round($y($top * $f) / $H * 100, 2) }}%">{{ $axisFmt(round($top * $f, 1)) }}</span>
        @endforeach
        <div class="crosshair" x-show="i !== null" x-cloak :style="`left:${left}%`"></div>
        <div class="chart-tip" x-show="i !== null" x-cloak :style="left > 60 ? `right:${100 - left}%` : `left:${left}%`">
            <strong x-text="i !== null && tip[i].label"></strong>
            <template x-for="(v, k) in (i !== null ? tip[i].values : [])" :key="k">
                <span><i class="swatch" :class="'s' + (k + 1)"></i><span x-text="v.label"></span> <b x-text="v.value"></b></span>
            </template>
        </div>
    </div>
    <div class="ticks-x" aria-hidden="true">
        @foreach ([0, intdiv($n - 1, 2), $n - 1] as $i)<span>{{ $points[$i]['label'] }}</span>@endforeach
    </div>
    @endif
</figure>
