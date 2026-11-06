@php
    $meta = $installation->meta();
    $statusLabel = ['installing' => __('Installing'), 'installed' => __('Installed'), 'failed' => __('Failed'), 'removing' => __('Removing')];
    $statusPill = ['installing' => 'warn', 'installed' => 'ok', 'failed' => 'bad', 'removing' => 'warn'];
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="$meta['name'].' · '.$installation->location()">
    @if ($installation->busy())<meta http-equiv="refresh" content="3">@endif
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.apps.index') }}">{{ $area === 'admin' ? __('App installer') : __('Apps') }}</a> / {{ $installation->location() }}</div>
            <div class="actions">
                <span class="app-logo" style="--app-color: {{ $meta['color'] }}" aria-hidden="true">{{ $meta['monogram'] }}</span>
                <h1>{{ $meta['name'] }}</h1>
                <x-ui.pill :status="$statusPill[$installation->status] ?? 'neutral'">{{ $statusLabel[$installation->status] ?? $installation->status }}</x-ui.pill>
            </div>
        </div>
        @if ($installation->status === 'installed')
            <div class="actions">
                <a class="btn" href="{{ $installation->url }}" target="_blank" rel="noopener">{{ __('Visit website') }}<x-icon name="external" /></a>
                <a class="btn btn-primary" href="{{ $installation->admin_url }}" target="_blank" rel="noopener">{{ __('Log in to :app', ['app' => $meta['name']]) }}<x-icon name="external" /></a>
            </div>
        @endif
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror

    @if ($installation->busy())
        <x-ui.card>
            <p><x-ui.pill status="warn">{{ $installation->status === 'removing' ? __('Removing') : __('Installing') }}</x-ui.pill>
               <span class="muted small">{{ __('This page refreshes by itself.') }}</span></p>
            @if ($showLog && ! empty($job['log']))<div class="log">{{ implode("\n", $job['log']) }}</div>@endif
        </x-ui.card>
    @elseif ($installation->status === 'failed')
        <div class="alert alert-bad" role="alert"><x-icon name="info" />
            <div><strong>{{ __('The installation failed. Nothing was left behind on the website.') }}</strong>
            @if ($installation->error)<p class="small">{{ $installation->error }}</p>@endif</div>
        </div>
    @else
        @if ($installation->error)<div class="alert alert-warn" role="alert"><x-icon name="info" />{{ $installation->error }}</div>@endif
        <x-ui.card :title="__('Details')">
            <dl class="kv">
                <dt>{{ __('Address') }}</dt><dd><a href="{{ $installation->url }}" target="_blank" rel="noopener">{{ $installation->url }}</a></dd>
                <dt>{{ __('Admin') }}</dt><dd><a href="{{ $installation->admin_url }}" target="_blank" rel="noopener">{{ $installation->admin_url }}</a></dd>
                <dt>{{ __('Admin username') }}</dt><dd class="mono">{{ $installation->admin_user }}</dd>
                <dt>{{ __('Version') }}</dt><dd>{{ $installation->version ?? '–' }}</dd>
                <dt>{{ __('Database') }}</dt><dd class="mono">{{ $installation->database ?? '–' }}</dd>
                <dt>{{ __('Installed') }}</dt><dd>{{ $installation->installed_at?->isoFormat('LLL') ?? '–' }}</dd>
            </dl>
            <p class="small muted">{{ __('Updates of :app itself are done from its own admin screen. Backups of the files and the database are part of the nightly website backup.', ['app' => $meta['name']]) }}</p>
        </x-ui.card>
    @endif

    @unless ($installation->busy())
        <x-ui.card :title="$installation->status === 'failed' ? __('Clear') : __('Remove')">
            <form method="POST" action="{{ route($area.'.apps.destroy', $installation) }}" class="form"
                  data-confirm="{{ $installation->status === 'failed' ? __('Remove this failed installation from the list?') : __('Remove :app from :location? All its files and its database are deleted. This cannot be undone.', ['app' => $meta['name'], 'location' => $installation->location()]) }}">
                @csrf @method('DELETE')
                <p class="small muted">{{ $installation->status === 'failed' ? __('Removes this entry from the list.') : __('Deletes the files and the database of this app. Make a backup first if you want to keep anything.') }}</p>
                <div><button type="submit" class="btn btn-danger"><x-icon name="trash" />{{ $installation->status === 'failed' ? __('Clear') : __('Remove :app', ['app' => $meta['name']]) }}</button></div>
            </form>
        </x-ui.card>
    @endunless
</x-dynamic-component>
