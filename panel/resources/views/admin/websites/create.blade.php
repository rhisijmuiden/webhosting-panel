<x-layouts.admin :title="__('New website')">
    <div class="page-head"><div><div class="crumbs"><a href="{{ route('admin.websites.index') }}">{{ __('Websites') }}</a></div><h1>{{ __('New website') }}</h1>
        <p class="sub">{{ __('The server creates a user, PHP pool, Nginx site and a Let\'s Encrypt certificate.') }}</p></div></div>
    @if ($customers->isEmpty())
        <x-ui.card><x-ui.empty icon="customers">{{ __('Create a customer first.') }}<x-slot:action><x-ui.button variant="primary" :href="route('admin.customers.create')">{{ __('New customer') }}</x-ui.button></x-slot:action></x-ui.empty></x-ui.card>
    @else
    <form method="POST" action="{{ route('admin.websites.store') }}" class="form" x-data="{ profile: @js(old('profile', 'php')) }">
        @csrf
        <x-ui.card>
            <div class="form-grid">
                <x-ui.field name="customer_id" type="select" :label="__('Customer')" required>
                    @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $selectedCustomer) == $c->id)>{{ $c->displayName() }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="domain" :label="__('Domain name')" placeholder="example.com" required autocomplete="off" spellcheck="false" />
            </div>
            <div class="field">
                <span class="label">{{ __('Type of website') }}</span>
                <div class="seg" role="radiogroup" aria-label="{{ __('Type of website') }}">
                    @foreach (['php' => __('PHP / static'), 'wordpress' => 'WordPress', 'laravel' => 'Laravel'] as $value => $label)
                        <label><input type="radio" name="profile" value="{{ $value }}" x-model="profile"><span>{{ $label }}</span></label>
                    @endforeach
                </div>
                <span class="help" x-show="profile === 'laravel'" x-cloak>{{ __('Laravel: the web root is app/public, page cache is off, and Composer, the scheduler and queue workers are supported.') }}</span>
                <span class="help" x-show="profile === 'wordpress'" x-cloak>{{ __('WordPress: page cache skips logged-in users and shopping carts.') }}</span>
            </div>
            <div class="field">
                <span class="label">{{ __('PHP version') }}</span>
                <div class="seg" role="radiogroup" aria-label="{{ __('PHP version') }}">
                    @foreach (\App\Models\Website::PHP_VERSIONS as $v)
                        <label><input type="radio" name="php_version" value="{{ $v }}" @checked(old('php_version', '8.4') === $v)><span>PHP {{ $v }}</span></label>
                    @endforeach
                </div>
            </div>
            <label class="check"><input type="checkbox" name="ssl" value="1" checked><span>{{ __('Let\'s Encrypt certificate') }}<small>{{ __('Requested right away and renewed automatically. If DNS is not ready yet, the server keeps trying every hour.') }}</small></span></label>
            <label class="check"><input type="checkbox" name="www_alias" value="1" checked><span>{{ __('Also answer on www.') }}<small>{{ __('Adds www.<domain> as an alias.') }}</small></span></label>
            <label class="check" x-show="profile !== 'laravel'"><input type="checkbox" name="page_cache" value="1" checked><span>{{ __('Page cache') }}<small>{{ __('Nginx serves pages from memory. Much faster, safe for most sites.') }}</small></span></label>
        </x-ui.card>
        <div class="actions"><button type="submit" class="btn btn-primary">{{ __('Create website') }}</button><a class="btn" href="{{ route('admin.websites.index') }}">{{ __('Cancel') }}</a></div>
    </form>
    @endif
</x-layouts.admin>
