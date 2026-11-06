{{-- Repository settings fields, used when linking (create) and on the repository page (show).
     $repo (null when creating), $area, $commandsAllowed, optional $defaults (per website id) and $branches. --}}
@php
    $steps = old('steps', $repo ? $repo->stepTypes() : ($defaults[$website->id]['steps'] ?? ['composer']));
    $stepLabels = [
        'composer' => [__('Composer install'), __('composer install --no-dev, when the repository has composer.json')],
        'npm' => [__('npm install and build'), __('npm ci, then npm run build when package.json has a build script')],
        'artisan_migrate' => [__('Laravel: run migrations'), __('php artisan migrate --force')],
        'artisan_optimize' => [__('Laravel: cache config and routes'), __('php artisan optimize')],
    ];
    $locked = $repo && $repo->deployed_commit;
    $sharedValue = $repo ? implode("\n", $repo->shared ?? []) : ($defaults[$website->id]['shared'] ?? '');
@endphp
<div class="form-grid">
    <x-ui.field name="url" :label="__('Repository address')" :value="$repo?->url" required
        placeholder="git@github.com:company/website.git" spellcheck="false" autocomplete="off"
        :help="__('SSH address for private repositories (with a deploy key), or the https:// address of a public repository.')" />
    <x-ui.field name="name" :label="__('Name')" :value="$repo?->name" :help="__('Optional. Shown in the panel.')" maxlength="80" />
</div>
<div class="form-grid">
    <div class="field">
        <label for="f-branch">{{ __('Branch') }} <span aria-hidden="true">*</span></label>
        <input id="f-branch" name="branch" class="input mono" required value="{{ old('branch', $repo?->branch ?? 'main') }}" list="git-branches" spellcheck="false" autocomplete="off">
        <datalist id="git-branches">@foreach ($branches ?? [] as $b)<option value="{{ $b }}">@endforeach</datalist>
        <span class="help">{{ __('Only pushes to this branch are deployed. Use for example main for the live site and a second repository with develop for a test site.') }}</span>
        @error('branch')<span class="error">{{ $message }}</span>@enderror
    </div>
    <div class="field">
        <label for="f-deploy_path">{{ __('Deploy to folder') }} <span aria-hidden="true">*</span></label>
        <div class="input-group"><span class="muted mono small">{{ ($repo?->website ?? $website)->domain }}/</span>
            <input id="f-deploy_path" name="deploy_path" class="input mono" required x-model="path" @disabled($locked) spellcheck="false"></div>
        @if ($locked)<input type="hidden" name="deploy_path" value="{{ $repo->deploy_path }}">@endif
        <span class="help">{{ __('Relative to the website folder. public is what visitors see; for Laravel use app (Nginx serves app/public).') }}</span>
        @error('deploy_path')<span class="error">{{ $message }}</span>@enderror
    </div>
</div>

<fieldset class="field" style="border:0;padding:0;margin:0">
    <legend class="label">{{ __('Deploy mode') }}</legend>
    <div class="seg" role="radiogroup">
        <label><input type="radio" name="mode" value="releases" x-model="mode" @disabled($locked)><span>{{ __('Zero downtime (recommended)') }}</span></label>
        <label><input type="radio" name="mode" value="direct" x-model="mode" @disabled($locked)><span>{{ __('Directly into the folder') }}</span></label>
    </div>
    @if ($locked)<input type="hidden" name="mode" value="{{ $repo->mode }}">@endif
    <p class="help" x-show="mode === 'releases'">{{ __('Every deployment is built in a new release folder. Only when all steps succeed the website switches over in one go, so visitors never see a half-updated site. Earlier releases stay available for a rollback.') }}</p>
    <p class="help" x-show="mode === 'direct'" x-cloak>{{ __('Files are written straight into the folder, like Plesk does. Simple, but while the build steps run, visitors may see a mix of old and new files.') }}</p>
    @error('mode')<span class="error">{{ $message }}</span>@enderror
</fieldset>

<div class="form-grid" x-show="mode === 'releases'">
    <x-ui.field name="shared" type="textarea" :label="__('Shared files and folders')" :value="$sharedValue"
        class="mono" rows="3" x-model="shared"
        :help="__('One per line. Kept between releases, for example .env, storage/ or wp-content/uploads/. End folders with /.')" />
    <x-ui.field name="keep_releases" type="number" :label="__('Releases to keep')" :value="$repo?->keep_releases ?? config('git.keep_releases', 5)" min="1" max="20" />
</div>

<fieldset class="field" style="border:0;padding:0;margin:0">
    <legend class="label">{{ __('After every deployment') }}</legend>
    @foreach ($stepLabels as $key => [$label, $hint])
        <label class="check"><input type="checkbox" name="steps[]" value="{{ $key }}" @checked(in_array($key, $steps, true))>
            <span>{{ $label }} <span class="muted small mono">{{ $hint }}</span></span></label>
    @endforeach
</fieldset>
@if ($commandsAllowed)
    <x-ui.field name="command" type="textarea" :label="__('Extra commands')" :value="$repo?->customCommand()" class="mono" rows="3"
        placeholder="php artisan queue:restart" spellcheck="false"
        :help="__('Optional. Bash, run in the deployed folder as the website\'s own user, after the steps above. A failing command stops the deployment.')" />
@endif

<label class="check"><input type="hidden" name="auto_deploy" value="0"><input type="checkbox" name="auto_deploy" value="1" @checked(old('auto_deploy', $repo?->auto_deploy ?? true))>
    <span><strong>{{ __('Deploy automatically on every push') }}</strong> <span class="muted small">{{ __('Off: a push only shows that there are new commits, you deploy with the button.') }}</span></span></label>
