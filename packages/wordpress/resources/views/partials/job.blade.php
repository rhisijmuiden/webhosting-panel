@if (($job['status'] ?? null) === 'running')
    <meta http-equiv="refresh" content="3">
@endif
@php
    $results = ($meta['kind'] === 'update_many') ? ($job['result']['results'] ?? []) : null;
    $title = ['update' => __('WordPress update'), 'update_many' => __('Update of :n sites', ['n' => count($meta['installs'])]), 'restore' => __('Restore snapshot')][$meta['kind']] ?? __('WordPress');
    $one = $results === null ? ($job['result'] ?? ($job['error']['details'] ?? [])) : null;
@endphp
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a></div>
        <h1>{{ $title }}</h1>
        <p class="sub">{{ $installs->map->label()->implode(', ') }} · {{ __('Started') }} {{ \Illuminate\Support\Carbon::parse($meta['started_at'])->diffForHumans() }}{{ isset($meta['user']) ? ' · '.$meta['user'] : '' }}</p>
    </div>
    <div class="actions">
        @if ($installs->count() === 1)<x-ui.button :href="route($area.'.wordpress.show', $installs->first())" icon="wordpress">{{ __('Back to the site') }}</x-ui.button>@endif
    </div>
</div>
@foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert">{{ $e }}</div>@endforeach
@if ($job)
    <x-ui.card>
        <p>
            @if ($job['status'] === 'running')<x-ui.pill status="warn">{{ __('Running') }}</x-ui.pill> <span class="muted small">{{ __('This page refreshes by itself. You can also close it, the update continues.') }}</span>
            @elseif ($job['status'] === 'succeeded' && ! ($job['result']['failed'] ?? 0))<x-ui.pill status="ok">{{ __('Done') }}</x-ui.pill>
            @elseif ($one['rolled_back'] ?? false)<x-ui.pill status="bad">{{ __('Rolled back') }}</x-ui.pill>
            @else<x-ui.pill status="bad">{{ __('Not everything succeeded') }}</x-ui.pill>@endif
        </p>
        @if ($one['rolled_back'] ?? false)
            <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('The website stopped working after the update (HTTP :code), so everything was put back as it was. Nothing was lost.', ['code' => $one['health']['after'] ?? '?']) }}</div>
        @elseif ($job['error'] ?? null)
            <div class="alert alert-bad" role="alert">{{ $job['error']['message'] ?? '' }}</div>
        @endif

        @foreach ($results ?? ($one ? [['ok' => $job['status'] === 'succeeded'] + $one] : []) as $res)
            @php $d = $res['result'] ?? $res; $u = $d['updated'] ?? []; @endphp
            @if ($results !== null)<h3 class="small" style="margin:12px 0 4px">{{ $res['domain'] ?? '' }}{{ ($res['path'] ?? '') !== '' ? '/'.$res['path'] : '' }}
                <x-ui.pill :status="($res['ok'] ?? false) ? 'ok' : 'bad'">{{ ($res['ok'] ?? false) ? __('Done') : (($d['rolled_back'] ?? false) ? __('Rolled back') : __('Failed')) }}</x-ui.pill></h3>@endif
            <ul class="small">
                @if ($u['core'] ?? null)<li>WordPress {{ $u['core']['old_version'] }} → {{ $u['core']['new_version'] }}</li>@endif
                @foreach (array_merge($u['plugins'] ?? [], $u['themes'] ?? []) as $item)<li>{{ $item['name'] }} {{ $item['old_version'] }} → {{ $item['new_version'] }}</li>@endforeach
                @if (($res['ok'] ?? false) && empty($u['core']) && empty($u['plugins']) && empty($u['themes']) && $meta['kind'] !== 'restore')<li class="muted">{{ __('Nothing needed an update.') }}</li>@endif
                @foreach ($d['errors'] ?? [] as $err)<li class="mono">{{ $err }}</li>@endforeach
                @if (isset($res['error']) && ! ($d['rolled_back'] ?? false))<li class="mono">{{ $res['error'] }}</li>@endif
            </ul>
        @endforeach

        @if ($area === 'admin' && ! empty($job['log']))<details @if ($job['status'] === 'running') open @endif><summary class="small">{{ __('Log') }}</summary><div class="log">{{ implode("\n", $job['log']) }}</div></details>@endif
    </x-ui.card>
@endif
