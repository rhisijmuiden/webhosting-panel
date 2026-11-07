{{-- Quick switches inside a WordPress block of the domain page. Needs $o (SiteOverview) and $i (install); the composer adds $switches. --}}
@php $url = fn (string $name) => $o->url('wordpress.switches.'.$name, $i); @endphp
@if ($url('cache'))
    @once<link rel="stylesheet" href="{{ asset('assets/wordpress-switches/switches.css') }}?v={{ @filemtime(public_path('assets/wordpress-switches/switches.css')) }}">@endonce
    <div class="wps-compact">
        <h3>{{ __('Quick switches') }}</h3>
        @include('wordpress::switches.panel', ['switches' => $switches, 'url' => $url, 'area' => $o->area, 'compact' => true])
    </div>
@endif
