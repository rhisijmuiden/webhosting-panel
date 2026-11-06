{{-- Uptime per day: $days from Uptime::days(), $size '' or 'sm' --}}
<div class="mo-bars {{ $size ?? '' }}" role="img" aria-label="{{ __('Uptime per day, last :n days', ['n' => count($days)]) }}">
    @foreach ($days as $d)
        @php $cls = $d['uptime'] === null ? 'none' : ($d['uptime'] < 99 ? 'bad' : ($d['uptime'] < 100 ? 'warn' : '')); @endphp
        <span class="{{ $cls }}" title="{{ \Illuminate\Support\Carbon::parse($d['day'])->isoFormat('dd D MMM') }}: {{ $d['uptime'] === null ? __('no data') : rtrim(rtrim(number_format($d['uptime'], 2), '0'), '.').'%'.($d['down_minutes'] ? ' · '.__(':n min down', ['n' => $d['down_minutes']]) : '') }}"></span>
    @endforeach
</div>
