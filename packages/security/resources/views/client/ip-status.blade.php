@if ($ipStatus && $ipStatus['blocked'])
    <div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __('Your IP address :ip is blocked on some services. Add it to the allowlist of your website, or contact support.', ['ip' => $ip]) }}</div>
@else
    <div class="alert alert-info" role="status"><x-icon name="shield" />{{ __('Your IP address is :ip and it is not blocked.', ['ip' => $ip]) }}</div>
@endif
