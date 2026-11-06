<x-layouts.admin :title="__('Security alert settings')">
    <div class="page-head">
        <div><p class="crumbs"><a href="{{ route('admin.vulns.index') }}">{{ __('Security overview') }}</a></p><h1>{{ __('Security alert settings') }}</h1>
            <p class="sub">{{ __('Where the vulnerability data comes from, and when customers get a mail.') }}</p></div>
    </div>
    <form method="POST" action="{{ route('admin.vulns.settings.save') }}" class="form">
        @csrf @method('PUT')
        <section class="grid grid-2" style="align-items:start">
            <x-ui.card :title="__('Vulnerability database')">
                <fieldset class="form" style="border:0;padding:0;margin:0">
                    <legend class="sr-only">{{ __('Source') }}</legend>
                    <label class="check"><input type="radio" name="source" value="wpvulnerability" @checked(old('source', $o->configuredSource()) !== 'wordfence')>
                        <span>WPVulnerability.net<small>{{ __('Free and works straight away, no account needed. The server asks per plugin and theme (only names and versions, never your customers\' data). Combines CVE, Wordfence, Patchstack and JVN data.') }}</small></span></label>
                    <label class="check"><input type="radio" name="source" value="wordfence" @checked(old('source', $o->configuredSource()) === 'wordfence')>
                        <span>Wordfence Intelligence <x-ui.pill status="info" :dot="false">{{ __('Recommended') }}</x-ui.pill><small>{{ __('The most complete and fastest database for WordPress, with a fix per vulnerability. Free API key: wordfence.com > Account > Integrations. The full list is downloaded every night, so nothing about your sites leaves the server.') }}</small></span></label>
                </fieldset>
                <x-ui.field name="wordfence_key" type="password" :label="__('Wordfence Intelligence API key')" autocomplete="off"
                    :help="$hasKey ? __('A key is saved. Leave empty to keep it.') : __('Needed for Wordfence.')" />
                @if ($hasKey)<label class="check"><input type="checkbox" name="forget_key" value="1"><span>{{ __('Remove the saved key') }}</span></label>@endif
                @if ($db)
                    <p class="small muted">{{ $db['ready'] ? __('Local Wordfence database: :n records, updated :time.', ['n' => number_format((int) $db['records']), 'time' => \Illuminate\Support\Carbon::createFromTimestamp($db['updated_at'])->diffForHumans()]) : __('The Wordfence database has not been downloaded yet.') }}</p>
                @endif
            </x-ui.card>
            <x-ui.card :title="__('Customer mails')">
                <input type="hidden" name="mail_enabled" value="0">
                <label class="check"><input type="checkbox" name="mail_enabled" value="1" @checked(old('mail_enabled', $o->mailEnabled()))>
                    <span>{{ __('Mail customers when something is found') }}<small>{{ __('One mail per customer with all problems and how to fix them. Nothing is updated automatically.') }}</small></span></label>
                <div class="form-grid">
                    <x-ui.field name="min_severity" type="select" :label="__('Mail from severity')" :help="__('Malware is always mailed.')">
                        @foreach (['critical' => __('Critical'), 'high' => __('High and up'), 'medium' => __('Medium and up'), 'low' => __('Everything')] as $v => $l)
                            <option value="{{ $v }}" @selected(old('min_severity', $o->minSeverity()) === $v)>{{ $l }}</option>
                        @endforeach
                    </x-ui.field>
                    <x-ui.field name="reminder_days" type="number" min="0" max="90" :label="__('Reminder after (days)')" :value="$o->reminderDays()" :help="__('0 = no reminders')" />
                    <x-ui.field name="max_reminders" type="number" min="0" max="10" :label="__('Reminders at most')" :value="$o->maxReminders()" />
                    <x-ui.field name="bcc" type="email" :label="__('Copy to (BCC)')" :value="$o->bcc()" :help="__('For example your own support address.')" />
                </div>
                <x-ui.field name="support_email" type="email" :label="__('Support address in the mail')" :value="$o->supportEmail()" />
            </x-ui.card>
        </section>
        <div class="actions">
            <x-ui.button variant="primary">{{ __('Save') }}</x-ui.button>
            @if ($hasKey)<button class="btn" type="submit" form="vx-sync">{{ __('Download Wordfence database now') }}</button>@endif
        </div>
    </form>
    <form id="vx-sync" method="POST" action="{{ route('admin.vulns.sync') }}">@csrf</form>
</x-layouts.admin>
