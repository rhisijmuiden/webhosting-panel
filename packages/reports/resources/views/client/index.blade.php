<x-layouts.client :title="__('Monthly report')">
    @include('reports::partials.styles')
    <div class="page-head">
        <div>
            <h1>{{ __('Monthly report') }}</h1>
            <p class="sub">{{ __('What your hosting did for you each month: visitors, uptime, backups, security and updates.') }}</p>
        </div>
        <div class="actions">
            <x-ui.button :href="route('client.reports.view', ['month' => $month->format('Y-m')])" target="_blank" rel="noopener" icon="external">{{ __('Open full page') }}</x-ui.button>
        </div>
    </div>
    @include('partials.flash')

    <div class="rp-grid">
        <div style="display:grid;gap:16px;min-width:0">
            @if ($months->count() > 1)
            <nav class="rp-months" aria-label="{{ __('Month') }}">
                @foreach ($months as $m)
                    <a href="{{ route('client.reports.index', ['month' => $m->format('Y-m')]) }}" @if ($m->equalTo($month)) aria-current="page" @endif>{{ $m->locale(app()->getLocale())->translatedFormat('M Y') }}</a>
                @endforeach
            </nav>
            @endif
            <div class="rp-paper">@include('reports::partials.body')</div>
        </div>

        <div class="rp-side">
            <x-ui.card :title="__('Receive by mail')" id="delivery">
                <form method="POST" action="{{ route('client.reports.delivery') }}" class="form">
                    @csrf @method('PUT')
                    <input type="hidden" name="enabled" value="0">
                    <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $pref->enabled))>
                        <span>{{ __('Mail me the monthly report') }}<small>{{ __('At the start of each month, to :email.', ['email' => $customer->email]) }}</small></span></label>
                    <x-ui.field name="extra_emails" :label="__('Also send to')" :value="implode(', ', $pref->extra_emails ?? [])" placeholder="directie@example.nl"
                        :help="__('For example a colleague. Up to 5 addresses, separated by commas.')" />
                    <div><x-ui.button variant="dark">{{ __('Save') }}</x-ui.button></div>
                </form>
            </x-ui.card>
            <x-ui.card :title="__('Where the numbers come from')">
                <ul class="small muted" style="margin:0;padding-left:18px;display:grid;gap:6px">
                    <li>{{ __('Visitors: counted by the web server, without cookies. Bots are left out.') }}</li>
                    <li>{{ __('Online: measured by our uptime monitoring.') }}</li>
                    <li>{{ __('Backups: the copies our server made, plus the backups you made yourself.') }}</li>
                    <li>{{ __('Security: the nightly check for known vulnerabilities and malware.') }}</li>
                </ul>
            </x-ui.card>
        </div>
    </div>
</x-layouts.client>
