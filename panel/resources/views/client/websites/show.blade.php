@php
    /** @var \App\Support\SiteOverview $o */
    $site = $o->website;
    [$from, $to] = $o->hues();
    $wp = $o->wordpress->first();
    $sub = $site->subscription;
    $pkg = $sub?->package;
    $tabs = $o->tabs();
@endphp
<x-layouts.client :title="$site->domain">
    <link rel="stylesheet" href="{{ asset('assets/site-overview.css') }}?v={{ @filemtime(public_path('assets/site-overview.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('client.websites.index') }}">{{ __('Websites') }}</a> / {{ $site->domain }}</div>
            <div class="actions"><h1>{{ $site->domain }}</h1>
                <x-ui.pill :status="$site->status">{{ $site->status === 'active' ? __('Online') : __(ucfirst($site->status)) }}</x-ui.pill>
                <x-ui.pill :status="$site->ssl_status">{{ $site->ssl_status === 'active' ? __('SSL valid') : __('SSL :status', ['status' => __($site->ssl_status)]) }}</x-ui.pill></div>
        </div>
        <div class="actions">
            @if ($o->has('client.files.browse'))
                <a class="btn" href="{{ route('client.files.browse', $site) }}">@include('client.websites.overview.icon', ['name' => 'folder']){{ __('Files') }}</a>
            @endif
            <a class="btn btn-primary" href="https://{{ $site->domain }}" target="_blank" rel="noopener">{{ __('Visit website') }}<x-icon name="external" /></a>
        </div>
    </div>

    <div class="site-page">
        {{-- Left: what the website is --}}
        <aside class="site-side">
            <div class="card site-card">
                <a class="site-preview" href="https://{{ $site->domain }}" target="_blank" rel="noopener" style="--from: {{ $from }}; --to: {{ $to }}" aria-label="{{ __('Visit website') }}">
                    <span class="site-preview-nav"><i></i><i></i><i></i><i></i></span>
                    <span class="site-preview-letter">{{ mb_strtoupper(mb_substr($site->domain, 0, 1)) }}</span>
                    <span class="site-preview-bar"></span><span class="site-preview-bar short"></span>
                    <span class="site-preview-open"><x-icon name="external" />{{ __('Open website') }}</span>
                </a>
                <div class="site-body">
                    <dl class="kv kv-tight">
                        <dt>{{ __('Type') }}</dt><dd>{{ $site->profile === 'wordpress' ? 'WordPress' : __(ucfirst($site->profile ?? 'php')) }}</dd>
                        <dt>PHP</dt><dd>{{ $site->php_version }}</dd>
                        <dt>{{ __('Page cache') }}</dt><dd>{{ $site->page_cache ? __('On') : __('Off') }}</dd>
                    </dl>
                </div>
            </div>

            @if ($wp)
                <div class="card">
                    <div class="card-head"><h2>{{ __('Log in to WordPress') }}</h2></div>
                    <p class="muted small">{{ __('Straight into the dashboard of :site, without a password.', ['site' => $wp->label()]) }}</p>
                    <div class="actions">
                        <form method="POST" action="{{ route('client.wordpress.login', $wp) }}" target="_blank">@csrf
                            <button class="btn btn-primary" type="submit"><x-icon name="wordpress" />{{ __('WP admin') }}</button></form>
                        <a class="btn btn-ghost" href="{{ route('client.wordpress.show', $wp) }}">{{ __('Settings') }}</a>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-head"><h2>{{ __('Usage') }}</h2></div>
                <div class="usage">
                    <div><span>{{ __('Disk space') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($site->disk_used_mb * 1024 * 1024) }}@if ($o->diskQuotaMb()) / {{ \Illuminate\Support\Number::fileSize($o->diskQuotaMb() * 1024 * 1024) }}@endif</strong></div>
                    @if ($o->diskPercent() !== null)<x-ui.meter label="" :percent="$o->diskPercent()" />@endif
                    <div><span>{{ __('Data traffic this month') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($site->trafficBytes(), 1) }}</strong></div>
                    <div><span>{{ __('Databases') }}</span><strong>{{ $site->databases()->count() }}</strong></div>
                    @if ($o->mailDomain && $o->hasLocalMail())
                        <div><span>{{ __('Mailboxes') }}</span><strong>{{ $o->mailDomain->mailboxes_count }}</strong></div>
                    @endif
                </div>
            </div>
        </aside>

        {{-- Middle: tabs --}}
        <section class="card site-main">
            <nav class="site-tabs" aria-label="{{ __('Sections') }}">
                @foreach ($tabs as $key => $label)
                    <a href="{{ route('client.websites.show', [$site, 'tab' => $key]) }}" @class(['is-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="site-tab">
                @include('client.websites.overview.tab-'.$tab)
            </div>
            <footer class="site-foot">
                <span>{{ __('Website in') }} <code>{{ $site->document_root ? \Illuminate\Support\Str::afterLast(rtrim($site->document_root, '/'), '/') : 'public_html' }}</code></span>
                @if ($o->serverIp)<span>{{ __('IP address') }} <code>{{ $o->serverIp }}</code></span>@endif
                @if ($site->system_user)<span>{{ __('System user') }} <code>{{ $site->system_user }}</code></span>@endif
            </footer>
        </section>

        {{-- Right: shortcuts and package --}}
        <aside class="site-rail">
            <div class="card">
                <div class="card-head"><h2>{{ __('Quick links') }}</h2></div>
                <div class="quick-links">
                    @foreach (array_filter([
                        $o->has('client.backups.index') ? [route('client.backups.index'), 'backup', __('Backups')] : null,
                        $o->has('client.files.browse') ? [route('client.files.browse', $site), 'folder', __('File manager')] : null,
                        $wp ? [route('client.wordpress.show', $wp), 'wordpress', 'WordPress'] : null,
                        $o->mailDomain ? [$o->mailLink('mailboxes'), 'mail', __('Mailboxes')] : null,
                        $o->mailDomain ? [$o->mailLink('dns'), 'globe', __('DNS records')] : null,
                        $o->has('client.protection.show') ? [route('client.protection.show', $site), 'shield', __('Protection and allowlist')] : null,
                        $o->has('client.git.index') && config('git.customer_access') ? [route('client.git.index'), 'git', __('Git deployments')] : null,
                    ]) as [$href, $icon, $label])
                        <a href="{{ $href }}"><span class="kpi-icon">@include('client.websites.overview.icon', ['name' => $icon])</span>{{ $label }}<x-icon name="chevron" /></a>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>{{ __('Subscription') }}</h2></div>
                <dl class="kv kv-tight">
                    <dt>{{ __('Package') }}</dt><dd>{{ $pkg?->name ?? '–' }}</dd>
                    <dt>{{ __('Status') }}</dt><dd><x-ui.pill :status="$site->status">{{ $site->status === 'active' ? __('Active') : __(ucfirst($site->status)) }}</x-ui.pill></dd>
                    <dt>{{ __('Holder') }}</dt><dd>{{ $site->customer?->displayName() }}</dd>
                    @if ($sub?->created_at)<dt>{{ __('Setup date') }}</dt><dd>{{ $sub->created_at->isoFormat('LL') }}</dd>@endif
                    @if ($sub?->expires_on)<dt>{{ __('Expiry date') }}</dt><dd>{{ $sub->expires_on->isoFormat('LL') }}</dd>@endif
                </dl>
                <div class="usage">
                    <div><span>{{ __('Disk space') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($site->disk_used_mb * 1024 * 1024) }} / {{ $pkg ? $pkg->limitLabel('disk_mb') : __('Unlimited') }}</strong></div>
                    @if ($sub?->diskPercent() !== null)<x-ui.meter label="" :percent="$sub->diskPercent()" />@endif
                    <div><span>{{ __('Data traffic') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($site->trafficBytes(), 1) }} / {{ $pkg ? $pkg->limitLabel('traffic_gb_month') : __('Unlimited') }}</strong></div>
                    @if ($sub?->trafficPercent() !== null)<x-ui.meter label="" :percent="$sub->trafficPercent()" />@endif
                    <div><span>{{ __('Databases') }}</span><strong>{{ $site->databases()->count() }} / {{ $pkg ? $pkg->limitLabel('max_databases') : __('Unlimited') }}</strong></div>
                </div>
                @if ($o->has('client.shop.index'))
                    <a class="btn btn-sm" href="{{ route('client.shop.index') }}" style="align-self:flex-start">{{ __('More space? Upgrade your package') }}</a>
                @endif
            </div>
        </aside>
    </div>
</x-layouts.client>
