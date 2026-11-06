@php $s = $finding->isMalware() ? 'malware' : $finding->severity; @endphp
<span class="vx-chip sev-{{ $s }}">{{ $finding->isMalware() ? __('Malware') : __(ucfirst($finding->severity)) }}@if ($finding->cvss_score) · {{ number_format($finding->cvss_score, 1) }}@endif</span>
