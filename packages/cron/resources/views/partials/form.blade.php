{{-- Cron job settings, used when creating a job and on the job page.
     $job (new or existing), $area, optional $websites/$website (create). --}}
@php
    $existing = $job->exists;
    $site = $job->website ?? $website ?? null;
    $presets = collect(\Rhis\Cron\Schedule::presets())->map(fn ($label, $expr) => ['expr' => $expr, 'label' => __($label)])->values();
    $labels = [
        'command' => [__('Command'), __('Runs with bash in the website folder, as the website\'s own user. Use && for several commands.'), 'cd app && php artisan schedule:run'],
        'php' => [__('PHP script'), __('Path of the script inside the website folder. It runs with the PHP version of the website.'), 'public/cron.php'],
        'url' => [__('URL'), __('The server opens this address (GET). A status of 400 or higher counts as failed.'), 'https://'.($site?->domain ?? 'example.com').'/cron'],
    ];
    $customerMail = $site?->customer?->email;
@endphp
<div x-data="{
        type: @js(old('type', $job->type ?? 'command')), schedule: @js(old('schedule', $job->schedule)),
        labels: @js($labels), preview: {label: null, next: [], error: null}, timer: null,
        notify: @js((bool) old('notify_failure', $job->exists ? $job->notify_failure : true)),
        check() {
            clearTimeout(this.timer);
            this.timer = setTimeout(async () => {
                try {
                    const res = await fetch(@js(route($area.'.cron.preview')), {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify({schedule: this.schedule})});
                    if (res.ok) this.preview = await res.json();
                } catch (e) {}
            }, 250);
        },
        init() { this.check(); }
     }" class="form" style="display:grid;gap:16px">

    <div class="form-grid">
        @if (! $existing)
            <x-ui.field name="website_id" type="select" :label="__('Website')" required>
                @foreach ($websites as $w)
                    <option value="{{ $w->id }}" @selected((int) old('website_id', $website?->id) === $w->id)>{{ $w->domain }}@if ($area === 'admin' && $w->customer) ({{ $w->customer->name }})@endif</option>
                @endforeach
            </x-ui.field>
        @endif
        <x-ui.field name="name" :label="__('Name')" :value="$job->name" required maxlength="80" :placeholder="__('Laravel scheduler')" />
    </div>

    <fieldset class="field" style="border:0;padding:0;margin:0">
        <legend class="label">{{ __('What should run?') }}</legend>
        <div class="seg" role="radiogroup">
            @foreach (\Rhis\Cron\Models\CronJob::TYPES as $t => $label)
                <label><input type="radio" name="type" value="{{ $t }}" x-model="type"><span>{{ __($label) }}</span></label>
            @endforeach
        </div>
        @error('type')<span class="error">{{ $message }}</span>@enderror
    </fieldset>

    <div class="field">
        <label for="f-command"><span x-text="labels[type][0]">{{ $labels[$job->type ?? 'command'][0] }}</span> <span aria-hidden="true">*</span></label>
        <input id="f-command" name="command" class="input mono" required maxlength="2000" spellcheck="false" autocomplete="off"
               value="{{ old('command', $job->command) }}" :placeholder="labels[type][2]" @error('command') aria-invalid="true" @enderror>
        <span class="help" x-text="labels[type][1]">{{ $labels[$job->type ?? 'command'][1] }}</span>
        @error('command')<span class="error">{{ $message }}</span>@enderror
    </div>
    <div x-show="type === 'php'" x-cloak>
        <x-ui.field name="args" :label="__('Arguments')" :value="$job->args" maxlength="1000" class="mono" placeholder="--quiet"
            :help="__('Optional: what comes after the script name.')" />
    </div>

    <div class="field">
        <label for="f-schedule">{{ __('Schedule') }} <span aria-hidden="true">*</span></label>
        <input id="f-schedule" name="schedule" class="input mono" required maxlength="100" spellcheck="false" autocomplete="off"
               x-model="schedule" x-on:input="check()" @error('schedule') aria-invalid="true" @enderror>
        <div class="cj-cron-help" aria-hidden="true"><span>{{ __('minute') }}</span><span>{{ __('hour') }}</span><span>{{ __('day') }}</span><span>{{ __('month') }}</span><span>{{ __('weekday') }}</span></div>
        <div class="cj-presets">
            <template x-for="p in @js($presets)" :key="p.expr">
                <button type="button" x-text="p.label" :class="{'is-on': schedule === p.expr}" x-on:click="schedule = p.expr; check()"></button>
            </template>
        </div>
        <div class="cj-preview" aria-live="polite">
            <strong x-show="preview.label" x-text="preview.label" x-cloak></strong>
            <span class="error" x-show="preview.error" x-text="preview.error" x-cloak></span>
            <span class="next" x-show="preview.next && preview.next.length" x-cloak>{{ __('Next:') }} <span x-text="(preview.next || []).join(' · ')"></span></span>
        </div>
        <span class="help">{{ __('Cron syntax: minute hour day month weekday. * means every, */5 every 5, 1-5 Monday to Friday. Time zone: :tz.', ['tz' => config('app.timezone')]) }}</span>
        @error('schedule')<span class="error">{{ $message }}</span>@enderror
    </div>

    <div class="form-grid">
        <x-ui.field name="timeout" type="select" :label="__('Time limit')" :help="__('A run that takes longer is stopped and counts as failed.')">
            @foreach (\Rhis\Cron\Models\CronJob::TIMEOUTS as $min => $label)
                <option value="{{ $min }}" @selected((int) old('timeout', $job->timeout ?? 60) === $min)>{{ __($label) }}</option>
            @endforeach
        </x-ui.field>
        <div class="field">
            <span class="label">{{ __('When it fails') }}</span>
            <input type="hidden" name="notify_failure" value="0">
            <label class="check"><input type="checkbox" name="notify_failure" value="1" x-model="notify"><span>{{ __('Send me an e-mail') }}</span></label>
            <div x-show="notify" x-cloak>
                <input name="notify_email" type="email" class="input" maxlength="255" value="{{ old('notify_email', $job->notify_email) }}"
                       placeholder="{{ $customerMail ?: 'you@example.com' }}" aria-label="{{ __('E-mail address') }}">
                <span class="help">{{ $customerMail ? __('Empty: :mail', ['mail' => $customerMail]) : __('Empty: the address of the customer') }}</span>
                @error('notify_email')<span class="error">{{ $message }}</span>@enderror
            </div>
        </div>
    </div>
    @if (! $existing)
        <input type="hidden" name="enabled" value="1">
    @endif
</div>
