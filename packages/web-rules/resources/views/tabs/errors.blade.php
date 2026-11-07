@php
    $names = [400 => __('Bad request'), 401 => __('Login required'), 403 => __('Forbidden'), 404 => __('Page not found'), 410 => __('Gone'),
        429 => __('Too many requests'), 500 => __('Server error'), 502 => __('Bad gateway'), 503 => __('Temporarily unavailable'), 504 => __('Gateway timeout')];
    $pages = $rules->error_pages ?? [];
@endphp
<div class="wr-intro">
    <h2>{{ __('Error pages') }}</h2>
    <p class="muted small">{{ __('Show a page in your own style instead of the standard error. Upload the file to the website folder (for example /errors/404.html) and enter its path. Leave a field empty for the standard page.') }}</p>
    <p class="muted small">{{ __('Note: WordPress and other PHP apps show their own "page not found" page; these pages are for files that do not exist and for server errors.') }}</p>
</div>
<form method="POST" action="{{ $r('error-pages.update') }}" class="form">
    @csrf @method('PUT')
    <div class="wr-errors">
        @foreach (\Rhis\WebRules\WebRules::ERROR_CODES as $code)
            <x-ui.field :name="'pages['.$code.']'" :label="$code.' · '.$names[$code]" :value="old('pages.'.$code, $pages[(string) $code] ?? '')" :placeholder="'/errors/'.$code.'.html'" />
        @endforeach
    </div>
    <div><button type="submit" class="btn btn-dark">{{ __('Save error pages') }}</button></div>
</form>
