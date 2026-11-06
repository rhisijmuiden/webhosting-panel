<x-layouts.admin :title="__('Monitoring settings')">
    @include('monitoring::partials.styles')
    <div class="page-head"><div><h1>{{ __('Monitoring') }}</h1><p class="sub">{{ __('How often we check and when something counts as a problem.') }}</p></div></div>
    @include('monitoring::partials.tabs')
    <form method="POST" action="{{ route('admin.monitoring.settings.save') }}" class="form">
        @csrf @method('PUT')
        <x-ui.card :title="__('Checking')">
            <div class="form">
                <input type="hidden" name="enabled" value="0">
                <label class="check"><input type="checkbox" name="enabled" value="1" @checked($o->get('enabled'))><span>{{ __('Monitoring is on') }}<small>{{ __('Switched off, nothing is checked and nobody gets alerts.') }}</small></span></label>
                <div class="form-grid">
                    <x-ui.field name="interval" type="select" :label="__('Check every')">
                        @foreach ([1, 2, 5, 10, 15] as $m)<option value="{{ $m }}" @selected($o->get('interval') == $m)>{{ trans_choice(':count minute|:count minutes', $m) }}</option>@endforeach
                    </x-ui.field>
                    <x-ui.field name="fail_after" type="number" min="1" max="10" :label="__('Down after failed checks in a row')" :value="$o->get('fail_after')" :help="__('2 means a single hiccup never wakes anybody.')" />
                    <x-ui.field name="slow_ms" type="number" min="200" :label="__('Slow from (ms)')" :value="$o->get('slow_ms')" />
                    <x-ui.field name="reminder_hours" type="number" min="0" max="168" :label="__('Remind every (hours)')" :value="$o->get('reminder_hours')" :help="__('While a problem stays open. 0 = never.')" />
                </div>
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Thresholds')">
            <div class="form-grid">
                <x-ui.field name="disk_warning" type="number" min="50" max="99" :label="__('Disk warning at (%)')" :value="$o->get('disk_warning')" />
                <x-ui.field name="disk_critical" type="number" min="50" max="100" :label="__('Disk critical at (%)')" :value="$o->get('disk_critical')" />
                <x-ui.field name="inode_warning" type="number" min="50" max="100" :label="__('Inodes warning at (%)')" :value="$o->get('inode_warning')" />
                <x-ui.field name="memory_warning" type="number" min="50" max="100" :label="__('Memory warning at (%)')" :value="$o->get('memory_warning')" />
                <x-ui.field name="load_warning" type="number" step="0.1" min="0.5" :label="__('Load warning per core')" :value="$o->get('load_warning')" />
                <x-ui.field name="mail_queue_warning" type="number" min="10" :label="__('Mail queue warning at')" :value="$o->get('mail_queue_warning')" />
                <x-ui.field name="ssl_warning_days" type="number" min="1" max="60" :label="__('Certificate warning (days left)')" :value="$o->get('ssl_warning_days')" :help="__('Let\'s Encrypt renews at 30 days, so this only happens when renewing fails.')" />
                <x-ui.field name="ssl_critical_days" type="number" min="0" max="30" :label="__('Certificate critical (days left)')" :value="$o->get('ssl_critical_days')" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('When the whole server is down')">
            <p class="small muted" style="margin:0 0 12px">{{ __('A server cannot report that it is down itself. Add a free external check (for example Healthchecks.io or Uptime Kuma with a push monitor): we call its address after every check, and it alerts you when the calls stop.') }}</p>
            <x-ui.field name="heartbeat_url" :label="__('Heartbeat address')" :value="$o->get('heartbeat_url')" placeholder="https://hc-ping.com/…" />
            @if ($heartbeat)<p class="small {{ $heartbeat['ok'] ? 'muted' : '' }}" style="margin:8px 0 0;{{ $heartbeat['ok'] ? '' : 'color:var(--bad-ink)' }}">{{ $heartbeat['ok'] ? __('Last heartbeat sent :time.', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($heartbeat['at'])->diffForHumans()]) : __('Last heartbeat failed: :error', ['error' => $heartbeat['error'] ?? 'HTTP error']) }}</p>@endif
        </x-ui.card>
        <x-ui.card :title="__('Customers and status page')">
            <div class="form">
                <input type="hidden" name="customer_alerts" value="0">
                <label class="check"><input type="checkbox" name="customer_alerts" value="1" @checked($o->get('customer_alerts'))><span>{{ __('Customers may get a mail when their website goes down') }}<small>{{ __('They switch it on themselves under Status in their panel.') }}</small></span></label>
                <input type="hidden" name="status_page" value="0">
                <label class="check"><input type="checkbox" name="status_page" value="1" @checked($o->get('status_page'))><span>{{ __('Public status page') }}<small>{{ route('status.public') }} · {{ __('Shows only the platform (websites, email, databases, panel), never customer names or domains.') }}</small></span></label>
                <x-ui.field name="status_title" :label="__('Title of the status page')" :value="$o->get('status_title')" />
            </div>
        </x-ui.card>
        <div><x-ui.button variant="dark">{{ __('Save') }}</x-ui.button></div>
    </form>
</x-layouts.admin>
