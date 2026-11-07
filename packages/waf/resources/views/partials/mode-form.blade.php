{{-- $action, $current (stored mode or null), $allowDefault, $defaultMode --}}
<form method="POST" action="{{ $action }}" class="form">
    @csrf @method('PUT')
    <div class="waf-mode" role="radiogroup" aria-label="{{ __('Mode') }}">
        @if ($allowDefault)
            <label class="m-default"><input type="radio" name="mode" value="default" @checked($current === null)>
                <strong><span class="waf-dot"></span>{{ __('Server default') }}</strong><small>{{ __('Follows the server setting (now: :mode).', ['mode' => \Rhis\Waf\Http\AdminWafController::modeLabel($defaultMode)]) }}</small></label>
        @endif
        <label class="m-block"><input type="radio" name="mode" value="block" @checked($current === 'block')>
            <strong><span class="waf-dot"></span>{{ __('Blocking attacks') }}</strong><small>{{ __('Attacks get an error page and never reach the website. Recommended.') }}</small></label>
        <label class="m-detect"><input type="radio" name="mode" value="detect" @checked($current === 'detect')>
            <strong><span class="waf-dot"></span>{{ __('Detect only') }}</strong><small>{{ __('Attacks are logged but let through. Good to check for false alarms first.') }}</small></label>
        <label class="m-off"><input type="radio" name="mode" value="off" @checked($current === 'off')>
            <strong><span class="waf-dot"></span>{{ __('Off') }}</strong><small>{{ __('No checks at all. Only when something does not work otherwise.') }}</small></label>
    </div>
    <div><button type="submit" class="btn btn-dark">{{ __('Save and apply') }}</button></div>
</form>
