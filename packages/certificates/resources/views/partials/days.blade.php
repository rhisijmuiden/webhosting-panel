{{-- $cert (SiteCertificate|null): "valid until" with days left, coloured by health. --}}
@if ($cert && $cert->not_after && $cert->source !== 'none')
    @php $d = $cert->daysLeft(); @endphp
    <span @class(['cert-days', 'is-bad' => $cert->health() === 'bad'])>{{ $cert->not_after->format('d-m-Y') }}</span>
    <div class="muted small">{{ $cert->isExpired() ? __('expired') : trans_choice(':count day left|:count days left', $d) }}</div>
@else
    <span class="muted">–</span>
@endif
