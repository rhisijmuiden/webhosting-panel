{{-- App settings, used when creating an app and on the app page.
     $app (new or existing), $choices [runtime => [version => label]], $area, optional $websites/$website (create). --}}
@php
    $existing = $app->exists;
    $site = $app->website ?? $website;
    $env = old('env', collect($app->env ?? [])->map(fn ($v, $k) => ['key' => $k, 'value' => $v])->values()->all());
    $runtime = old('runtime', $app->runtime ?? 'node');
    $firstVersion = fn ($rt) => array_key_last($choices[$rt] ?? []) ?? '';
    $version = old('runtime_version', $app->runtime_version ?: $firstVersion($runtime));
    $presets = collect(\Rhis\WebApps\Models\WebApp::PRESETS)->map(fn ($p) => collect($p)->map(fn ($label, $cmd) => ['cmd' => $cmd, 'label' => $label])->values());
    $versionsJs = collect(\Rhis\WebApps\Models\WebApp::RUNTIMES)->keys()->mapWithKeys(fn ($rt) => [$rt => collect($choices[$rt] ?? [])->map(fn ($l, $v) => ['v' => (string) $v, 'l' => $l])->values()]);
@endphp
<div x-data="{
        runtime: @js($runtime), version: @js((string) $version), start: @js(old('start_command', $app->start_command)),
        mount: @js(old('mount_path', $app->mount_path ?? '/')),
        env: @js(array_values($env ?: [['key' => '', 'value' => '']])),
        versions: @js($versionsJs), presets: @js($presets), show: false,
        pick(rt) { this.runtime = rt; const v = this.versions[rt] || []; this.version = v.length ? v[v.length - 1].v : '';
                   if (!this.start || Object.values(this.presets).flat().some(p => p.cmd === this.start)) this.start = (this.presets[rt] || [{cmd: ''}])[0].cmd; }
     }" class="form" style="display:grid;gap:16px">

    @if (! $existing)
    <div class="form-grid">
        <x-ui.field name="website_id" type="select" :label="__('Website')" required>
            @foreach ($websites as $w)
                <option value="{{ $w->id }}" @selected((int) old('website_id', $website?->id) === $w->id)>{{ $w->domain }}@if ($area === 'admin' && $w->customer) ({{ $w->customer->name }})@endif</option>
            @endforeach
        </x-ui.field>
        <x-ui.field name="name" :label="__('Name')" :value="$app->name" required maxlength="80" placeholder="Shop API" />
    </div>
    @else
        <x-ui.field name="name" :label="__('Name')" :value="$app->name" required maxlength="80" />
    @endif

    <fieldset class="field" style="border:0;padding:0;margin:0">
        <legend class="label">{{ __('Runtime') }}</legend>
        <div class="seg" role="radiogroup">
            @foreach (\Rhis\WebApps\Models\WebApp::RUNTIMES as $rt => $label)
                <label><input type="radio" name="runtime" value="{{ $rt }}" :checked="runtime === @js($rt)" x-on:change="pick(@js($rt))"><span>{{ $label }}</span></label>
            @endforeach
        </div>
        @error('runtime')<span class="error">{{ $message }}</span>@enderror
    </fieldset>

    <div class="form-grid">
        <div class="field">
            <label for="f-runtime_version">{{ __('Version') }} <span aria-hidden="true">*</span></label>
            <select id="f-runtime_version" name="runtime_version" class="select" x-model="version" required>
                <template x-for="o in (versions[runtime] || [])" :key="o.v"><option :value="o.v" x-text="o.l" :selected="o.v === version"></option></template>
            </select>
            <span class="help" x-show="(versions[runtime] || []).length === 0">{{ __('No version of this runtime is installed on the server yet.') }}
                @if ($area === 'admin')<a href="{{ route('admin.webapps.runtimes') }}">{{ __('Install one') }}</a>@else{{ __('Ask your hosting provider.') }}@endif</span>
            @error('runtime_version')<span class="error">{{ $message }}</span>@enderror
        </div>
        <div class="field">
            <label for="f-app_root">{{ __('App folder') }} <span aria-hidden="true">*</span></label>
            <div class="input-group"><span class="muted mono small">{{ $site?->domain ?? 'example.com' }}/</span>
                <input id="f-app_root" name="app_root" class="input mono" required value="{{ old('app_root', $app->app_root ?? 'app') }}" spellcheck="false"></div>
            <span class="help">{{ __('Where the code is, relative to the website folder. Upload it there, or deploy it with Git to the same folder. Keep it outside public.') }}</span>
            @error('app_root')<span class="error">{{ $message }}</span>@enderror
        </div>
    </div>

    <div class="field">
        <label for="f-start_command">{{ __('Start command') }} <span aria-hidden="true">*</span></label>
        <input id="f-start_command" name="start_command" class="input mono" required x-model="start" spellcheck="false" autocomplete="off">
        <div class="wa-presets"><template x-for="p in (presets[runtime] || [])" :key="p.cmd">
            <button type="button" x-on:click="start = p.cmd" x-text="p.label"></button></template></div>
        <span class="help">{{ __('Runs in the app folder as the website user. The app must listen on 127.0.0.1 and the port in $PORT (Node.js: process.env.PORT).') }}</span>
        @error('start_command')<span class="error">{{ $message }}</span>@enderror
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="f-mount_path">{{ __('Address') }} <span aria-hidden="true">*</span></label>
            <div class="input-group"><span class="muted mono small">{{ $site?->domain ?? 'example.com' }}</span>
                <input id="f-mount_path" name="mount_path" class="input mono" required x-model="mount" spellcheck="false"></div>
            <span class="help" x-show="mount.replace(/\/+$/, '') === ''">{{ __('/ serves the whole website from the app. PHP is no longer used for this website.') }}</span>
            <span class="help" x-show="mount.replace(/\/+$/, '') !== ''" x-cloak>{{ __('Only this path goes to the app, PHP keeps serving the rest of the website.') }}</span>
            @error('mount_path')<span class="error">{{ $message }}</span>@enderror
        </div>
        <x-ui.field name="build_command" :label="__('Build command')" :value="$app->build_command" class="mono" spellcheck="false"
            placeholder="npm run build" :help="__('Optional. Runs after installing the dependencies, for example npm run build or python manage.py collectstatic --noinput.')" />
    </div>
    <label class="check" x-show="mount.replace(/\/+$/, '') !== ''" x-cloak><input type="hidden" name="strip_prefix" value="0"><input type="checkbox" name="strip_prefix" value="1" @checked(old('strip_prefix', $app->strip_prefix))>
        <span>{{ __('Remove the path before passing the request') }}<small>{{ __('The app then gets /products instead of /api/products. Leave off when the app knows its own base path.') }}</small></span></label>

    <fieldset class="field" style="border:0;padding:0;margin:0">
        <legend class="label">{{ __('Environment variables') }}</legend>
        <div class="wa-env">
            <template x-for="(row, i) in env" :key="i">
                <div class="wa-env-row">
                    <input class="input mono" :name="`env[${i}][key]`" x-model="row.key" placeholder="DATABASE_URL" spellcheck="false" autocomplete="off" aria-label="{{ __('Name') }}">
                    <input class="input mono wa-val" :name="`env[${i}][value]`" x-model="row.value" :type="show ? 'text' : 'password'" placeholder="{{ __('Value') }}" spellcheck="false" autocomplete="off" aria-label="{{ __('Value') }}">
                    <button type="button" class="btn btn-sm" x-on:click="env.splice(i, 1); if (!env.length) env.push({key: '', value: ''})" aria-label="{{ __('Remove') }}"><x-icon name="trash" /></button>
                </div>
            </template>
        </div>
        <div class="actions" style="margin-top:8px">
            <button type="button" class="btn btn-sm" x-on:click="env.push({key: '', value: ''})"><x-icon name="plus" />{{ __('Add variable') }}</button>
            <button type="button" class="btn btn-sm" x-on:click="show = !show"><x-icon name="key" /><span x-text="show ? @js(__('Hide values')) : @js(__('Show values'))">{{ __('Show values') }}</span></button>
        </div>
        <span class="help">{{ __('Stored encrypted and readable only by the server. PORT and HOST are set by the panel. NODE_ENV is production unless you set it.') }}</span>
        @foreach ($errors->getMessages() as $k => $msgs)@if (str_starts_with($k, 'env'))<span class="error">{{ $msgs[0] }}</span>@endif @endforeach
    </fieldset>

    <label class="check"><input type="hidden" name="deps_on_deploy" value="0"><input type="checkbox" name="deps_on_deploy" value="1" @checked(old('deps_on_deploy', $app->deps_on_deploy ?? true))>
        <span>{{ __('Install dependencies on every Git deployment') }}<small>{{ __('npm ci or uv pip install and the build command run before a new release goes live, then the app restarts.') }}</small></span></label>
</div>
