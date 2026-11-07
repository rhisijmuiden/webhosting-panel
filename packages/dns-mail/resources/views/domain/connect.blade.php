@php
    $r = fn (string $name, array $extra = []) => route($area . '.mail.domains.' . $name, [$domain->id, ...$extra]);
    $o = $onboarding;
    $started = $o !== null && $o->status !== \Rhis\DnsMail\Models\DnsOnboarding::EXPIRED;
    $live = $o?->isActive() ?? false;
    $current = collect($o?->current_name_servers ?? []);
    $assigned = collect($o?->name_servers ?? []);
    $old = collect($o?->original_name_servers ?? [])->diff($assigned);
    $step = fn (bool $done, bool $now) => $done ? 'is-done' : ($now ? 'is-current' : 'is-todo');
@endphp
<x-dynamic-component :component="'layouts.' . $area" :title="__('Move DNS to :provider', ['provider' => \App\Support\Brand::provider()])">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area . '.mail.domains.index') }}">{{ $area === 'admin' ? __('Domains & mail') : __('Mail & DNS') }}</a>
                / <a href="{{ $r('show', ['tab' => 'dns']) }}">{{ $domain->domain }}</a></div>
            <h1>{{ __('Move DNS to :provider', ['provider' => \App\Support\Brand::provider()]) }}</h1>
            <p class="sub">{{ __('Keep your domain where it is registered, and let :provider run its DNS on the Cloudflare network. We then set every record for your website and mail for you.', ['provider' => \App\Support\Brand::provider()]) }}</p>
        </div>
        <div class="actions">
            @if ($live)<x-ui.pill status="ok">{{ __('Live at :provider', ['provider' => \App\Support\Brand::provider()]) }}</x-ui.pill>
            @elseif ($started)<x-ui.pill status="warn">{{ __('Waiting for nameservers') }}</x-ui.pill>@endif
        </div>
    </div>

    @include('dns-mail::partials.warnings')

    @if (! $hasCloudflare)
        <div class="alert alert-warn" role="status"><x-icon name="info" /><div>{{ __('Cloudflare is not connected to the panel yet. Contact :provider.', ['provider' => \App\Support\Brand::provider()]) }}</div></div>
    @endif

    @if ($live)
        <div class="alert alert-ok" role="status"><x-icon name="check" /><div>
            <strong>{{ __('Your DNS is now live at :provider Cloudflare', ['provider' => \App\Support\Brand::provider()]) }}</strong>
            <p class="small">{{ __('Since :date. Records for your website and mail are kept up to date automatically. You can add your own records on the DNS tab.', ['date' => $o->activated_at?->isoFormat('LLL')]) }}</p>
        </div></div>
    @endif

    <x-ui.card>
        <ol class="steps">
            <li class="{{ $step($started, ! $started) }}">
                <h3>{{ __('Add your domain to :provider Cloudflare', ['provider' => \App\Support\Brand::provider()]) }}</h3>
                @if (! $started)
                    <p class="muted small">{{ __('We create :domain in our Cloudflare account, copy the records we find at your current DNS provider and add the records for your website and mail. Nothing changes for visitors yet: your current provider keeps answering until you change the nameservers.', ['domain' => $domain->domain]) }}</p>
                    @if ($o?->status === \Rhis\DnsMail\Models\DnsOnboarding::EXPIRED)
                        <p class="small"><x-ui.pill status="warn">{{ __('The previous attempt expired: the nameservers were not changed within :days days.', ['days' => \Rhis\DnsMail\Models\DnsOnboarding::MAX_DAYS]) }}</x-ui.pill></p>
                    @endif
                    <form method="POST" action="{{ $r('connect.start') }}">@csrf
                        <button type="submit" class="btn btn-primary" @disabled(! $hasCloudflare)><x-icon name="cloud" />{{ __('Add :domain', ['domain' => $domain->domain]) }}</button>
                    </form>
                @else
                    <p class="muted small">{{ trans_choice('Done. :count record was copied from your current DNS provider.|Done. :count records were copied from your current DNS provider.', $o->imported_records, ['count' => $o->imported_records]) }}</p>
                @endif
            </li>

            <li class="{{ $step($started && $domain->dns_status !== 'conflict', false) }}">
                <h3>{{ __('Check your records') }}</h3>
                @if ($started)
                    @if ($domain->dns_status === 'conflict')
                        <p class="small"><x-ui.pill status="warn">{{ __('Some copied records differ from what your hosting needs') }}</x-ui.pill></p>
                        <p class="muted small">{{ __('For example an old web server address or a mail server elsewhere. Choose on the DNS tab whether the panel may take them over.') }}</p>
                    @else
                        <p class="muted small">{{ __('Have a look whether everything you use is there, such as records for a mail service or a webshop elsewhere. Add missing ones before you change the nameservers.') }}</p>
                    @endif
                    <a class="btn btn-sm" href="{{ $r('show', ['tab' => 'dns']) }}"><x-icon name="list" />{{ __('Open DNS records') }}</a>
                @else
                    <p class="muted small">{{ __('After step 1 you can see and change all records before anything goes live.') }}</p>
                @endif
            </li>

            <li class="{{ $step($live || ($o?->nameserversMatch() ?? false), $started && ! $live) }}">
                <h3>{{ __('Change the nameservers at your registrar') }}</h3>
                @if ($started && ! $live)
                    <p class="muted small">
                        {{ __('Log in to the control panel of the company where you registered :domain', ['domain' => $domain->domain]) }}@if ($o->original_registrar) ({{ $o->original_registrar }})@endif.
                        {{ __('Look for “Nameservers” or “DNS servers”, choose your own or custom nameservers, and replace all of them with these two:') }}
                    </p>
                    <div class="ns-list" x-data="{ copied: null }">
                        @foreach ($assigned as $ns)
                            <div class="ns-item"><span>{{ $ns }}</span>
                                <button type="button" class="btn btn-ghost btn-sm" @click="navigator.clipboard?.writeText(@js($ns)); copied = @js($ns)">
                                    <span x-show="copied !== @js($ns)">{{ __('Copy') }}</span><span x-show="copied === @js($ns)" x-cloak><x-icon name="check" />{{ __('Copied') }}</span></button></div>
                        @endforeach
                    </div>
                    @if ($old->isNotEmpty())
                        <p class="muted small">{{ __('Remove the current ones:') }} @foreach ($old as $ns)<span class="mono ns-old">{{ $ns }}</span>@if (! $loop->last), @endif @endforeach</p>
                    @endif
                    <div class="alert alert-info" role="note" style="margin-top:8px"><x-icon name="info" /><div class="small">
                        <strong>{{ __('Is DNSSEC on at your registrar?') }}</strong>
                        {{ __('Switch DNSSEC off (remove the DS record) before you change the nameservers, otherwise your domain stops working until it is removed. You can switch it on again later through :provider.', ['provider' => \App\Support\Brand::provider()]) }}
                    </div></div>
                @elseif ($live)
                    <p class="muted small">{{ __('Done.') }} @foreach ($assigned as $ns)<span class="mono">{{ $ns }}</span>@if (! $loop->last), @endif @endforeach</p>
                @else
                    <p class="muted small">{{ __('You get two nameservers to enter at your registrar.') }}</p>
                @endif
            </li>

            <li class="{{ $step($live, $started && ! $live) }}">
                <h3>{{ __('We check and let you know') }}</h3>
                @if ($started && ! $live)
                    <p class="muted small">{{ __('We check automatically, often at first and then every hour. Most changes are visible within a few hours; it can take up to 48 hours. You get a mail as soon as your DNS runs on :provider Cloudflare.', ['provider' => \App\Support\Brand::provider()]) }}</p>
                    <ul class="check-list small">
                        @foreach ($assigned as $ns)
                            <li>@if ($current->contains($ns))<x-ui.pill status="ok">{{ __('Found') }}</x-ui.pill>@else<x-ui.pill status="neutral">{{ __('Not yet') }}</x-ui.pill>@endif <span class="mono">{{ $ns }}</span></li>
                        @endforeach
                        @foreach ($current->diff($assigned) as $ns)
                            <li><x-ui.pill status="warn">{{ __('Still there') }}</x-ui.pill> <span class="mono">{{ $ns }}</span></li>
                        @endforeach
                    </ul>
                    <p class="muted small">
                        @if ($o->last_checked_at){{ __('Last checked :time.', ['time' => $o->last_checked_at->diffForHumans()]) }}@endif
                        @if ($o->nameserversMatch()) {{ __('The new nameservers are visible; Cloudflare confirms this shortly.') }}@endif
                    </p>
                    <form method="POST" action="{{ $r('connect.check') }}">@csrf
                        <button type="submit" class="btn btn-dark"><x-icon name="refresh" />{{ __('Check now') }}</button>
                    </form>
                @elseif ($live)
                    <p class="muted small">{{ __('Your DNS is live. We sent you a mail.') }}</p>
                @else
                    <p class="muted small">{{ __('You get a mail and a notice here when your DNS is live.') }}</p>
                @endif
            </li>
        </ol>
    </x-ui.card>
</x-dynamic-component>
