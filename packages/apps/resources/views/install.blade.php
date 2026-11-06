<x-dynamic-component :component="'layouts.'.$area" :title="__('Install :app', ['app' => $meta['name']])">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.apps.index') }}">{{ $area === 'admin' ? __('App installer') : __('Apps') }}</a> / {{ $meta['name'] }}</div>
            <div class="actions"><span class="app-logo" style="--app-color: {{ $meta['color'] }}" aria-hidden="true">{{ $meta['monogram'] }}</span><h1>{{ __('Install :app', ['app' => $meta['name']]) }}</h1></div>
            <p class="sub">{{ __($meta['description']) }}</p>
        </div>
    </div>
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror

    @if ($websites->isEmpty())
        <x-ui.card><x-ui.empty icon="globe">{{ __('There is no active website to install into.') }}</x-ui.empty></x-ui.card>
    @else
    <form method="POST" action="{{ route($area.'.apps.store', $app) }}" class="form"
          x-data="{ pw: @js(old('admin_password', '')), show: true,
                    gen() { const c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'; const a = new Uint32Array(20); crypto.getRandomValues(a); this.pw = Array.from(a, n => c[n % c.length]).join(''); this.show = true; } }"
          x-init="if (! pw) gen()">
        @csrf
        <x-ui.card :title="__('Where')">
            <div class="form-grid">
                <x-ui.field name="website" type="select" :label="__('Website')" required>
                    @foreach ($websites as $w)
                        <option value="{{ $w->id }}" @selected(old('website', $selected) == $w->id)>{{ $w->domain }}@if ($area === 'admin') ({{ $w->customer?->displayName() }})@endif</option>
                    @endforeach
                </x-ui.field>
                @if ($meta['subdir'])
                    <x-ui.field name="path" :label="__('Folder')" placeholder="{{ __('empty = the website itself') }}" autocomplete="off" spellcheck="false"
                        :help="__('Leave empty to install in the root (example.com). Or type a folder such as blog for example.com/blog.')" />
                @endif
            </div>
            <p class="small muted">{{ __('The folder must be empty. The placeholder page of a new website is replaced.') }}</p>
        </x-ui.card>

        <x-ui.card :title="__('Site and admin account')">
            <div class="form-grid">
                <x-ui.field name="title" :label="__('Site name')" :value="old('title', __('My website'))" required maxlength="100" />
                @if (count($meta['locales']) > 1)
                    <div class="field">
                        <span class="label">{{ __('Language') }}</span>
                        <div class="seg" role="radiogroup" aria-label="{{ __('Language') }}">
                            @foreach ($meta['locales'] as $l)
                                <label><input type="radio" name="locale" value="{{ $l }}" @checked(old('locale', $defaults['locale']) === $l)><span>{{ $l === 'nl' ? 'Nederlands' : 'English' }}</span></label>
                            @endforeach
                        </div>
                    </div>
                @else
                    <input type="hidden" name="locale" value="{{ $meta['locales'][0] }}">
                @endif
                <x-ui.field name="admin_user" :label="__('Admin username')" :value="old('admin_user', $defaults['admin_user'])" required autocomplete="off" spellcheck="false"
                    :help="isset($meta['admin_user_hint']) ? __($meta['admin_user_hint']).' '.__('Avoid admin: it is the first name attackers try.') : __('Avoid admin: it is the first name attackers try.')" />
                <x-ui.field name="admin_email" type="email" :label="__('Admin e-mail address')" :value="old('admin_email', $defaults['admin_email'])" required />
                <div class="field" style="grid-column: 1 / -1">
                    <label for="f-admin_password">{{ __('Admin password') }} <span aria-hidden="true">*</span></label>
                    <div class="actions" style="flex-wrap:nowrap; max-width:560px">
                        <input id="f-admin_password" name="admin_password" class="input mono" style="flex:1; min-width:0" :type="show ? 'text' : 'password'" x-model="pw" required minlength="12" maxlength="128" autocomplete="new-password" spellcheck="false" @error('admin_password') aria-invalid="true" @enderror>
                        <button type="button" class="btn" @click="gen()" title="{{ __('New password') }}"><x-icon name="refresh" /><span class="sr-only">{{ __('New password') }}</span></button>
                        <button type="button" class="btn" @click="navigator.clipboard && navigator.clipboard.writeText(pw)">{{ __('Copy') }}</button>
                    </div>
                    <span class="help">{{ __('At least 12 characters. Save it in your password manager now: the panel does not store it.') }}</span>
                    @error('admin_password')<span class="error">{{ $message }}</span>@enderror
                </div>
            </div>
        </x-ui.card>

        @if ($databasesLeft !== null)
            <p class="small muted"><x-icon name="database" /> {{ __('A database is created for :app. Databases left in your package: :n.', ['app' => $meta['name'], 'n' => $databasesLeft]) }}</p>
        @endif
        <div class="actions">
            <button type="submit" class="btn btn-primary" @disabled($databasesLeft === 0)>{{ __('Install :app', ['app' => $meta['name']]) }}</button>
            <a class="btn" href="{{ route($area.'.apps.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
    @endif
</x-dynamic-component>
