<x-layouts.admin :title="__('WordPress plugin sets')">
    <link rel="stylesheet" href="{{ asset('assets/plugin-sets.css') }}?v={{ @filemtime(public_path('assets/plugin-sets.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.apps.index') }}">{{ __('App installer') }}</a> / {{ __('Plugin sets') }}</div>
            <h1>{{ __('WordPress plugin sets') }}</h1>
            <p class="sub">{{ __('Fixed lists of plugins and themes that are put on a new WordPress or WooCommerce site right after it is installed. Pick a set in the app installer, or give a package its own default set.') }}</p>
        </div>
        <div class="actions">
            <x-ui.button variant="primary" icon="plus" :href="route('admin.apps.sets.create')">{{ __('New plugin set') }}</x-ui.button>
        </div>
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif

    @if ($sets->isEmpty())
        <x-ui.card>
            <x-ui.empty icon="wordpress">
                {{ __('No plugin sets yet. Make one, for example your standard with a security plugin, a backup plugin and your favourite theme.') }}
                <div style="margin-top:12px"><x-ui.button variant="primary" icon="plus" :href="route('admin.apps.sets.create')">{{ __('New plugin set') }}</x-ui.button></div>
            </x-ui.empty>
        </x-ui.card>
    @else
        <section class="ps-grid" aria-label="{{ __('Plugin sets') }}">
            @foreach ($sets as $set)
                <article class="card ps-card">
                    <header>
                        <h2><a href="{{ route('admin.apps.sets.edit', $set) }}">{{ $set->name }}</a></h2>
                        @if ($set->customer_visible)
                            <x-ui.pill status="ok">{{ __('Customers can choose it') }}</x-ui.pill>
                        @else
                            <x-ui.pill status="neutral">{{ __('Admins only') }}</x-ui.pill>
                        @endif
                    </header>
                    @if ($set->description)<p class="small muted">{{ $set->description }}</p>@endif
                    @if ($set->themes)
                        <div>
                            <p class="small muted">{{ __('Themes') }}</p>
                            <ul class="ps-chips">@foreach ($set->themes as $t)<li @class(['ps-on' => $t['activate'] ?? false]) title="{{ $t['source'] }}">{{ \Rhis\Apps\PluginSets\PluginSet::label($t) }}</li>@endforeach</ul>
                        </div>
                    @endif
                    @if ($set->plugins)
                        <div>
                            <p class="small muted">{{ trans_choice(':count plugin|:count plugins', count($set->plugins)) }}</p>
                            <ul class="ps-chips">@foreach ($set->plugins as $p)<li @class(['ps-on' => $p['activate'] ?? false]) title="{{ $p['source'] }}">{{ \Rhis\Apps\PluginSets\PluginSet::label($p) }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <div class="ps-meta">
                        <span><x-icon name="package" /> {{ $set->packages->isEmpty() ? __('Not a package default') : __('Default for :packages', ['packages' => $set->packages->pluck('name')->implode(', ')]) }}</span>
                        <span><x-icon name="apps" /> {{ trans_choice('Used for :count site|Used for :count sites', $used[$set->id] ?? 0) }}</span>
                    </div>
                    <footer>
                        <x-ui.button size="sm" icon="edit" :href="route('admin.apps.sets.edit', $set)">{{ __('Edit') }}</x-ui.button>
                        <x-ui.button size="sm" icon="wordpress" :href="route('admin.apps.create', 'wordpress')">{{ __('Install WordPress') }}</x-ui.button>
                    </footer>
                </article>
            @endforeach
        </section>
        <p class="small muted">{{ __('A green dot means the plugin or theme is switched on right away; the others are only installed.') }}</p>
    @endif
</x-layouts.admin>
