{{-- Plugins & themes of one WordPress install. Shared by the admin and the client panel ($area). --}}
@php
    $r = fn (string $name, array $extra = []) => route($area.'.wordpress.extensions.'.$name, ['install' => $install] + $extra);
    $tabs = ['plugins' => __('Plugins'), 'themes' => __('Themes'), 'add' => __('Add new')];
    $counts = ['plugins' => $plugins->count(), 'themes' => $themes->count()];
    $pluginUpdates = $plugins->where('update', true)->count();
    $themeUpdates = $themes->where('update', true)->count();
    $hue = fn (string $slug) => ['#1E2D50', '#2B1E2F', '#1D3A35', '#3A2A1D', '#2A2148', '#123A4A'][crc32($slug) % 6];
    $initials = fn (string $title) => mb_strtoupper(collect(preg_split('/[\s\-_]+/u', trim($title)) ?: [])->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: 'WP';
    $installed = ['plugin' => $plugins->pluck('name')->all(), 'theme' => $themes->pluck('name')->all()];
    $highlight = session('highlight');
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Plugins & themes').' · '.$install->label()">
    <link rel="stylesheet" href="{{ asset('assets/site-overview.css') }}?v={{ @filemtime(public_path('assets/site-overview.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/wp-extensions.css') }}?v={{ @filemtime(public_path('assets/wp-extensions.css')) }}">

    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / <a href="{{ route($area.'.wordpress.show', $install) }}">{{ $install->label() }}</a> / {{ __('Plugins & themes') }}</div>
            <h1>{{ __('Plugins & themes') }}</h1>
            <p class="sub">{{ __('Switch plugins and themes on or off, remove what you no longer use and add new ones from WordPress.org.') }}</p>
        </div>
        <div class="actions">
            <form method="POST" action="{{ route($area.'.wordpress.refresh', $install) }}">@csrf<x-ui.button icon="refresh">{{ __('Check for updates') }}</x-ui.button></form>
            <form method="POST" action="{{ route($area.'.wordpress.login', $install) }}" target="_blank">@csrf<x-ui.button variant="primary" icon="external">{{ __('Log in to WP admin') }}</x-ui.button></form>
        </div>
    </div>

    @if (session('wp_rolled_back'))
        <div class="alert alert-warn" role="alert"><x-icon name="shield" />
            <div><strong>{{ __('Your website stopped working, so we undid the change') }}</strong>
                <p class="small">{{ __('After activating, the website gave an error (HTTP :code). The previous situation is back and the website works again. Contact the maker of the plugin or theme, or try another one.', ['code' => session('wp_rolled_back')['health']['after'] ?? '5xx']) }}</p></div>
        </div>
    @endif
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if ($errors->has('items') || $errors->has('slugs'))
        <div class="alert alert-bad" role="alert"><x-icon name="info" />
            <div><strong>{{ __('Not everything worked') }}</strong>
                <ul class="small">@foreach ([...$errors->get('items'), ...$errors->get('slugs')] as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        </div>
    @endif
    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach

    <section class="card wpx-main">
        <nav class="site-tabs" aria-label="{{ __('Sections') }}">
            @foreach ($tabs as $key => $label)
                <a href="{{ $r('index', ['tab' => $key]) }}" @class(['is-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>
                    {{ $label }}@isset($counts[$key]) <span class="wpx-count">{{ $counts[$key] }}</span>@endisset
                    @if ($key === 'plugins' && $pluginUpdates) <span class="wpx-count wpx-count-warn" title="{{ __('Updates available') }}">{{ $pluginUpdates }} ↑</span>@endif
                    @if ($key === 'themes' && $themeUpdates) <span class="wpx-count wpx-count-warn" title="{{ __('Updates available') }}">{{ $themeUpdates }} ↑</span>@endif
                </a>
            @endforeach
        </nav>

        <div class="wpx-body">
        @if ($tab === 'plugins')
            <form method="POST" action="{{ $r('apply') }}" x-data="{ q: '', only: 'all', sel: [], show(active, upd, text) { return (this.only === 'all' || (this.only === 'active' && active) || (this.only === 'inactive' && ! active) || (this.only === 'update' && upd)) && text.includes(this.q.toLowerCase()) } }">
                @csrf<input type="hidden" name="kind" value="plugin">
                <div class="wpx-toolbar">
                    <label class="search"><x-icon name="search" /><input type="search" x-model="q" placeholder="{{ __('Filter plugins') }}" aria-label="{{ __('Filter plugins') }}"></label>
                    <div class="seg" role="radiogroup" aria-label="{{ __('Show') }}">
                        @foreach (['all' => __('All'), 'active' => __('Active'), 'inactive' => __('Inactive'), 'update' => __('Updates')] as $k => $l)
                            <label><input type="radio" value="{{ $k }}" x-model="only"><span>{{ $l }}</span></label>
                        @endforeach
                    </div>
                    <div class="wpx-bulk" x-show="sel.length" x-cloak>
                        <span class="small strong" x-text="@js(__(':n selected')).replace(':n', sel.length)"></span>
                        <select name="op" class="input" aria-label="{{ __('Action') }}">
                            <option value="activate">{{ __('Activate') }}</option>
                            <option value="deactivate">{{ __('Deactivate') }}</option>
                            <option value="auto_update_on">{{ __('Automatic updates on') }}</option>
                            <option value="auto_update_off">{{ __('Automatic updates off') }}</option>
                            <option value="delete">{{ __('Delete') }}</option>
                        </select>
                        <button type="submit" class="btn btn-dark btn-sm" onclick="return this.form.op.value !== 'delete' || confirm(@js(__('Delete the selected plugins? Their files are removed; settings in the database stay.')))">{{ __('Apply') }}</button>
                    </div>
                </div>

                @if ($plugins->isEmpty())
                    <x-ui.empty icon="apps">{{ __('No plugins installed yet.') }}<x-slot:action><a class="btn btn-sm" href="{{ $r('index', ['tab' => 'add']) }}">{{ __('Add a plugin') }}</a></x-slot:action></x-ui.empty>
                @else
                <x-ui.table>
                    <x-slot:head>
                        <th style="width:36px"><input type="checkbox" aria-label="{{ __('Select all') }}" x-on:change="sel = $event.target.checked ? @js($plugins->pluck('name')) : []"></th>
                        <th>{{ __('Plugin') }}</th><th>{{ __('Version') }}</th><th>{{ __('Automatic updates') }}</th><th class="num"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </x-slot:head>
                    @foreach ($plugins as $p)
                        @php $active = in_array($p['status'], ['active', 'active-network'], true); @endphp
                        <tr x-show="show({{ $active ? 'true' : 'false' }}, {{ $p['update'] ? 'true' : 'false' }}, @js(mb_strtolower($p['title'].' '.$p['name'])))"
                            @class(['wpx-row', 'is-inactive' => ! $active, 'is-new' => $highlight === $p['name']])>
                            <td><input type="checkbox" name="slugs[]" value="{{ $p['name'] }}" x-model="sel" aria-label="{{ __('Select :name', ['name' => $p['title']]) }}"></td>
                            <td>
                                <div class="wpx-name">
                                    <span class="wpx-tile" style="--tile: {{ $hue($p['name']) }}" aria-hidden="true">{{ $initials($p['title'] ?: $p['name']) }}</span>
                                    <div><span class="strong">{{ $p['title'] ?: $p['name'] }}</span>
                                        <div class="small muted"><span class="mono">{{ $p['name'] }}</span> · <x-ui.pill :status="$active ? 'ok' : 'neutral'">{{ $active ? __('Active') : __('Inactive') }}</x-ui.pill></div></div>
                                </div>
                            </td>
                            <td><span class="mono">{{ $p['version'] ?: '–' }}</span>
                                @if ($p['update'])<div><x-ui.pill status="warn">{{ __(':version available', ['version' => $p['update_version']]) }}</x-ui.pill></div>@endif</td>
                            <td>
                                <button type="submit" name="row" value="{{ ($p['auto_update'] ? 'auto_update_off' : 'auto_update_on').'|'.$p['name'] }}" class="wpx-switch" role="switch" aria-checked="{{ $p['auto_update'] ? 'true' : 'false' }}"
                                    aria-label="{{ __('Automatic updates for :name', ['name' => $p['title']]) }}"><span class="wpx-knob"></span><span class="small">{{ $p['auto_update'] ? __('On') : __('Off') }}</span></button>
                            </td>
                            <td class="num">
                                <div class="actions" style="justify-content:flex-end">
                                    @if ($active)
                                        <button type="submit" name="row" value="deactivate|{{ $p['name'] }}" class="btn btn-sm">{{ __('Deactivate') }}</button>
                                    @else
                                        <button type="submit" name="row" value="activate|{{ $p['name'] }}" class="btn btn-sm btn-dark">{{ __('Activate') }}</button>
                                        <button type="submit" name="row" value="delete|{{ $p['name'] }}" class="btn btn-sm btn-ghost btn-danger" title="{{ __('Delete') }}"
                                            onclick="return confirm(@js(__('Delete :name? The files are removed; its settings in the database stay.', ['name' => $p['title']])))"><x-icon name="trash" /><span class="sr-only">{{ __('Delete') }}</span></button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
                @endif
            </form>
            <p class="small muted wpx-note"><x-icon name="info" /><span>
                {{ __('Automatic updates are done by WordPress itself, usually twice a day. Updates you start from this panel first make a restore point and are put back automatically when the website breaks.') }}
                <a href="{{ route($area.'.wordpress.show', $install) }}">{{ __('Update now') }}</a></span></p>

        @elseif ($tab === 'themes')
            <form method="POST" action="{{ $r('apply') }}" class="wpx-themes">
                @csrf<input type="hidden" name="kind" value="theme">
                @forelse ($themes as $t)
                    @php $isActive = $t['status'] === 'active'; $isParent = $t['status'] === 'parent'; @endphp
                    <article @class(['wpx-theme', 'is-active' => $isActive, 'is-new' => $highlight === $t['name']])>
                        <div class="wpx-shot" style="--tile: {{ $hue($t['name']) }}" aria-hidden="true"><span>{{ $initials($t['title'] ?: $t['name']) }}</span></div>
                        <div class="wpx-theme-body">
                            <header>
                                <div><h3>{{ $t['title'] ?: $t['name'] }}</h3><span class="small muted mono">{{ $t['name'] }} · {{ $t['version'] ?: '–' }}</span></div>
                                @if ($isActive)<x-ui.pill status="ok">{{ __('Active') }}</x-ui.pill>@elseif ($isParent)<x-ui.pill status="info">{{ __('Parent theme') }}</x-ui.pill>@endif
                            </header>
                            @if ($t['update'])<p><x-ui.pill status="warn">{{ __(':version available', ['version' => $t['update_version']]) }}</x-ui.pill></p>@endif
                            <footer>
                                <button type="submit" name="row" value="{{ ($t['auto_update'] ? 'auto_update_off' : 'auto_update_on').'|'.$t['name'] }}" class="wpx-switch" role="switch" aria-checked="{{ $t['auto_update'] ? 'true' : 'false' }}"
                                    aria-label="{{ __('Automatic updates for :name', ['name' => $t['title']]) }}"><span class="wpx-knob"></span><span class="small">{{ __('Auto-update') }}</span></button>
                                <div class="actions">
                                    @unless ($isActive)
                                        <button type="submit" name="row" value="activate|{{ $t['name'] }}" class="btn btn-sm btn-dark"
                                            onclick="return confirm(@js(__('Switch the website to :name? Visitors see the new theme straight away.', ['name' => $t['title']])))">{{ __('Activate') }}</button>
                                        @unless ($isParent)
                                            <button type="submit" name="row" value="delete|{{ $t['name'] }}" class="btn btn-sm btn-ghost btn-danger" title="{{ __('Delete') }}"
                                                onclick="return confirm(@js(__('Delete the theme :name?', ['name' => $t['title']])))"><x-icon name="trash" /><span class="sr-only">{{ __('Delete') }}</span></button>
                                        @endunless
                                    @endunless
                                </div>
                            </footer>
                        </div>
                    </article>
                @empty
                    <x-ui.empty icon="palette">{{ __('No themes found.') }}</x-ui.empty>
                @endforelse
            </form>

        @else
            <div class="wpx-add">
                <form method="GET" action="{{ $r('index') }}" class="wpx-toolbar">
                    <input type="hidden" name="tab" value="add">
                    <div class="seg" role="radiogroup" aria-label="{{ __('Search for') }}">
                        <label><input type="radio" name="kind" value="plugin" @checked($kind === 'plugin') onchange="this.form.submit()"><span>{{ __('Plugins') }}</span></label>
                        <label><input type="radio" name="kind" value="theme" @checked($kind === 'theme') onchange="this.form.submit()"><span>{{ __('Themes') }}</span></label>
                    </div>
                    <label class="search wpx-search"><x-icon name="search" /><input type="search" name="q" value="{{ $q }}" minlength="2" maxlength="100" required
                        placeholder="{{ $kind === 'plugin' ? __('Search WordPress.org plugins') : __('Search WordPress.org themes') }}" aria-label="{{ __('Search WordPress.org') }}"></label>
                    <button type="submit" class="btn btn-dark">{{ __('Search') }}</button>
                </form>
                <div class="wpx-chips">
                    <span class="small muted">{{ __('Popular:') }}</span>
                    @foreach (config('wordpress-extensions.suggestions.'.$kind, []) as $s)
                        <a class="wpx-chip" href="{{ $r('index', ['tab' => 'add', 'kind' => $kind, 'q' => $s]) }}">{{ __($s) }}</a>
                    @endforeach
                </div>

                @if ($search === null)
                    <x-ui.empty icon="search">{{ __('Search the free plugins and themes on WordPress.org. Everything is installed straight from WordPress.org.') }}</x-ui.empty>
                @elseif (empty($search['results']))
                    <x-ui.empty icon="search">{{ __('Nothing found for “:q”.', ['q' => $q]) }}</x-ui.empty>
                @else
                    <p class="small muted">{{ trans_choice(':count result|:count results', $search['total']) }}</p>
                    <div class="wpx-results">
                        @foreach ($search['results'] as $res)
                            @php $has = in_array($res['slug'], $installed[$kind], true); @endphp
                            <article class="wpx-result">
                                <span class="wpx-tile wpx-tile-lg" style="--tile: {{ $hue($res['slug']) }}" aria-hidden="true">{{ $initials($res['name']) }}</span>
                                <div class="wpx-result-body">
                                    <h3>{{ html_entity_decode($res['name']) }}</h3>
                                    <p class="small muted">{{ __('by :author', ['author' => html_entity_decode($res['author'] ?: '?')]) }}</p>
                                    <p class="small wpx-desc">{{ html_entity_decode($res['description']) }}</p>
                                    <dl class="wpx-meta small">
                                        <div><dt class="sr-only">{{ __('Rating') }}</dt><dd><span class="wpx-stars" style="--rating: {{ (int) $res['rating'] }}" aria-hidden="true">★★★★★</span> <span class="muted">({{ number_format($res['num_ratings'], 0, ',', '.') }})</span><span class="sr-only">{{ round($res['rating'] / 20, 1) }} / 5</span></dd></div>
                                        @if ($res['active_installs'])<div><dt class="sr-only">{{ __('Active installations') }}</dt><dd>{{ __(':n+ active installations', ['n' => $res['active_installs'] >= 1000000 ? number_format($res['active_installs'] / 1000000, 0).' '.__('million') : number_format($res['active_installs'], 0, ',', '.')]) }}</dd></div>@endif
                                        @if ($res['tested'])<div><dt class="sr-only">{{ __('Tested up to') }}</dt><dd @class(['is-warn' => $install->version && version_compare(implode('.', array_slice(explode('.', $install->version), 0, 2)), $res['tested'], '>')])>{{ __('Tested up to WordPress :v', ['v' => $res['tested']]) }}</dd></div>@endif
                                    </dl>
                                </div>
                                <div class="wpx-result-actions">
                                    @if ($has)
                                        <x-ui.pill status="ok">{{ __('Installed') }}</x-ui.pill>
                                    @else
                                        <form method="POST" action="{{ $r('install') }}">
                                            @csrf<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="slug" value="{{ $res['slug'] }}"><input type="hidden" name="q" value="{{ $q }}">
                                            <button type="submit" name="activate" value="1" class="btn btn-sm btn-dark" @if ($kind === 'theme') onclick="return confirm(@js(__('Install and switch the website to :name?', ['name' => html_entity_decode($res['name'])])))" @endif>{{ __('Install & activate') }}</button>
                                            <button type="submit" name="activate" value="0" class="btn btn-sm">{{ __('Install') }}</button>
                                        </form>
                                    @endif
                                    <a class="small" href="https://wordpress.org/{{ $kind }}s/{{ $res['slug'] }}/" target="_blank" rel="noopener">{{ __('More details') }}</a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    @if (($search['pages'] ?? 1) > 1)
                        <nav class="actions wpx-pages" aria-label="{{ __('Pages') }}">
                            @if ($page > 1)<a class="btn btn-sm" href="{{ $r('index', ['tab' => 'add', 'kind' => $kind, 'q' => $q, 'page' => $page - 1]) }}">{{ __('Previous') }}</a>@endif
                            <span class="small muted">{{ __('Page :n of :total', ['n' => $page, 'total' => $search['pages']]) }}</span>
                            @if ($page < $search['pages'])<a class="btn btn-sm" href="{{ $r('index', ['tab' => 'add', 'kind' => $kind, 'q' => $q, 'page' => $page + 1]) }}">{{ __('Next') }}</a>@endif
                        </nav>
                    @endif
                @endif
            </div>
        @endif
        </div>
    </section>
</x-dynamic-component>
