{{-- Migration log lines: [{time, level, text, agent}] --}}
<div class="mig-log" data-mig-log tabindex="0" aria-label="{{ __('Log') }}" role="log">
    @forelse ($log as $l)
        <div class="mig-line is-{{ $l['level'] }}@if ($l['agent']) is-agent @endif"><time title="{{ $l['date'] ?? '' }}">{{ $l['time'] ?? '' }}</time><span>{{ $l['text'] }}</span></div>
    @empty
        <div class="mig-line is-info"><time></time><span>{{ __('Nothing logged yet.') }}</span></div>
    @endforelse
</div>
