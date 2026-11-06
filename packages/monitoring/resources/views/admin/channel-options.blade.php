<div class="form-grid">
    <x-ui.field name="min_severity" type="select" :label="__('Send')">
        <option value="warning">{{ __('Warnings and critical problems') }}</option>
        <option value="critical">{{ __('Only critical problems') }}</option>
    </x-ui.field>
    <x-ui.field name="locale" type="select" :label="__('Language')">
        <option value="en" @selected(app()->getLocale() === 'en')>English</option>
        <option value="nl" @selected(app()->getLocale() === 'nl')>Nederlands</option>
    </x-ui.field>
</div>
<input type="hidden" name="recoveries" value="0">
<label class="check"><input type="checkbox" name="recoveries" value="1" checked><span>{{ __('Also send a message when a problem is solved') }}</span></label>
