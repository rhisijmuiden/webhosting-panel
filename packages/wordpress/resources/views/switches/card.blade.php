{{-- Quick switches on the install page (admin and client). The composer adds $switches and $switchesError. --}}
@php $url = fn (string $name) => \Illuminate\Support\Facades\Route::has($area.'.wordpress.switches.'.$name) ? route($area.'.wordpress.switches.'.$name, $install) : null; @endphp
@once<link rel="stylesheet" href="{{ asset('assets/wordpress-switches/switches.css') }}?v={{ @filemtime(public_path('assets/wordpress-switches/switches.css')) }}">@endonce
<x-ui.card :title="__('Quick switches')" id="switches">
    @if ($switchesError)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $switchesError }}</div>@endif
    @include('wordpress::switches.panel', ['switches' => $switches, 'url' => $url, 'area' => $area, 'compact' => false])
</x-ui.card>
