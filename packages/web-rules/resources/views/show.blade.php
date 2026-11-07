{{-- Redirects & access of one website. Shared by the client and the admin panel ($area). --}}
@php
    $r = fn (string $name, array $extra = []) => route($area.'.web-rules.'.$name, [$website, ...$extra]);
    $labels = ['redirects' => __('Redirects'), 'folders' => __('Password protection'), 'errors' => __('Error pages'), 'https' => __('HTTPS & HSTS'), 'access' => __('Block visitors')];
    $sum = $rules->summary();
    $counts = ['redirects' => $sum['redirects'], 'folders' => $sum['folders'], 'errors' => $sum['error_pages']];
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Redirects & access').' · '.$website->domain">
    <link rel="stylesheet" href="{{ asset('assets/site-overview.css') }}?v={{ @filemtime(public_path('assets/site-overview.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/web-rules.css') }}?v={{ @filemtime(public_path('assets/web-rules.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.websites.show', $website) }}">{{ $website->domain }}</a> / {{ __('Redirects & access') }}</div>
            <h1>{{ __('Redirects & access') }}</h1>
            <p class="muted">{{ __('Redirect old addresses, protect folders with a password, show your own error pages and decide who may visit :domain.', ['domain' => $website->domain]) }}</p>
        </div>
        <a class="btn" href="{{ route($area.'.websites.show', $website) }}">{{ __('Back to the website') }}</a>
    </div>

    @if (session('status'))<div class="alert alert-ok" role="status"><x-icon name="check" />{{ session('status') }}</div>@endif
    @if (session('warning'))<div class="alert alert-warn" role="alert"><x-icon name="info" />{{ session('warning') }}</div>@endif
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (session('missing_pages'))
        <div class="alert alert-warn" role="alert"><x-icon name="info" />
            <span>{{ __('These error pages do not exist yet, visitors get the standard page until you upload them:') }}
                @foreach (session('missing_pages') as $m)<code>{{ $m['code'] }} {{ $m['path'] }}</code>@if (! $loop->last), @endif @endforeach</span>
        </div>
    @endif

    <section class="card wr-main">
        <nav class="site-tabs" aria-label="{{ __('Sections') }}">
            @foreach ($tabs as $key)
                <a href="{{ $r('show', ['tab' => $key]) }}" @class(['is-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>
                    {{ $labels[$key] }}@if (($counts[$key] ?? 0) > 0) <span class="wr-count">{{ $counts[$key] }}</span>@endif
                    @if ($key === 'access' && $sum['blocking']) <span class="wr-count wr-count-on">{{ __('on') }}</span>@endif
                </a>
            @endforeach
        </nav>
        <div class="wr-body">
            @include('web-rules::tabs.'.$tab)
        </div>
    </section>
</x-dynamic-component>
