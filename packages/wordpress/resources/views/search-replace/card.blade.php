{{-- Entry on the WordPress install page: change domain and search & replace. --}}
@php
    $srR = fn ($name, $p) => route($area.'.wordpress.sr.'.$name, $p);
    $srLast = \Rhis\WordPress\SearchReplace\SearchReplaceRun::where('wordpress_install_id', $install->id)->latest('id')->first();
@endphp
<link rel="stylesheet" href="{{ asset('assets/wp-search-replace.css') }}?v={{ @filemtime(public_path('assets/wp-search-replace.css')) }}">
<x-ui.card :title="__('Addresses and content')">
    <div class="wsr-tiles">
        <a class="wsr-tile" href="{{ $srR('domain', $install) }}">
            <x-icon name="globe" />
            <span><strong>{{ __('Change domain') }}</strong><p class="small muted">{{ __('Move this WordPress to another address of the website, or switch to https. All links follow.') }}</p></span>
        </a>
        <a class="wsr-tile" href="{{ $srR('form', $install) }}">
            <x-icon name="search" />
            <span><strong>{{ __('Search & replace') }}</strong><p class="small muted">{{ __('Replace text everywhere in the database, safe for serialized data. You see a preview first.') }}</p></span>
        </a>
    </div>
    @if ($srLast)
        <p class="small muted" style="margin:12px 0 0">{{ __('Last run') }}:
            <a href="{{ $srR('show', $srLast) }}" class="wsr-break">{{ $srLast->summary() }}</a>
            @include('wordpress::search-replace.status', ['run' => $srLast])
            · {{ $srLast->created_at->diffForHumans() }}</p>
    @endif
</x-ui.card>
