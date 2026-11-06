@php
    $phpSettings = app(\App\Services\PhpSettings::class);
    $server = $phpSettings->server();
    $own = $website->php_settings ?? [];
    $effective = $phpSettings->effective($website);
@endphp
<x-ui.card :title="__('PHP settings')">
    <form method="POST" action="{{ $action }}" class="form">
        @csrf @method('PUT')
        <p class="muted small">{{ __('Leave a field empty to use the server default. Uploads and timeouts of the web server follow these values automatically.') }}</p>
        <div class="form-grid">
            @foreach (\App\Services\PhpSettings::KEYS as $key => [$label, $unit, , , $lowest])
                @php $err = $errors->first("php.$key"); @endphp
                <div class="field">
                    <label for="php-{{ $key }}">{{ __($label) }}</label>
                    <span class="input-unit"><input id="php-{{ $key }}" class="input" type="number" name="php[{{ $key }}]" min="{{ $lowest }}" max="{{ $server[$key]['max'] }}"
                        value="{{ old("php.$key", $own[$key] ?? '') }}" placeholder="{{ $server[$key]['default'] }}" @if ($err) aria-invalid="true" @endif>
                        @if ($unit)<span class="unit">{{ $unit }}</span>@endif</span>
                    <span class="help">{{ __('Now :value, maximum :max', ['value' => $effective[$key].($unit ? ' '.$unit : ''), 'max' => $server[$key]['max'].($unit ? ' '.$unit : '')]) }}</span>
                    @if ($err)<span class="error">{{ $err }}</span>@endif
                </div>
            @endforeach
            @if ($server['customer_display_errors'])
                <x-ui.field name="php[display_errors]" type="select" :label="__('Show PHP errors in the browser')" :help="__('Only for testing: errors can show paths and passwords to visitors.')">
                    @php $de = old('php.display_errors', array_key_exists('display_errors', $own) ? ($own['display_errors'] ? 'on' : 'off') : ''); @endphp
                    <option value="" @selected($de === '')>{{ __('Server default (:value)', ['value' => $server['display_errors'] ? __('on') : __('off')]) }}</option>
                    <option value="off" @selected($de === 'off')>{{ __('Off') }}</option>
                    <option value="on" @selected($de === 'on')>{{ __('On') }}</option>
                </x-ui.field>
            @endif
        </div>
        <p class="small muted">{{ trans_choice('This website handles up to :count request at the same time.|This website handles up to :count requests at the same time.', $phpSettings->workers($website)) }}</p>
        <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
    </form>
</x-ui.card>
