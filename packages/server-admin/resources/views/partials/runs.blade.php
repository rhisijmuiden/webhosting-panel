{{-- Run history: $runs --}}
@if ($runs->isEmpty())
    <x-ui.empty icon="list">{{ __('Nothing has run yet.') }}</x-ui.empty>
@else
<ul class="sv-timeline">
    @foreach ($runs as $r)
        <li>
            <x-ui.pill :status="$r->status">{{ $r->status === 'running' ? __('Running') : ($r->status === 'succeeded' ? __('Done') : __('Failed')) }}</x-ui.pill>
            <a href="{{ route('admin.server.run', $r) }}">{{ $r->label() }}</a>
            <span class="muted small">{{ $r->started_at->diffForHumans() }}</span>
            <span class="meta">{{ \Rhis\ServerAdmin\Tasks::trigger($r->trigger) }}@if ($r->user) · {{ $r->user->name }}@endif @if ($r->durationLabel()) · {{ $r->durationLabel() }}@endif @if ($r->summary) · {{ $r->summary }}@endif</span>
        </li>
    @endforeach
</ul>
@endif
