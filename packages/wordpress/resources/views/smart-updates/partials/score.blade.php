{{-- Visual difference against the threshold. Needs $score (float|null) and $threshold (int). --}}
@if ($score === null)
    <span class="muted small">{{ __('No screenshots') }}</span>
@else
    @php $scale = max(20, $threshold * 2); $w = min(100, $score / $scale * 100); $t = min(100, $threshold / $scale * 100); @endphp
    <div class="su-score" title="{{ __(':score% of the page changed, the limit is :limit%', ['score' => number_format($score, 1), 'limit' => $threshold]) }}">
        <div class="bar"><span @class(['over' => $score > $threshold]) style="width: {{ max(2, $w) }}%"></span><i style="left: {{ $t }}%"></i></div>
        <small>{{ __(':score% changed', ['score' => number_format($score, 1)]) }} · {{ __('limit :limit%', ['limit' => $threshold]) }}</small>
    </div>
@endif
