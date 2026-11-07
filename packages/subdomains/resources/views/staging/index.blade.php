@php
    $statusLabel = ['copying' => __('Copying'), 'ready' => __('Ready'), 'failed' => __('Failed')];
    $statusPill = ['copying' => 'warn', 'ready' => 'ok', 'failed' => 'bad'];
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Staging for :domain', ['domain' => $website->domain])">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.websites.index') }}">{{ __('Websites') }}</a> / <a href="{{ route($area.'.websites.show', [$website, 'tab' => 'wordpress']) }}">{{ $website->domain }}</a> / {{ __('Staging') }}</div>
            <h1>{{ __('Staging for :domain', ['domain' => $website->domain]) }}</h1>
            <p class="sub">{{ __('Work on a copy of your WordPress website and put it live with one click when you are happy with it.') }}</p>
        </div>
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif

    <ol class="stg-flow">
        <li><span class="stg-num">1</span><span><strong>{{ __('Make a copy') }}</strong><span class="muted small">{{ __('Files and database on staging.:domain', ['domain' => $main->domain]) }}</span></span></li>
        <li><span class="stg-num">2</span><span><strong>{{ __('Work on it safely') }}</strong><span class="muted small">{{ __('No email goes out and search engines skip it') }}</span></span></li>
        <li><span class="stg-num">3</span><span><strong>{{ __('Push to live') }}</strong><span class="muted small">{{ __('A backup is made first; you can always go back') }}</span></span></li>
    </ol>

    <div class="stg-layout">
        <div class="stg-col">
            <x-ui.card :title="__('Staging copies')">
                @forelse ($stagings as $s)
                    <ul class="stg-list">
                        <li>
                            <div class="stg-grow"><a class="strong" href="{{ route($area.'.staging.show', $s) }}">{{ $s->staging_domain }}</a>
                                <div class="muted small">{{ $s->last_push_at ? __('Last pushed :time', ['time' => $s->last_push_at->diffForHumans()]) : __('Not pushed yet') }}</div></div>
                            <x-ui.pill :status="$statusPill[$s->status] ?? 'neutral'">{{ $statusLabel[$s->status] ?? $s->status }}</x-ui.pill>
                            <a class="btn btn-sm" href="{{ route($area.'.staging.show', $s) }}"><x-icon name="settings" />{{ __('Manage') }}</a>
                        </li>
                    </ul>
                @empty
                    <x-ui.empty icon="wordpress">{{ __('No staging copy yet.') }}</x-ui.empty>
                @endforelse
            </x-ui.card>
        </div>
        <div class="stg-col">
            <x-ui.card :title="__('New staging copy')">
                @if ($blocker)
                    <p class="small muted">{{ $blocker }}</p>
                @else
                    <form method="POST" action="{{ route($area.'.staging.store', $website) }}" class="form stg-form">
                        @csrf
                        <div class="field">
                            <label for="stg-label">{{ __('Address of the copy') }}</label>
                            <div class="sd-input"><input id="stg-label" class="input" name="label" value="{{ old('label', $stagings->isEmpty() ? 'staging' : '') }}" autocomplete="off" spellcheck="false" maxlength="63" required
                                pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?" @error('label') aria-invalid="true" @enderror><span class="sd-suffix">.{{ $main->domain }}</span></div>
                            @error('label')<span class="error">{{ $message }}</span>@enderror
                        </div>
                        @if (count($paths) > 1)
                            <x-ui.field name="path" type="select" :label="__('WordPress install')">
                                @foreach ($paths as $p)<option value="{{ $p }}" @selected(old('path') === $p)>{{ $website->domain }}/{{ $p }}</option>@endforeach
                            </x-ui.field>
                        @else
                            <input type="hidden" name="path" value="{{ $paths[0] }}">
                        @endif
                        <ul class="stg-hint small muted">
                            <li>{{ __('The copy is a subdomain of this subscription: no extra package needed.') }}</li>
                            <li>{{ __('Its disk space counts towards :domain.', ['domain' => $main->domain]) }}</li>
                        </ul>
                        <div><x-ui.button variant="primary" icon="plus">{{ __('Make staging copy') }}</x-ui.button></div>
                    </form>
                @endif
            </x-ui.card>
        </div>
    </div>
    @include('subdomains::staging.partials.styles')
</x-dynamic-component>
