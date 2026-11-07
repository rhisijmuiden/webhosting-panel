<x-layouts.admin :title="__('Web application firewall')">
    @include('waf::partials.styles')
    <div class="page-head">
        <div>
            <h1>{{ __('Web application firewall') }}</h1>
            <p class="sub">{{ __('ModSecurity with the OWASP Core Rule Set, the standard open source rules. Every rule that matches adds points; a request is stopped when its points reach the threshold.') }}</p>
        </div>
        <form method="POST" action="{{ route('admin.waf.apply') }}">@csrf<button class="btn" type="submit"><x-icon name="refresh" />{{ __('Send to server again') }}</button></form>
    </div>
    @include('waf::partials.admin-tabs')
    @include('waf::partials.alerts')
    @if ($status !== null && empty($status['available']))
        <div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __('The firewall is not installed on this server. It is installed with the next panel update (Info > Check for updates).') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.waf.settings.update') }}" class="form">
        @csrf @method('PUT')
        <x-ui.card :title="__('Default for websites')">
            <p class="muted small">{{ __('Used by every website that has no mode of its own.') }}</p>
            <div class="waf-mode" role="radiogroup" aria-label="{{ __('Default mode') }}">
                @foreach (['block' => __('Attacks get an error page and never reach the website. Recommended.'), 'detect' => __('Attacks are logged but let through. Good to check for false alarms first.'), 'off' => __('No checks at all. Only when something does not work otherwise.')] as $m => $help)
                    <label class="m-{{ $m }}"><input type="radio" name="default_mode" value="{{ $m }}" @checked(old('default_mode', $defaultMode) === $m)>
                        <strong><span class="waf-dot"></span>{{ \Rhis\Waf\Http\AdminWafController::modeLabel($m) }}</strong><small>{{ $help }}</small></label>
                @endforeach
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Strictness')">
            <div class="form-grid">
                <x-ui.field type="select" name="paranoia" :label="__('Paranoia level')" :help="__('Level 1 stops clear attacks with hardly any false alarms. Higher levels catch more, but also stop more normal visitors.')">
                    @foreach ([1 => __('1 – Standard (recommended)'), 2 => __('2 – Stricter'), 3 => __('3 – Strict, needs tuning'), 4 => __('4 – Paranoid')] as $n => $label)
                        <option value="{{ $n }}" @selected((int) old('paranoia', $paranoia) === $n)>{{ $label }}</option>
                    @endforeach
                </x-ui.field>
                <x-ui.field type="number" min="2" max="100" name="threshold" :value="old('threshold', $threshold)" :label="__('Blocking threshold (points)')" :help="__('5 is the standard: one critical match blocks. A higher number blocks less often.')" required />
            </div>
            <input type="hidden" name="client_control" value="0">
            <label class="check"><input type="checkbox" name="client_control" value="1" @checked(old('client_control', $clientControl))>
                <span>{{ __('Customers may change the mode and add exceptions for their own websites') }}<small>{{ __('Customers always see what was blocked on their websites.') }}</small></span></label>
            <div><button type="submit" class="btn btn-dark">{{ __('Save and apply') }}</button></div>
        </x-ui.card>
    </form>

    @include('waf::partials.exceptions', [
        'exceptions' => $exceptions, 'canChange' => true,
        'storeUrl' => route('admin.waf.exceptions.store'),
        'destroyUrl' => fn ($i) => route('admin.waf.exceptions.destroy', $i),
    ])
    <p class="muted small">{{ __('These exceptions count for all websites. WordPress editors, passwords and the Customizer are already excluded.') }}
        @if ($last) · {{ $last['ok'] ? __('Last sent to the server :time.', ['time' => \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans()]) : __('The last change did not reach the server (:at): :error', ['at' => \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans(), 'error' => $last['error'] ?? '']) }}@endif</p>
</x-layouts.admin>
