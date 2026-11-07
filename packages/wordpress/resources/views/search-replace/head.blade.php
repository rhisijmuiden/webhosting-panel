{{-- Page head shared by the three screens: $title, $step (1 choose, 2 preview, 3 done). --}}
<link rel="stylesheet" href="{{ asset('assets/wp-search-replace.css') }}?v={{ @filemtime(public_path('assets/wp-search-replace.css')) }}">
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / <a href="{{ route($area.'.wordpress.show', $install) }}">{{ $install->label() }}</a></div>
        <h1>{{ $title }}</h1>
        @isset($sub)<p class="sub">{{ $sub }}</p>@endisset
    </div>
    <div class="actions"><x-ui.button :href="route($area.'.wordpress.show', $install)" icon="wordpress">{{ __('Back to the site') }}</x-ui.button></div>
</div>
<ol class="wsr-steps" aria-label="{{ __('Steps') }}">
    @foreach ([1 => __('Choose'), 2 => __('Check the preview'), 3 => __('Done')] as $n => $label)
        <li class="{{ $n === $step ? 'on' : ($n < $step ? 'done' : '') }}" @if ($n === $step) aria-current="step" @endif>{{ $label }}</li>
    @endforeach
</ol>
@foreach ($agentErrors ?? [] as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
@error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
