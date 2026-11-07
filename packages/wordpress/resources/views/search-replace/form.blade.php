<x-dynamic-component :component="'layouts.'.$area" :title="__('Search & replace')">
    @include('wordpress::search-replace.head', ['title' => __('Search & replace'), 'sub' => $install->label(), 'step' => 1])

    <x-ui.card>
        <p class="muted small" style="max-width:72ch">{{ __('Replaces text in every table of this WordPress: posts, pages, widgets, page builder data and plugin settings. Serialized data stays intact, so nothing breaks because of a changed text length. Nothing changes until you have seen the preview and confirmed it, and a snapshot is made first.') }}</p>
        <form method="POST" action="{{ route($area.'.wordpress.sr.preview', $install) }}" class="form">
            @csrf
            <div class="form-grid">
                <x-ui.field name="search" :label="__('Search for')" :value="$from['search'] ?? ''" required minlength="3" maxlength="1000" autocomplete="off" spellcheck="false"
                    :help="__('At least 3 characters, case sensitive.')" />
                <x-ui.field name="replace" :label="__('Replace with')" :value="$from['replace'] ?? ''" maxlength="1000" autocomplete="off" spellcheck="false"
                    :help="__('Leave empty to remove the text.')" />
            </div>
            <details @if (($from['tables'] ?? 'wordpress') === 'all' || ($from['skip_guid'] ?? true) === false) open @endif>
                <summary class="small">{{ __('Advanced') }}</summary>
                <div class="form" style="margin-top:10px">
                    <div class="seg" role="radiogroup" aria-label="{{ __('Tables') }}">
                        <label><input type="radio" name="tables" value="wordpress" @checked(($from['tables'] ?? 'wordpress') === 'wordpress')><span>{{ __('WordPress tables (recommended)') }}</span></label>
                        <label><input type="radio" name="tables" value="all" @checked(($from['tables'] ?? '') === 'all')><span>{{ __('All tables in the database') }}</span></label>
                    </div>
                    <input type="hidden" name="skip_guid" value="0">
                    <label class="check"><input type="checkbox" name="skip_guid" value="1" @checked($from['skip_guid'] ?? true)><span>{{ __('Leave post GUIDs alone (recommended: feed readers use them as a fixed id)') }}</span></label>
                </div>
            </details>
            <div class="actions"><x-ui.button variant="primary" icon="search">{{ __('Show preview') }}</x-ui.button>
                <span class="small muted">{{ __('Want to move the site to another domain? Use') }} <a href="{{ route($area.'.wordpress.sr.domain', $install) }}">{{ __('Change domain') }}</a>.</span></div>
        </form>
    </x-ui.card>

    @include('wordpress::search-replace.history')
</x-dynamic-component>
