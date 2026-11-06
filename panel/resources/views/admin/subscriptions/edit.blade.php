@php $w = $subscription->website; @endphp
<x-layouts.admin :title="$w?->domain ?? __('Subscription')">
    <link rel="stylesheet" href="{{ asset('assets/subscriptions.css') }}?v={{ @filemtime(public_path('assets/subscriptions.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.subscriptions.index') }}">{{ __('Subscriptions') }}</a></div>
            <div class="actions"><h1>{{ $w?->domain }}</h1>
                <x-ui.pill :status="$subscription->status">{{ __(ucfirst($subscription->status)) }}</x-ui.pill></div>
            <p class="sub">{{ __('Set up on :date', ['date' => $subscription->created_at?->isoFormat('LL')]) }}@if ($subscription->suspended_reason) · {{ __($subscription->suspended_reason) }}@endif</p>
        </div>
        <div class="actions">
            @if ($w)<x-ui.button icon="globe" :href="route('admin.websites.show', $w)">{{ __('Open website') }}</x-ui.button>@endif
        </div>
    </div>

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Subscription')">
            <form method="POST" action="{{ route('admin.subscriptions.update', $subscription) }}" class="form">
                @csrf @method('PUT')
                <div class="subs-form-grid">
                    <x-ui.field name="hosting_package_id" type="select" :label="__('Package')">
                        <option value="">{{ __('No package (unlimited)') }}</option>
                        @foreach ($packages as $p)<option value="{{ $p->id }}" @selected(old('hosting_package_id', $subscription->hosting_package_id) == $p->id)>{{ $p->name }}@unless ($p->is_active) ({{ __('inactive') }})@endunless</option>@endforeach
                    </x-ui.field>
                    <x-ui.field name="customer_id" type="select" :label="__('Subscription holder')">
                        @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $w?->customer_id) == $c->id)>{{ $c->displayName() }}</option>@endforeach
                    </x-ui.field>
                    <x-ui.field name="status" type="select" :label="__('Status')" :help="__('Suspended: the website shows a “temporarily unavailable” page and SFTP is closed.')">
                        @foreach (\App\Models\Subscription::STATUSES as $st)<option value="{{ $st }}" @selected(old('status', $subscription->status) === $st)>{{ __(ucfirst($st)) }}</option>@endforeach
                    </x-ui.field>
                    <x-ui.field name="expires_on" type="date" :label="__('Expiry date')" :value="$subscription->expires_on?->toDateString()" :help="__('Optional. The subscription is suspended after this date.')" />
                </div>
                <x-ui.field name="notes" type="textarea" :label="__('Notes')" :value="$subscription->notes" rows="3" />
                <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Usage')">
            @php $pkg = $subscription->package; @endphp
            <dl class="kv">
                <dt>{{ __('Disk') }}</dt><dd>{{ \Illuminate\Support\Number::fileSize(($w?->disk_used_mb ?? 0) * 1024 * 1024) }} / {{ $pkg ? $pkg->limitLabel('disk_mb') : __('Unlimited') }}</dd>
                <dt>{{ __('Data traffic this month') }}</dt><dd>{{ \Illuminate\Support\Number::fileSize($w?->trafficBytes() ?? 0, 1) }} / {{ $pkg ? $pkg->limitLabel('traffic_gb_month') : __('Unlimited') }}</dd>
                <dt>{{ __('Databases') }}</dt><dd>{{ $w?->databases()->count() ?? 0 }} / {{ $pkg ? $pkg->limitLabel('max_databases') : __('Unlimited') }}</dd>
                <dt>{{ __('Mailboxes') }}</dt><dd>{{ $pkg ? $pkg->limitLabel('max_mailboxes') : __('Unlimited') }}</dd>
                <dt>CPU</dt><dd>{{ $pkg ? $pkg->limitLabel('cpu_percent') : __('Unlimited') }}</dd>
                <dt>{{ __('Memory (RAM)') }}</dt><dd>{{ $pkg ? $pkg->limitLabel('memory_mb') : __('Unlimited') }}</dd>
                <dt>{{ __('Resource jail') }}</dt><dd class="mono small">whp-sites-{{ $subscription->sliceOwner() }}.slice</dd>
            </dl>
            @if ($subscription->diskPercent() !== null)<x-ui.meter :label="__('Disk')" :percent="$subscription->diskPercent()" />@endif
            @if ($subscription->trafficPercent() !== null)<x-ui.meter :label="__('Data traffic')" :percent="min(100, $subscription->trafficPercent())" />@endif
        </x-ui.card>
    </section>
</x-layouts.admin>
