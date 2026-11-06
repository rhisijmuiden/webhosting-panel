<x-layouts.admin :title="__('Quick view')">
    <div class="quick" x-data="quickSearch(@js(route('admin.quick.search')))">
        <div class="page-head">
            <div>
                <h1>{{ __('Quick view') }}</h1>
                <p class="sub">{{ __('Find a customer or domain and help them right away, also from your phone.') }}</p>
            </div>
        </div>

        <div class="quick-search">
            <x-icon name="search" />
            <input type="search" x-model="query" @input.debounce.250ms="run()" x-ref="input" enterkeyhint="search" autocomplete="off"
                   placeholder="{{ __('Customer, e-mail, domain or CRM number') }}" aria-label="{{ __('Search') }}">
            <button type="button" class="btn btn-ghost icon-btn" x-show="query" x-cloak @click="clear()" aria-label="{{ __('Clear') }}"><x-icon name="close" /></button>
        </div>

        <template x-if="query.length >= 2">
            <div class="card card-flush">
                <ul class="m-list" aria-live="polite">
                    <template x-for="r in results" :key="r.url">
                        <li><a :href="r.url" class="m-item">
                            <span class="m-avatar" :class="'is-' + r.type" aria-hidden="true">
                                <template x-if="r.type === 'customer'"><x-icon name="user" /></template>
                                <template x-if="r.type === 'website'"><x-icon name="globe" /></template>
                            </span>
                            <span class="m-text"><strong x-text="r.title"></strong><small x-text="r.sub"></small></span>
                            <span class="m-dot" :class="'is-' + r.status" :title="r.status"></span>
                            <x-icon name="chevron" />
                        </a></li>
                    </template>
                    <li class="m-empty" x-show="!loading && results.length === 0">{{ __('Nothing found') }}</li>
                    <li class="m-empty" x-show="loading && results.length === 0">{{ __('Searching…') }}</li>
                </ul>
            </div>
        </template>

        <div x-show="query.length < 2" class="quick-home">
            <div class="m-stats">
                <a href="{{ route('admin.customers.index') }}" class="card"><span class="label">{{ __('Customers') }}</span><strong>{{ $stats['customers'] }}</strong></a>
                <a href="{{ route('admin.websites.index') }}" class="card"><span class="label">{{ __('Websites') }}</span><strong>{{ $stats['websites'] }}</strong></a>
                <a href="{{ route('admin.customers.index', ['status' => 'suspended']) }}" class="card"><span class="label">{{ __('Suspended') }}</span><strong @class(['is-bad' => $stats['suspended'] > 0])>{{ $stats['suspended'] }}</strong></a>
            </div>

            <div class="m-actions">
                <a class="m-action" href="{{ route('admin.customers.create') }}"><x-icon name="customers" /><span>{{ __('New customer') }}</span></a>
                <a class="m-action" href="{{ route('admin.websites.create') }}"><x-icon name="globe" /><span>{{ __('New website') }}</span></a>
                @if (Route::has('admin.mail.log.index'))
                    <a class="m-action" href="{{ route('admin.mail.log.index') }}"><x-icon name="mail" /><span>{{ __('Mail log') }}</span></a>
                @endif
                @if (Route::has('admin.backups.index'))
                    <a class="m-action" href="{{ route('admin.backups.index') }}"><x-icon name="backup" /><span>{{ __('Backups') }}</span></a>
                @endif
            </div>

            <x-ui.card :title="__('Needs attention')" flush>
                @if ($attention->isEmpty())
                    <x-ui.empty icon="check">{{ __('All websites are online.') }}</x-ui.empty>
                @else
                    <ul class="m-list">
                        @foreach ($attention as $w)
                            <li><a href="{{ route('admin.websites.show', $w) }}" class="m-item">
                                <span class="m-avatar is-bad" aria-hidden="true"><x-icon name="globe" /></span>
                                <span class="m-text"><strong>{{ $w->domain }}</strong>
                                    <small>{{ $w->customer?->displayName() }} · {{ $w->last_error ? __($w->last_error) : (in_array($w->status, ['suspended', 'failed']) ? __(ucfirst($w->status)) : __('SSL certificate problem')) }}</small></span>
                                <x-ui.pill :status="$w->status === 'active' ? 'warn' : $w->status">{{ $w->status === 'active' ? 'SSL' : __(ucfirst($w->status)) }}</x-ui.pill>
                            </a></li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Recent customers')" flush>
                @if ($recent->isEmpty())
                    <x-ui.empty icon="customers">{{ __('No customers yet.') }}</x-ui.empty>
                @else
                    <ul class="m-list">
                        @foreach ($recent as $c)
                            <li><a href="{{ route('admin.customers.show', $c) }}" class="m-item">
                                <span class="m-avatar" aria-hidden="true">{{ strtoupper(mb_substr($c->displayName(), 0, 1)) }}</span>
                                <span class="m-text"><strong>{{ $c->displayName() }}</strong>
                                    <small>{{ $c->package?->name ?? '–' }} · {{ trans_choice(':count website|:count websites', $c->websites_count) }}</small></span>
                                <span @class(['m-dot', 'is-'.$c->status]) title="{{ __(ucfirst($c->status)) }}"></span>
                                <x-icon name="chevron" />
                            </a></li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</x-layouts.admin>
