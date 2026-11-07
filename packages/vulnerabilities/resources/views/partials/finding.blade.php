{{-- One finding as a card with the fix (client screen and admin detail). --}}
<article class="vx-finding sev-{{ $f->isMalware() ? 'malware' : $f->severity }}">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <h3>{{ $f->isMalware() ? __('Suspicious file') : $f->componentLabel() }}</h3>
        @include('vulnerabilities::partials.chip', ['finding' => $f])
    </div>
    @if ($f->isMalware())
        <p class="small"><code>{{ $f->file_path }}</code><br><span class="muted">{{ __('Detected as :signature', ['signature' => $f->title]) }}</span></p>
    @elseif ($f->isIntegrity())
        <p class="small">{{ $f->title }}</p>
        @if ($f->description)<p class="small mono" style="white-space:pre-line;margin-top:-6px">{{ $f->description }}</p>@endif
    @else
        <p class="small">{{ $f->title }}@if ($f->component_status === 'inactive') <span class="muted">({{ __('this plugin is switched off, but its files can still be attacked') }})</span>@endif</p>
    @endif
    <div class="vx-fix"><b>{{ __('How to fix it') }}</b>{{ $f->advice() }}</div>
    <div class="small" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        @if ($fixUrl ?? null)<a class="btn btn-sm btn-dark" href="{{ $fixUrl }}">{{ $f->isIntegrity() ? __('Restore original files') : __('Update now') }}</a>@endif
        @if ($f->cve)<a href="https://www.cve.org/CVERecord?id={{ urlencode($f->cve) }}" target="_blank" rel="noopener noreferrer">{{ $f->cve }}</a>@endif
        @foreach (array_slice($f->references ?? [], 0, 1) as $ref)<a href="{{ $ref }}" target="_blank" rel="noopener noreferrer">{{ __('More information') }}</a>@endforeach
        <span class="muted">{{ __('Found :time', ['time' => $f->first_seen_at?->diffForHumans()]) }}@if ($f->notified_at) · {{ __('mailed :time', ['time' => $f->notified_at->diffForHumans()]) }}@endif</span>
    </div>
</article>
