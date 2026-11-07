{{-- Plugin set choice on the install form of WordPress / WooCommerce. Needs $pluginSets, $pluginSetDefaults, $selected, $area. --}}
@php
    $psSets = $pluginSets->mapWithKeys(fn ($s) => [$s->id => [
        'name' => $s->name, 'description' => $s->description,
        'plugins' => collect($s->plugins)->map(fn ($p) => \Rhis\Apps\PluginSets\PluginSet::label($p))->all(),
        'themes' => collect($s->themes)->map(fn ($t) => \Rhis\Apps\PluginSets\PluginSet::label($t))->all(),
    ]]);
    $psInitial = old('plugin_set', $pluginSetDefaults[old('website', $selected)] ?? '');
@endphp
<link rel="stylesheet" href="{{ asset('assets/plugin-sets.css') }}?v={{ @filemtime(public_path('assets/plugin-sets.css')) }}">
<x-ui.card :title="__('Plugins and themes')">
    <div class="ps-pick" x-data="{ set: @js((string) $psInitial), sets: @js($psSets), defaults: @js(array_map(fn ($v) => $v === null ? '' : (string) $v, $pluginSetDefaults)) }"
         x-init="$el.closest('form').querySelector('[name=website]')?.addEventListener('change', e => { set = defaults[e.target.value] ?? ''; })">
        <div class="field">
            <label for="f-plugin_set">{{ __('Plugin set') }}</label>
            <select id="f-plugin_set" name="plugin_set" class="select" x-model="set" @error('plugin_set') aria-invalid="true" @enderror>
                <option value="">{{ __('None: a clean WordPress') }}</option>
                @foreach ($pluginSets as $s)
                    <option value="{{ $s->id }}" @selected((string) $psInitial === (string) $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
            <span class="help">{{ __('The plugins and themes of the set are installed right after WordPress. The package of the website can preselect one.') }}</span>
            @error('plugin_set')<span class="error">{{ $message }}</span>@enderror
        </div>
        <template x-if="sets[set]">
            <div class="ps-preview">
                <p class="small" x-show="sets[set].description" x-text="sets[set].description"></p>
                <p class="small" x-show="sets[set].themes.length"><strong>{{ __('Themes') }}:</strong> <span x-text="sets[set].themes.join(', ')"></span></p>
                <p class="small" x-show="sets[set].plugins.length"><strong>{{ __('Plugins') }}:</strong> <span x-text="sets[set].plugins.join(', ')"></span></p>
            </div>
        </template>
        @if ($area === 'admin')
            <p class="small muted"><a href="{{ route('admin.apps.sets.index') }}">{{ __('Manage plugin sets') }}</a></p>
        @endif
    </div>
</x-ui.card>
