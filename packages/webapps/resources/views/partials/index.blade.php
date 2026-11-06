{{-- Apps overview, shared by the admin and client screens. --}}
<div class="page-head">
    <div>
        <h1>{{ __('Node.js & Python') }}</h1>
        <p class="sub">{{ __('Run Node.js and Python apps next to PHP: Express, Next.js, Nuxt, Django, Flask, FastAPI and more. Each app runs as its website\'s own user, with its own limits, behind Nginx.') }}</p>
    </div>
    <div class="actions">
        @if ($area === 'admin')<x-ui.button icon="settings" :href="route('admin.webapps.runtimes')">{{ __('Runtime versions') }}</x-ui.button>@endif
        @if ($canCreate)<x-ui.button variant="primary" icon="plus" :href="route($area.'.webapps.create')">{{ __('New app') }}</x-ui.button>@endif
    </div>
</div>

@if ($area === 'admin' && $runtimes && collect($runtimes['node'] ?? [])->merge($runtimes['python'] ?? [])->where('installed', true)->isEmpty())
    <div class="alert alert-warn" role="status"><x-icon name="info" /><div>{{ __('No Node.js or Python version is installed yet.') }}
        <a href="{{ route('admin.webapps.runtimes') }}">{{ __('Install one') }}</a></div></div>
@endif

<x-ui.card :title="__('Apps')" flush>
    @if ($list->isEmpty())
        <x-ui.empty icon="runtime">{{ __('No apps yet. An app can serve a whole website or one path of it, like /api.') }}</x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head>
            <th>{{ __('App') }}</th><th>{{ __('Address') }}</th>
            @if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif
            <th>{{ __('Status') }}</th><th class="num">{{ __('Memory') }}</th><th>{{ __('Running since') }}</th>
        </x-slot:head>
        @foreach ($list as $a)
            @php $st = $statuses[$a->uid] ?? null; @endphp
            <tr>
                <td><div class="wa-runtime">@include('webapps::partials.badge', ['runtime' => $a->runtime])
                    <div><a class="strong" href="{{ route($area.'.webapps.show', $a) }}">{{ $a->name }}</a>
                    <div class="muted small">{{ $a->runtimeLabel() }}</div></div></div></td>
                <td>{{ $a->website->domain }}<span class="mono">{{ $a->mount_path === '/' ? '' : $a->mount_path }}</span>
                    <div class="muted small mono">{{ $a->app_root }}/</div></td>
                @if ($area === 'admin')<td>{{ $a->website->customer?->name ?? '–' }}</td>@endif
                <td>@include('webapps::partials.state-pill', ['state' => $st['state'] ?? $a->last_state])</td>
                <td class="num">{{ isset($st['memory_bytes']) ? \Illuminate\Support\Number::fileSize($st['memory_bytes'], 0) : '–' }}</td>
                <td>{{ ($st['since'] ?? null) && ($st['state'] ?? '') === 'running' ? \Illuminate\Support\Carbon::createFromTimestamp($st['since'])->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) : '–' }}</td>
            </tr>
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>
