{{-- Object cache of one website, on the domain page (Hosting & DNS tab), for admins and customers.
     Needs $o (App\Support\SiteOverview) and $website. --}}
@php
    $ocCache = \Rhis\ObjectCache\Models\ObjectCache::where('website_id', $website->id)->first();
    $ocMemory = \Rhis\ObjectCache\ObjectCacheManager::memoryFor($website);
    $ocAllowed = $ocMemory !== null;
    $ocCanManage = $o->has('object-cache.update') && ($o->isAdmin() || config('object-cache.customer_access'));
    $ocOn = $ocCache?->enabled ?? false;
    $ocBusy = $ocCache?->isBusy() ?? false;
    $ocLive = $ocOn && ! $ocBusy ? app(\Rhis\ObjectCache\ObjectCacheManager::class)->status($website) : null;
    $ocStats = $ocLive['stats'] ?? null;
    $ocState = $ocBusy ? 'applying' : ($ocOn ? ($ocLive['state'] ?? $ocCache->state) : 'off');
    $ocSocket = $ocLive['socket'] ?? ($website->system_user ? '/run/whp-objcache/'.$website->system_user.'/valkey.sock' : null);
    $ocHasWp = $o->wordpress->isNotEmpty() || ! empty($ocCache?->installs);
@endphp
@if ($ocCanManage && ($ocAllowed || $ocOn))
<x-ui.card :title="__('Object cache')" id="object-cache" class="oc-card">
    <x-slot:actions>
        @switch($ocState)
            @case('applying')<x-ui.pill status="pending">{{ __('Being set up') }}</x-ui.pill>@break
            @case('running')<x-ui.pill status="running">{{ __('Running') }}</x-ui.pill>@break
            @case('failed')<x-ui.pill status="failed">{{ __('Failed') }}</x-ui.pill>@break
            @case('off')<x-ui.pill status="off">{{ __('Off') }}</x-ui.pill>@break
            @default<x-ui.pill status="warn">{{ __('Stopped') }}</x-ui.pill>
        @endswitch
    </x-slot:actions>
    @if ($ocBusy)<meta http-equiv="refresh" content="5">@endif
    <style>
        .oc-card .oc-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .oc-card .oc-stats div { display: flex; flex-direction: column; gap: 2px; }
        .oc-card .oc-stats span { font-size: 12px; color: var(--ink-muted); font-weight: 600; }
        .oc-card .oc-stats strong { font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; }
        .oc-card .oc-installs { display: flex; flex-wrap: wrap; gap: 6px; }
        .oc-card details summary { cursor: pointer; font-size: 13px; font-weight: 600; }
        .oc-card pre { margin: 8px 0 0; padding: 10px 12px; border-radius: var(--radius-md, 8px); background: var(--surface-2, rgba(127,127,127,.08));
            font-size: 12px; overflow-x: auto; white-space: pre; }
        .oc-card .oc-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        @media (max-width: 560px) { .oc-card .oc-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>

    <p class="muted small">{{ __('Keeps database results in memory, so WordPress, WooCommerce and Laravel build pages faster. This website gets its own Valkey server: nobody else can reach it.') }}
        @if ($ocAllowed){{ __('Memory: :size.', ['size' => \Illuminate\Support\Number::fileSize($ocMemory * 1024 * 1024)]) }}@endif</p>

    @error('object_cache')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (! $ocBusy && $ocCache?->state === 'failed' && $ocCache->last_error)
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The object cache could not be switched on') }}</strong><p class="small mono">{{ $ocCache->last_error }}</p></div></div>
    @endif

    @if ($ocStats)
        @php $ocPct = ($ocStats['maxmemory_bytes'] ?? 0) > 0 ? ($ocStats['used_memory_bytes'] ?? 0) / $ocStats['maxmemory_bytes'] * 100 : 0; @endphp
        <x-ui.meter label="" :percent="$ocPct" />
        <div class="oc-stats">
            <div><span>{{ __('Memory used') }}</span><strong>{{ \Illuminate\Support\Number::fileSize($ocStats['used_memory_bytes'] ?? 0, 1) }}</strong></div>
            <div><span>{{ __('Hit ratio') }}</span><strong>{{ $ocStats['hit_ratio'] !== null ? \Illuminate\Support\Number::format($ocStats['hit_ratio'], 1).' %' : '–' }}</strong></div>
            <div><span>{{ __('Items') }}</span><strong>{{ \Illuminate\Support\Number::format($ocStats['keys'] ?? 0) }}</strong></div>
        </div>
    @endif

    @if ($ocOn && ! empty($ocCache->installs))
        <div class="oc-row">
            <span class="small strong">{{ __('WordPress') }}</span>
            <span class="oc-installs">
                @foreach ($ocCache->installs as $inst)
                    @php $label = $website->domain.(($inst['path'] ?? '') !== '' ? '/'.$inst['path'] : ''); @endphp
                    @if ($inst['configured'] ?? false)
                        <x-ui.pill status="ok">{{ $label }}@if ($inst['woocommerce'] ?? false) · WooCommerce @endif</x-ui.pill>
                    @else
                        <x-ui.pill status="warn" title="{{ $inst['error'] ?? '' }}">{{ $label }}: {{ __('not connected') }}</x-ui.pill>
                    @endif
                @endforeach
            </span>
        </div>
        @foreach ($ocCache->failedInstalls() as $inst)
            <p class="small muted">{{ $website->domain.(($inst['path'] ?? '') !== '' ? '/'.$inst['path'] : '') }}: {{ $inst['error'] }}</p>
        @endforeach
    @endif

    @if ($ocAllowed || $ocOn)
    <form method="POST" action="{{ $o->url('object-cache.update', $website) }}" class="form">
        @csrf @method('PUT')
        <input type="hidden" name="enabled" value="0">
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked($ocOn) @disabled($ocBusy || ! $ocAllowed && ! $ocOn)><span>{{ __('Object cache (Valkey)') }}
            <small>{{ __('Switching on takes a few seconds; the cache starts empty and fills itself.') }}</small></span></label>
        <input type="hidden" name="wordpress" value="0">
        <label class="check"><input type="checkbox" name="wordpress" value="1" @checked($ocCache?->wordpress ?? true) @disabled($ocBusy)><span>{{ __('Set up WordPress automatically') }}
            <small>{{ $ocHasWp ? __('Installs and connects the Redis Object Cache plugin in every WordPress on this website, WooCommerce included.') : __('For WordPress installed later: switch the cache off and on again.') }}</small></span></label>
        @php $ocFlush = $ocOn && ! $ocBusy && $o->has('object-cache.flush'); @endphp
        <div class="oc-row">
            <button type="submit" class="btn btn-dark" @disabled($ocBusy)>{{ __('Save') }}</button>
            @if ($ocFlush)
                <button class="btn" type="submit" form="oc-flush-{{ $website->id }}"><x-icon name="refresh" />{{ __('Flush cache') }}</button>
                @if ($ocCache->flushed_at)<span class="muted small">{{ __('Last emptied :time', ['time' => $ocCache->flushed_at->diffForHumans()]) }}</span>@endif
            @endif
        </div>
    </form>
    @if ($ocFlush)
        <form method="POST" id="oc-flush-{{ $website->id }}" action="{{ $o->url('object-cache.flush', $website) }}" data-confirm="{{ __('Empty the object cache of :domain? It fills itself again.', ['domain' => $website->domain]) }}" hidden>@csrf</form>
    @endif
    @endif

    @if ($ocOn && $ocSocket)
        <details>
            <summary>{{ __('Connection details for developers') }}</summary>
            <p class="muted small">{{ __('Unix socket, no password needed: only this website can open it. For Laravel, put this in .env:') }}</p>
            <pre><code>REDIS_CLIENT=phpredis
REDIS_HOST={{ $ocSocket }}
REDIS_PORT=0
REDIS_PASSWORD=null
CACHE_STORE=redis</code></pre>
        </details>
    @endif
</x-ui.card>
@elseif (! $ocAllowed && $o->isAdmin())
<x-ui.card :title="__('Object cache')" id="object-cache">
    <p class="muted small">{{ __('The service plan of this website has no object cache. Give the plan an object cache memory limit to offer it.') }}</p>
</x-ui.card>
@endif
