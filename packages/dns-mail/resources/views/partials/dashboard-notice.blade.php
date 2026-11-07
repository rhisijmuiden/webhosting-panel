@php
    // Client dashboard: DNS wizard progress for this customer's domains.
    $onboardings = \Rhis\DnsMail\Models\DnsOnboarding::with('domain')
        ->whereHas('domain', fn ($q) => $q->where('customer_id', $customer->id))
        ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'active')->whereNull('seen_at')))
        ->get();
@endphp
@foreach ($onboardings as $o)
    @if ($o->isActive())
        <div class="alert alert-ok" role="status"><x-icon name="check" /><div>
            <strong>{{ __('DNS for :domain is now live at :provider', ['provider' => \App\Support\Brand::provider(), 'domain' => $o->domain->domain]) }}</strong>
            <p class="small">{{ __('The nameservers point to the :provider Cloudflare network. We keep your website and mail records up to date.', ['provider' => \App\Support\Brand::provider()]) }}
                <a href="{{ route('client.mail.domains.connect', $o->domain->id) }}">{{ __('View') }}</a></p>
        </div></div>
    @else
        <div class="alert alert-info" role="status"><x-icon name="refresh" /><div>
            <strong>{{ __('Waiting for the nameservers of :domain', ['domain' => $o->domain->domain]) }}</strong>
            <p class="small">{{ __('Change them at your registrar to the ones shown in the DNS wizard. We check automatically and mail you when it is done.') }}
                <a href="{{ route('client.mail.domains.connect', $o->domain->id) }}">{{ __('Open the DNS wizard') }}</a></p>
        </div></div>
    @endif
@endforeach
