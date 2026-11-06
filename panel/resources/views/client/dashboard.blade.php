@php
    $hour = (int) now()->setTimezone(config('app.timezone'))->format('G');
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 18 ? __('Good afternoon') : __('Good evening'));
    $firstName = \Illuminate\Support\Str::before(auth()->user()->name, ' ');
    $security = (class_exists(\Rhis\Vulnerabilities\Risk::class) && \Illuminate\Support\Facades\Route::has('client.vulns.index'))
        ? rescue(fn () => \Rhis\Vulnerabilities\Risk::totals($customer->id), null, false) : null;
    $open = $security['total'] ?? 0;
    $offline = $customer->websites->where('status', '!=', 'active')->count();
    $noSsl = $customer->websites->where('ssl_status', '!=', 'active')->count();
    $wpMenu = collect(\App\Support\Menu::client()->sections())->flatten(1)->contains('key', 'wordpress');
    $hues = [['#2B1E2F', '#C9776A'], ['#1D2B3A', '#9BC5C0'], ['#1E2D50', '#9CC3FF'], ['#2E2A1E', '#E2B66B'], ['#20302A', '#8FCBA9']];
@endphp
<x-layouts.client :title="__('Overview')">
    <section class="hello">
        <svg class="hello-waves" viewBox="0 0 260 200" aria-hidden="true"><path d="M60 120a90 90 0 0 1 140 0M20 80a150 150 0 0 1 220 0" /></svg>
        <div class="hello-text">
            <p class="hello-eyebrow">{{ now()->setTimezone(config('app.timezone'))->isoFormat('dddd D MMMM') }} · {{ $customer->displayName() }}</p>
            <h1>{{ $greeting }}, {{ $firstName }}</h1>
            <p>
                @if ($offline === 0 && $open === 0)
                    {{ __('Your websites and email are working. There is nothing you need to do.') }}
                @elseif ($open > 0)
                    {{ __('Your websites are online. There is one thing you should do.') }}
                @else
                    {{ __('Not every website is online yet.') }}
                @endif
            </p>
        </div>
        @if ($open > 0)
            <div class="hello-action">
                <span class="sev-icon warn"><x-icon name="shield" /></span>
                <div><strong>{{ trans_choice(':count security problem on your websites|:count security problems on your websites', $open) }}</strong>
                    <small>{{ __('Updating usually takes a minute. We show you exactly what to do.') }}</small></div>
                <x-ui.button variant="primary" size="sm" :href="route('client.vulns.index')">{{ __('View') }}</x-ui.button>
            </div>
        @endif
    </section>

    @includeIf('dns-mail::partials.dashboard-notice', ['customer' => $customer])

    @if ($customer->websites->isEmpty())
        <x-ui.card>
            <x-ui.empty icon="globe">{{ __('You have no websites yet.') }}</x-ui.empty>
        </x-ui.card>
    @else
        <section class="site-cards">
            @foreach ($customer->websites as $site)
                @php [$from, $to] = $hues[$site->id % count($hues)]; @endphp
                <article class="card site-card">
                    <a class="site-preview" href="{{ route('client.websites.show', $site) }}" style="--from: {{ $from }}; --to: {{ $to }}" aria-hidden="true" tabindex="-1">
                        <span class="site-preview-nav"><i></i><i></i><i></i><i></i></span>
                        <span class="site-preview-letter">{{ mb_strtoupper(mb_substr($site->domain, 0, 1)) }}</span>
                        <span class="site-preview-bar"></span><span class="site-preview-bar short"></span>
                    </a>
                    <div class="site-body">
                        <div class="site-title">
                            <a class="strong" href="{{ route('client.websites.show', $site) }}">{{ $site->domain }}</a>
                            <x-ui.pill :status="$site->status">{{ $site->status === 'active' ? __('Online') : __(ucfirst($site->status)) }}</x-ui.pill>
                            <a class="btn btn-sm" href="https://{{ $site->domain }}" target="_blank" rel="noopener" style="margin-left:auto"><x-icon name="external" />{{ __('Visit website') }}</a>
                        </div>
                        <div class="site-stats">
                            <div><small>{{ __('Disk') }}</small><strong>{{ \Illuminate\Support\Number::fileSize($site->disk_used_mb * 1024 * 1024) }}</strong></div>
                            <div><small>{{ __('Data traffic this month') }}</small><strong>{{ \Illuminate\Support\Number::fileSize($site->trafficBytes(), 1) }}</strong></div>
                            <div><small>{{ __('Certificate') }}</small><strong>{{ $site->ssl_status === 'active' ? __('Valid') : __(ucfirst($site->ssl_status)) }}</strong></div>
                        </div>
                        <div class="site-actions">
                            <x-ui.button size="sm" icon="settings" :href="route('client.websites.show', $site)">{{ __('Manage') }}</x-ui.button>
                            @if ($wpMenu && $site->profile === 'wordpress')
                                <x-ui.button size="sm" icon="wordpress" :href="route('client.wordpress.index')">WordPress</x-ui.button>
                            @endif
                            <span class="muted small">PHP {{ $site->php_version }}</span>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    <section class="bento">
        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Subscriptions') }}</h2></div>
            <div class="usage">
                @forelse ($customer->websites as $site)
                    @php $sub = $site->subscription; $pkg = $sub?->package; @endphp
                    <div><span>{{ $site->domain }} <small class="muted">{{ $pkg?->name ?? __('No package') }}</small></span>
                        <strong>{{ \Illuminate\Support\Number::fileSize($site->disk_used_mb * 1024 * 1024) }}@if ($pkg?->disk_mb) / {{ $pkg->limitLabel('disk_mb') }}@endif</strong></div>
                    @if ($sub?->diskPercent() !== null)<x-ui.meter label="" :percent="$sub->diskPercent()" />@endif
                @empty
                    <p class="small muted">{{ __('No subscriptions yet.') }}</p>
                @endforelse
                <div><span>{{ __('Data traffic this month') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($customer->trafficBytes(), 1) }}</strong></div>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('client.shop.index'))
                <x-ui.button size="sm" :href="route('client.shop.index')" style="align-self:flex-start">{{ __('More space? Upgrade your package') }}</x-ui.button>
            @endif
        </div>

        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Security') }}</h2>
                @if ($security)<a class="small" href="{{ route('client.vulns.index') }}">{{ __('Open') }}</a>@endif</div>
            <ul class="checks">
                <li class="is-{{ $noSsl ? 'warn' : 'ok' }}"><x-icon name="lock" /><span>{{ __('HTTPS on all websites') }}</span>
                    <small>{{ $noSsl ? trans_choice(':count website without a certificate|:count websites without a certificate', $noSsl) : __('Let\'s Encrypt, renewed automatically') }}</small></li>
                @if ($security)
                    <li class="is-{{ $open ? 'warn' : 'ok' }}"><x-icon name="shield" /><span>{{ __('Known vulnerabilities') }}</span>
                        <small>{{ $open ? trans_choice(':count problem|:count problems', $open) : __('None found') }}</small></li>
                @endif
                <li class="is-ok"><x-icon name="firewall" /><span>{{ __('Attackers are blocked automatically') }}</span><small>CrowdSec</small></li>
            </ul>
        </div>

        <div class="card span-4">
            <div class="card-head"><h2>{{ __('Quick links') }}</h2></div>
            <div class="quick-links">
                @foreach ([
                    ['client.websites.index', 'globe', __('Websites')],
                    ['client.mail.domains.index', 'mail', __('Email')],
                    ['client.backups.index', 'backup', __('Backups')],
                    ['client.apps.index', 'apps', __('Install an app')],
                ] as [$route, $icon, $label])
                    @if (\Illuminate\Support\Facades\Route::has($route))
                        <a href="{{ route($route) }}"><span class="kpi-icon"><x-icon :name="$icon" /></span>{{ $label }}<x-icon name="chevron" /></a>
                    @endif
                @endforeach
            </div>
        </div>
    </section>
</x-layouts.client>
