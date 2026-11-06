<x-layouts.admin :title="__('Monthly reports')">
    @include('reports::partials.styles')
    @php $periodName = $period->locale(app()->getLocale())->translatedFormat('F Y'); @endphp
    <div class="page-head">
        <div>
            <p class="eyebrow">@if ($o->enabled())<span class="live">{{ __('Sent automatically') }}</span><span>· {{ __('Next: :date', ['date' => $stats['next']->locale(app()->getLocale())->translatedFormat('j F, H:i')]) }}</span>@else<span class="live is-bad">{{ __('Switched off') }}</span>@endif</p>
            <h1>{{ __('Monthly reports') }}</h1>
            <p class="sub">{{ __('Every month each customer gets a mail with what their hosting did: visitors, uptime, backups, security and updates. It shows the value of their hosting.') }}</p>
        </div>
        <div class="actions">
            <form method="POST" action="{{ route('admin.reports.send-all') }}" data-confirm="{{ __('Send the report about :month to every customer who has not had it yet?', ['month' => $periodName]) }}">@csrf<input type="hidden" name="month" value="{{ $period->format('Y-m') }}">
                <x-ui.button variant="primary" icon="send">{{ __('Send :month now', ['month' => $periodName]) }}</x-ui.button></form>
        </div>
    </div>
    @include('partials.flash')

    <section class="bento" aria-label="{{ __('Totals') }}">
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="customers" /></span>{{ __('Receive the report') }}</span>
            <strong class="kpi-value">{{ $stats['receiving'] }}</strong>
            <span class="kpi-foot">{{ __('of :n customers with a website', ['n' => $stats['eligible']]) }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="send" /></span>{{ __('Sent about :month', ['month' => \Rhis\Reports\Format::monthName($period)]) }}</span>
            <strong class="kpi-value">{{ $stats['sent'] }}</strong>
            <span class="kpi-foot">@if ($stats['failed'])<b class="down">{{ trans_choice(':count failed|:count failed', $stats['failed']) }}</b>@else{{ __('No failures') }}@endif</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="refresh" /></span>{{ __('Next report') }}</span>
            <strong class="kpi-value">{{ $stats['next']->locale(app()->getLocale())->translatedFormat('j M') }}</strong>
            <span class="kpi-foot">{{ __('About :month, at :time', ['month' => \Rhis\Reports\Format::monthName($stats['next']->subMonthNoOverflow()), 'time' => $o->time()]) }}</span>
        </div>
        <div class="card kpi span-3">
            <span class="kpi-label"><span class="kpi-icon"><x-icon name="language" /></span>{{ __('Languages') }}</span>
            <strong class="kpi-value">EN · NL</strong>
            <span class="kpi-foot">{{ __('In the language of each customer') }}</span>
        </div>
    </section>

    <div class="rp-grid">
        <x-ui.card :title="__('Customers')" flush>
            <x-slot:actions>
                <form class="search" method="GET" role="search"><x-icon name="search" /><input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Name or e-mail') }}" aria-label="{{ __('Search') }}"></form>
            </x-slot:actions>
            @if ($customers->isEmpty())
                <x-ui.empty icon="customers">{{ __('No customers yet.') }}</x-ui.empty>
            @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('Report') }}</th><th>{{ __('Last sent') }}</th><th class="num"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                @foreach ($customers as $c)
                    @php
                        $p = $prefs[$c->id] ?? null;
                        $on = $p ? $p->enabled : $o->defaultOn();
                        $l = $last[$c->id] ?? null;
                    @endphp
                    <tr>
                        <td><a class="strong" href="{{ route('admin.customers.show', $c) }}">{{ $c->displayName() }}</a>
                            <div class="small muted">{{ $c->email }}@if ($p && $p->extra_emails) {{ __('+ :n more', ['n' => count($p->extra_emails)]) }}@endif · {{ strtoupper($c->locale ?: config('app.locale')) }}</div></td>
                        <td>
                            @if ($c->websites_count === 0)<x-ui.pill status="neutral">{{ __('No website') }}</x-ui.pill>
                            @elseif ($on)<x-ui.pill status="ok">{{ __('On') }}</x-ui.pill>
                            @else<x-ui.pill status="off">{{ __('Off') }}</x-ui.pill>@if ($p?->changed_by === 'customer')<div class="small muted">{{ __('by the customer') }}</div>@endif
                            @endif
                        </td>
                        <td>
                            @if ($l)
                                <div class="small"><x-ui.pill :status="$l->status === 'sent' ? 'ok' : 'bad'" :dot="false">{{ $l->status === 'sent' ? ($l->kind === 'test' ? __('Test copy') : __('Sent')) : __('Failed') }}</x-ui.pill>
                                    {{ \Rhis\Reports\ReportBuilder::month($l->period)->locale(app()->getLocale())->translatedFormat('M Y') }}</div>
                                @if ($l->summary)
                                    <div class="rp-summary">
                                        @isset($l->summary['visitors'])<span><b>{{ \Rhis\Reports\Format::number($l->summary['visitors']) }}</b> {{ __('visitors') }}</span>@endisset
                                        @isset($l->summary['uptime'])<span><b>{{ \Rhis\Reports\Format::percent($l->summary['uptime']) }}</b> {{ __('online') }}</span>@endisset
                                        @isset($l->summary['backups'])<span><b>{{ \Rhis\Reports\Format::number($l->summary['backups']) }}</b> {{ __('backups') }}</span>@endisset
                                    </div>
                                @endif
                            @else<span class="muted small">{{ __('Not yet') }}</span>@endif
                        </td>
                        <td class="num">
                            @if ($c->websites_count > 0)
                            <div class="rp-actions">
                                <x-ui.button size="sm" :href="route('admin.reports.preview', [$c, 'month' => $period->format('Y-m')])" target="_blank" rel="noopener" icon="external">{{ __('Preview') }}</x-ui.button>
                                <form method="POST" action="{{ route('admin.reports.send', $c) }}">@csrf<input type="hidden" name="month" value="{{ $period->format('Y-m') }}"><input type="hidden" name="to_me" value="1">
                                    <x-ui.button size="sm" variant="ghost" title="{{ __('Send a test copy to :email', ['email' => auth()->user()->email]) }}">{{ __('To me') }}</x-ui.button></form>
                                @if ($on)
                                <form method="POST" action="{{ route('admin.reports.send', $c) }}">@csrf<input type="hidden" name="month" value="{{ $period->format('Y-m') }}">
                                    <x-ui.button size="sm" variant="ghost" icon="send">{{ __('Send') }}</x-ui.button></form>
                                @endif
                                <form method="POST" action="{{ route('admin.reports.delivery', $c) }}">@csrf @method('PUT')<input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
                                    <x-ui.button size="sm" variant="ghost">{{ $on ? __('Switch off') : __('Switch on') }}</x-ui.button></form>
                            </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>

        <div class="rp-side">
            <x-ui.card :title="__('Settings')">
                <form method="POST" action="{{ route('admin.reports.settings.save') }}" class="form">
                    @csrf @method('PUT')
                    <input type="hidden" name="enabled" value="0">
                    <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $o->enabled()))>
                        <span>{{ __('Send the monthly report automatically') }}<small>{{ __('One mail per customer about the month before.') }}</small></span></label>
                    <input type="hidden" name="default_on" value="0">
                    <label class="check"><input type="checkbox" name="default_on" value="1" @checked(old('default_on', $o->defaultOn()))>
                        <span>{{ __('On for new customers') }}<small>{{ __('Customers can switch it off themselves in their panel or with the link in the mail.') }}</small></span></label>
                    <div class="form-grid">
                        <x-ui.field name="day" type="number" min="1" max="28" :label="__('Day of the month')" :value="$o->day()" />
                        <x-ui.field name="time" type="time" :label="__('Time')" :value="$o->time()" />
                    </div>
                    <x-ui.field name="support_email" type="email" :label="__('Support address in the mail')" :value="$o->get('support_email')" :help="__('Empty = the sender address of the mail server.')" />
                    <x-ui.field name="bcc" type="email" :label="__('Copy to (BCC)')" :value="$o->bcc()" :help="__('For example your own address, to see what customers get.')" />
                    <div><x-ui.button variant="dark">{{ __('Save') }}</x-ui.button></div>
                </form>
            </x-ui.card>
            <x-ui.card :title="__('Recently sent')" flush>
                @if ($history->isEmpty())
                    <x-ui.empty icon="send">{{ __('No reports sent yet.') }}</x-ui.empty>
                @else
                <ul class="list-plain" style="list-style:none;margin:0;padding:0">
                    @foreach ($history as $h)
                        <li style="display:flex;justify-content:space-between;gap:12px;padding:10px 20px;border-top:1px solid var(--line-soft)">
                            <span><strong class="small">{{ $h->customer?->displayName() ?? '–' }}</strong><br><span class="small muted">{{ \Rhis\Reports\ReportBuilder::month($h->period)->locale(app()->getLocale())->translatedFormat('F Y') }} · {{ $h->sent_at->locale(app()->getLocale())->diffForHumans() }}</span></span>
                            <x-ui.pill :status="$h->status === 'sent' ? 'ok' : 'bad'">{{ $h->status === 'sent' ? ($h->kind === 'test' ? __('Test copy') : __('Sent')) : __('Failed') }}</x-ui.pill>
                        </li>
                    @endforeach
                </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
    {{ $customers->links('partials.pagination') }}
</x-layouts.admin>
