@php
    $editing = $set->exists;
    $rows = fn (string $key) => collect(old($key, $set->{$key} ?? []))->map(fn ($r) => [
        'source' => (string) ($r['source'] ?? ''), 'activate' => (bool) ($r['activate'] ?? false),
        'name' => (string) ($r['name'] ?? ''),
    ])->values()->all();
    $plugins = $rows('plugins');
    $themes = $rows('themes');
    $activeTheme = old('theme_active', collect($themes)->search(fn ($t) => $t['activate']));
    $activeTheme = $activeTheme === false || $activeTheme === null ? -1 : (int) $activeTheme;
    $chosen = collect(old('packages', $set->packages?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id)->all();
@endphp
<x-layouts.admin :title="$editing ? $set->name : __('New plugin set')">
    <link rel="stylesheet" href="{{ asset('assets/plugin-sets.css') }}?v={{ @filemtime(public_path('assets/plugin-sets.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.apps.index') }}">{{ __('App installer') }}</a> / <a href="{{ route('admin.apps.sets.index') }}">{{ __('Plugin sets') }}</a> / {{ $editing ? $set->name : __('New') }}</div>
            <h1>{{ $editing ? __('Edit plugin set') : __('New plugin set') }}</h1>
            <p class="sub">{{ __('Plugins and themes come from wordpress.org (type the slug from the address, for example wordpress.org/plugins/wordfence → wordfence) or from an https link to a .zip file, for paid plugins you host yourself.') }}</p>
        </div>
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @if (session('warning'))<div class="alert alert-warn" role="status"><x-icon name="info" />{{ session('warning') }}</div>@endif

    <form method="POST" action="{{ $editing ? route('admin.apps.sets.update', $set) : route('admin.apps.sets.store') }}" class="form"
          x-data="{ plugins: @js($plugins), themes: @js($themes), active: @js($activeTheme),
                    add(list) { list.push({ source: '', activate: list === this.plugins, name: '' }); this.$nextTick(() => { const i = this.$el.querySelectorAll(list === this.plugins ? '[data-plugin]' : '[data-theme]'); i.length && i[i.length - 1].focus(); }); },
                    drop(list, i) { list.splice(i, 1); if (list === this.themes) { if (this.active === i) this.active = -1; else if (this.active > i) this.active--; } } }">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-ui.card :title="__('Name')">
            <div class="form-grid">
                <x-ui.field name="name" :label="__('Name')" :value="$set->name" required maxlength="80" :help="__('For example RHIS standard or Webshop basics.')" />
                <x-ui.field name="description" :label="__('Description')" :value="$set->description" maxlength="500" :help="__('Shown when someone picks a set.')" />
            </div>
            <label class="check"><input type="checkbox" name="customer_visible" value="1" @checked(old('customer_visible', $set->customer_visible))><span>{{ __('Customers can choose this set') }}<small>{{ __('Otherwise only admins can pick it. The default set of a package is always used for that package.') }}</small></span></label>
        </x-ui.card>

        <x-ui.card :title="__('Plugins')">
            <p class="small muted ps-hint">{{ __('Tick "Switch on" to activate the plugin right away. Without it the plugin is only installed and the site owner switches it on later.') }}</p>
            @error('plugins')<p class="error">{{ $message }}</p>@enderror
            <div class="ps-rows">
                <template x-for="(row, i) in plugins" :key="i">
                    <div class="ps-row">
                        <input class="input mono" data-plugin :name="`plugins[${i}][source]`" x-model.trim="row.source" maxlength="500" spellcheck="false" autocomplete="off"
                               placeholder="{{ __('slug or https://…/plugin.zip') }}" :aria-label="@js(__('Plugin')) + ' ' + (i + 1)">
                        <label class="check"><input type="checkbox" :name="`plugins[${i}][activate]`" value="1" x-model="row.activate"><span>{{ __('Switch on') }}</span></label>
                        <button type="button" class="icon-link is-danger" @click="drop(plugins, i)" title="{{ __('Remove') }}"><x-icon name="trash" /><span class="sr-only">{{ __('Remove') }}</span></button>
                        <span class="help" x-show="row.name" x-text="row.name"></span>
                    </div>
                </template>
                @foreach ($errors->get('plugins.*') as $key => $messages)<p class="error">{{ __('Plugin :n', ['n' => (int) explode('.', $key)[1] + 1]) }}: {{ $messages[0] }}</p>@endforeach
                <button type="button" class="btn btn-sm ps-add" @click="add(plugins)" x-show="plugins.length < {{ \Rhis\Apps\PluginSets\PluginSet::MAX_PLUGINS }}"><x-icon name="plus" />{{ __('Add plugin') }}</button>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Themes')">
            <p class="small muted ps-hint">{{ __('Choose at most one theme to use. A child theme installs its parent theme by itself when it is on wordpress.org.') }}</p>
            <input type="hidden" name="theme_active" :value="active">
            <div class="ps-rows">
                <template x-for="(row, i) in themes" :key="i">
                    <div class="ps-row">
                        <input class="input mono" data-theme :name="`themes[${i}][source]`" x-model.trim="row.source" maxlength="500" spellcheck="false" autocomplete="off"
                               placeholder="{{ __('slug or https://…/theme.zip') }}" :aria-label="@js(__('Theme')) + ' ' + (i + 1)">
                        <label class="check"><input type="radio" name="theme_pick" :checked="active === i" @change="active = i"><span>{{ __('Use this theme') }}</span></label>
                        <button type="button" class="icon-link is-danger" @click="drop(themes, i)" title="{{ __('Remove') }}"><x-icon name="trash" /><span class="sr-only">{{ __('Remove') }}</span></button>
                        <span class="help" x-show="row.name" x-text="row.name"></span>
                    </div>
                </template>
                @foreach ($errors->get('themes.*') as $key => $messages)<p class="error">{{ __('Theme :n', ['n' => (int) explode('.', $key)[1] + 1]) }}: {{ $messages[0] }}</p>@endforeach
                <div class="actions">
                    <button type="button" class="btn btn-sm ps-add" @click="add(themes)" x-show="themes.length < {{ \Rhis\Apps\PluginSets\PluginSet::MAX_THEMES }}"><x-icon name="plus" />{{ __('Add theme') }}</button>
                    <button type="button" class="btn btn-sm" x-show="active !== -1" @click="active = -1">{{ __('Keep the default WordPress theme') }}</button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Clean up')">
            <label class="check"><input type="checkbox" name="remove_default_content" value="1" @checked(old('remove_default_content', $set->remove_default_content))><span>{{ __('Remove the example post and page') }}<small>{{ __('"Hello world!" with its comment and the "Sample Page". The privacy policy draft stays.') }}</small></span></label>
            <label class="check"><input type="checkbox" name="remove_inactive_themes" value="1" @checked(old('remove_inactive_themes', $set->remove_inactive_themes))><span>{{ __('Remove themes that are not used') }}<small>{{ __('The standard Twenty… themes that are not active. Fewer themes means fewer updates and less to attack.') }}</small></span></label>
        </x-ui.card>

        <x-ui.card :title="__('Default for packages')">
            <p class="small muted ps-hint">{{ __('New WordPress sites of these packages start with this set selected. Customers and admins can still choose another set or none.') }}</p>
            @if ($packages->isEmpty())
                <p class="muted">{{ __('No packages yet.') }}</p>
            @else
                <div class="ps-packages">
                    @foreach ($packages as $package)
                        <label class="check"><input type="checkbox" name="packages[]" value="{{ $package->id }}" @checked(in_array($package->id, $chosen, true))>
                            <span>{{ $package->name }}@if (isset($taken[$package->id]))<small>{{ __('Now uses :set', ['set' => $taken[$package->id]]) }}</small>@endif</span></label>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <div class="actions">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.apps.sets.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
    @if ($editing)
        <form method="POST" action="{{ route('admin.apps.sets.destroy', $set) }}" data-confirm="{{ __('Delete plugin set :name? Sites that were installed with it keep their plugins.', ['name' => $set->name]) }}">@csrf @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit"><x-icon name="trash" />{{ __('Delete plugin set') }}</button></form>
    @endif
</x-layouts.admin>
