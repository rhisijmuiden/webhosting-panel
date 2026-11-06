<x-layouts.admin :title="__('Maintenance window')">
    @include('server-admin::partials.styles')
    <div class="page-head">
        <div><p class="crumbs"><a href="{{ route('admin.server.index') }}">{{ __('Server management') }}</a></p>
            <h1>{{ __('Maintenance window') }}</h1>
            <p class="sub">{{ __('When the server installs updates and reboots by itself, what happens with critical security problems, and who gets a mail.') }}</p></div>
    </div>
    @include('server-admin::partials.tabs')

    @php $days = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 0 => __('Sun')]; @endphp
    <form method="POST" action="{{ route('admin.server.maintenance.save') }}" class="form">
        @csrf @method('PUT')
        <section class="grid grid-2" style="align-items:start">
            <x-ui.card :title="__('Nightly maintenance')">
                <input type="hidden" name="window_enabled" value="0">
                <label class="check"><input type="checkbox" name="window_enabled" value="1" @checked(old('window_enabled', $o->get('window_enabled')))>
                    <span>{{ __('Use a maintenance window') }}<small>{{ __('Updates are installed in this window only, Debian\'s own daily run is switched off. Every website is checked before and after.') }}</small></span></label>
                <div class="field">
                    <span class="label">{{ __('Days') }}</span>
                    <div class="sv-days" role="group" aria-label="{{ __('Days') }}">
                        @foreach ($days as $n => $label)
                            <label><input type="checkbox" name="window_days[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', (array) old('window_days', $o->get('window_days'))), true))><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                </div>
                <div class="form-grid">
                    <x-ui.field name="window_start" type="time" :label="__('From')" :value="$o->get('window_start')" required />
                    <x-ui.field name="window_end" type="time" :label="__('Until')" :value="$o->get('window_end')" required :help="__('Time zone: :tz', ['tz' => $o->timezone()])" />
                </div>
                <x-ui.field name="window_install" type="select" :label="__('Install')">
                    <option value="security" @selected(old('window_install', $o->get('window_install')) === 'security')>{{ __('Security updates (recommended)') }}</option>
                    <option value="all" @selected(old('window_install', $o->get('window_install')) === 'all')>{{ __('All updates') }}</option>
                </x-ui.field>
                <input type="hidden" name="window_reboot" value="0">
                <label class="check"><input type="checkbox" name="window_reboot" value="1" @checked(old('window_reboot', $o->get('window_reboot')))>
                    <span>{{ __('Reboot when an update needs it') }}<small>{{ __('For example a new kernel or a new OpenSSL. Only inside the window; afterwards services that did not come back are restarted and every website is checked again.') }}</small></span></label>
                @if ($nextWindow)<p class="small muted">{{ __('Next window: :time', ['time' => $nextWindow->isoFormat('dddd D MMMM HH:mm')]) }}</p>@endif
            </x-ui.card>

            <x-ui.card :title="__('Critical security updates')">
                <input type="hidden" name="critical_enabled" value="0">
                <label class="check"><input type="checkbox" name="critical_enabled" value="1" @checked(old('critical_enabled', $o->get('critical_enabled')))>
                    <span>{{ __('Patch critical CVEs straight away') }}<small>{{ __('Every hour the server looks which CVEs the waiting security updates fix (from the Debian changelog) and their CVSS score (NVD). At or above the score below it installs them at once, also outside the window, and mails you before it starts.') }}</small></span></label>
                <div class="form-grid">
                    <x-ui.field name="critical_threshold" type="number" step="0.1" min="4" max="10" :label="__('From CVSS score')" :value="$o->get('critical_threshold')" />
                    <x-ui.field name="critical_reboot_delay" type="number" min="1" max="240" :label="__('Reboot after (minutes)')" :value="$o->get('critical_reboot_delay')" :help="__('Time between the mail and the reboot.')" />
                </div>
                <x-ui.field name="critical_reboot" type="select" :label="__('Reboot for a critical update')">
                    <option value="soon" @selected(old('critical_reboot', $o->get('critical_reboot')) === 'soon')>{{ __('Soon, after the minutes above (recommended)') }}</option>
                    <option value="window" @selected(old('critical_reboot', $o->get('critical_reboot')) === 'window')>{{ __('In the next maintenance window') }}</option>
                </x-ui.field>
                <x-ui.field name="nvd_api_key" type="password" :label="__('NVD API key (optional)')" autocomplete="off"
                    :help="$hasNvdKey ? __('A key is saved. Leave empty to keep it.') : __('Without a key the NVD allows 5 lookups per 30 seconds, which is enough. Free key: nvd.nist.gov/developers/request-an-api-key')" />
                @if ($hasNvdKey)<label class="check"><input type="checkbox" name="forget_nvd_key" value="1"><span>{{ __('Remove the saved key') }}</span></label>@endif
            </x-ui.card>

            <x-ui.card :title="__('PHP end of life')">
                <input type="hidden" name="php_eol_enabled" value="0">
                <label class="check"><input type="checkbox" name="php_eol_enabled" value="1" @checked(old('php_eol_enabled', $o->get('php_eol_enabled')))>
                    <span>{{ __('Move websites off PHP versions without security support') }}<small>{{ __('Each website is checked before and after the switch and goes back when it breaks. The next version is installed first when the server does not have it.') }}</small></span></label>
                <div class="form-grid">
                    <x-ui.field name="php_notice_days" type="number" min="0" max="365" :label="__('Warn customers (days before)')" :value="$o->get('php_notice_days')" />
                    <x-ui.field name="php_grace_days" type="number" min="0" max="365" :label="__('Move (days after end of life)')" :value="$o->get('php_grace_days')" />
                </div>
                <input type="hidden" name="php_notify_customers" value="0">
                <label class="check"><input type="checkbox" name="php_notify_customers" value="1" @checked(old('php_notify_customers', $o->get('php_notify_customers')))><span>{{ __('Mail customers before their websites move') }}</span></label>
                <input type="hidden" name="php_remove_old" value="0">
                <label class="check"><input type="checkbox" name="php_remove_old" value="1" @checked(old('php_remove_old', $o->get('php_remove_old')))><span>{{ __('Remove the old PHP version once no website uses it') }}</span></label>
            </x-ui.card>

            <x-ui.card :title="__('Mails')">
                <x-ui.field name="mail_mode" type="select" :label="__('Mail me')">
                    <option value="always" @selected(old('mail_mode', $o->get('mail_mode')) === 'always')>{{ __('After every maintenance') }}</option>
                    <option value="reboots" @selected(old('mail_mode', $o->get('mail_mode')) === 'reboots')>{{ __('When the server reboots, and about problems') }}</option>
                    <option value="problems" @selected(old('mail_mode', $o->get('mail_mode')) === 'problems')>{{ __('Only about problems') }}</option>
                </x-ui.field>
                <x-ui.field name="mail_to" type="text" :label="__('Send to')" :value="$o->get('mail_to')" :help="__('Comma separated. Empty: every administrator.')" />
                <p class="small muted">{{ __('Critical security updates are always mailed: before installing and before the reboot.') }}</p>
            </x-ui.card>
        </section>
        <div class="actions"><x-ui.button variant="primary">{{ __('Save') }}</x-ui.button></div>
    </form>

    <x-ui.card :title="__('Recent maintenance')" flush>
        @include('server-admin::partials.runs', ['runs' => $sessions])
    </x-ui.card>
</x-layouts.admin>
