@php
    $searchIndex = collect($groups)->flatMap(fn ($g, $key) => collect($g['items'])->map(fn ($i) => mb_strtolower(__($i['label']).' '.__($i['description'] ?? '').' '.__($g['label']))))->all();
@endphp
<x-layouts.admin :title="__('Tools & settings')">
    <div class="tools-page" x-data="{ q: '', all: @js(array_values($searchIndex)), match(text) { return this.q.trim() === '' || text.includes(this.q.trim().toLowerCase()); }, get none() { return ! this.all.some((t) => this.match(t)); } }">
        <div class="page-head">
            <div>
                <h1>{{ __('Tools & settings') }}</h1>
                <p class="sub">{{ __('Every setting of the server and the panel in one place.') }}</p>
            </div>
            <div class="actions">
                <label class="search tools-search">
                    <x-icon name="search" />
                    <input type="search" x-model="q" placeholder="{{ __('Find a setting…') }}" aria-label="{{ __('Find a setting…') }}" autocomplete="off">
                </label>
            </div>
        </div>

        <section class="tools-grid">
            @foreach ($groups as $key => $group)
                @php($texts = collect($group['items'])->map(fn ($i) => mb_strtolower(__($i['label']).' '.__($i['description'] ?? '').' '.__($group['label']))))
                <article class="tools-group" id="tools-{{ $key }}" x-show="{{ $texts->map(fn ($t) => 'match('.\Illuminate\Support\Js::from($t).')')->implode(' || ') }}">
                    <header class="tools-group-head">
                        <span class="tools-group-icon is-{{ $key }}"><x-icon :name="$group['icon']" /></span>
                        <div>
                            <h2>{{ __($group['label']) }}</h2>
                            <p class="muted small">{{ __($group['intro']) }}</p>
                        </div>
                    </header>
                    <ul class="tools-links">
                        @foreach ($group['items'] as $i => $item)
                            <li x-show="match({{ \Illuminate\Support\Js::from($texts[$i]) }})">
                                <a href="{{ route($item['route'], $item['parameters']) }}">
                                    <span class="tools-link-text">
                                        <strong>{{ __($item['label']) }}</strong>
                                        @if ($item['description'])<span>{{ __($item['description']) }}</span>@endif
                                    </span>
                                    <x-icon name="chevron" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </section>
        <p class="muted tools-empty" x-show="none" x-cloak>{{ __('No setting matches your search.') }}</p>
    </div>
</x-layouts.admin>
