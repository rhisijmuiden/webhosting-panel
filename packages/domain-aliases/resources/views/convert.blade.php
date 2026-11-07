@php
    $site = $alias->website;
    $locale = app()->getLocale();
    $price = fn ($p) => '€ '.number_format((float) $p['price']['amount'], 2, ',', '.').match ($p['price']['interval'] ?? 'once') {
        'month' => ' '.__('per month'), 'year' => ' '.__('per year'), default => '' };
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Make :domain a website', ['domain' => $alias->domain])">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.domain-aliases.index') }}">{{ __('Domain aliases') }}</a> / {{ $alias->domain }}</div>
            <h1>{{ __('Make :domain a website of its own', ['domain' => $alias->domain]) }}</h1>
            <p class="sub">{{ __('Now :alias shows :domain. As a website of its own it gets its own files, PHP settings, databases and statistics.', ['alias' => $alias->domain, 'domain' => $site->domain]) }}</p>
        </div>
    </div>
    @include('domain-aliases::partials.messages')

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('What happens')">
            <ul class="checklist small">
                <li><x-icon name="check" />{{ __('A new, empty website :domain is created in your account, with Let\'s Encrypt.', ['domain' => $alias->domain]) }}</li>
                <li><x-icon name="check" />{{ __(':domain stops showing :site.', ['domain' => $alias->domain, 'site' => $site->domain]) }}</li>
                <li><x-icon name="check" />{{ __('Mail keeps arriving: addresses @:alias stay forwarded to the mailboxes @:domain until you change them.', ['alias' => $alias->domain, 'domain' => $site->domain]) }}</li>
                <li><x-icon name="check" />{{ __('DNS stays as it is: both domains already point to this server.') }}</li>
            </ul>
        </x-ui.card>

        @if ($direct)
            <x-ui.card :title="__('Convert now')">
                <p class="small">{{ $area === 'admin'
                    ? __('As an admin you can convert directly. The website counts towards the customer\'s package.')
                    : __('Your package has room for another website, so there are no extra costs.') }}</p>
                <form method="POST" action="{{ route($area.'.domain-aliases.convert.store', $alias) }}" class="form">
                    @csrf
                    <x-ui.field name="confirm" :label="__('Type :domain to confirm', ['domain' => $alias->domain])" autocomplete="off" required />
                    <div><x-ui.button variant="primary" icon="package">{{ __('Make website') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @elseif ($order && in_array($order->status, ['requested', 'awaiting_payment', 'accepted'], true))
            <x-ui.card :title="__('Upgrade ordered')">
                <x-ui.pill status="pending">{{ __(ucfirst(str_replace('_', ' ', $order->status))) }}</x-ui.pill>
                <p class="small">{{ __(':domain becomes a website of its own as soon as :provider has processed your order.', ['provider' => \App\Support\Brand::provider(), 'domain' => $alias->domain]) }}</p>
                @if ($order->message)<p class="small muted">{{ $order->message }}</p>@endif
                @if ($order->checkout_url)<x-ui.button variant="primary" icon="external" :href="$order->checkout_url">{{ __('Pay now') }}</x-ui.button>@endif
            </x-ui.card>
        @elseif ($products !== [])
            <x-ui.card :title="__('Upgrade your package')">
                <p class="small">{{ __('Your package has no room for another website. Choose an upgrade: :provider processes the order and the website is created automatically.', ['provider' => \App\Support\Brand::provider()]) }}</p>
                <form method="POST" action="{{ route('client.domain-aliases.upgrade', $alias) }}" class="form">
                    @csrf
                    <div class="field">
                        @foreach ($products as $p)
                            <label class="check"><input type="radio" name="product" value="{{ $p['code'] }}" @checked($loop->first) required>
                                <span>{{ $p['name'][$locale] ?? $p['name']['en'] }} · <strong>{{ $price($p) }}</strong>
                                    @if (! empty($p['description']))<small>{{ $p['description'][$locale] ?? $p['description']['en'] ?? '' }}</small>@endif</span></label>
                        @endforeach
                    </div>
                    <div><x-ui.button variant="primary" icon="send">{{ __('Order upgrade') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @else
            <x-ui.card :title="__('Upgrade your package')">
                <p class="small">{{ __('Your package has no room for another website. Contact :provider to upgrade your package.', ['provider' => \App\Support\Brand::provider()]) }}</p>
            </x-ui.card>
        @endif
    </section>
</x-dynamic-component>
