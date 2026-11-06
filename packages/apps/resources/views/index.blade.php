@php
    $statusLabel = ['installing' => __('Installing'), 'installed' => __('Installed'), 'failed' => __('Failed'), 'removing' => __('Removing')];
    $statusPill = ['installing' => 'warn', 'installed' => 'ok', 'failed' => 'bad', 'removing' => 'warn'];
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('App installer')">
    @if ($installations->contains->busy())<meta http-equiv="refresh" content="5">@endif
    <div class="page-head">
        <div>
            <h1>{{ $area === 'admin' ? __('App installer') : __('Apps') }}</h1>
            <p class="sub">{{ __('Put a ready-to-use website or app in a website folder with one click: files, database, configuration and an admin account.') }}</p>
        </div>
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if ($databasesLeft === 0)
        <div class="alert alert-warn" role="status"><x-icon name="database" />{{ __('Your package allows no more databases. Remove an app or ask us for a larger package.') }}</div>
    @endif

    <section class="app-grid" aria-label="{{ __('Available apps') }}">
        @forelse ($catalog as $id => $app)
            <article class="card app-tile">
                <div class="app-tile-head">
                    <span class="app-logo" style="--app-color: {{ $app['color'] }}" aria-hidden="true">{{ $app['monogram'] }}</span>
                    <div><h2>{{ $app['name'] }}</h2><p class="small muted">{{ __($app['category']) }}</p></div>
                </div>
                <p class="small">{{ __($app['description']) }}</p>
                <div class="app-tile-foot">
                    <span class="small muted">{{ collect($app['locales'])->map(fn ($l) => strtoupper($l))->implode(' · ') }}</span>
                    @if ($databasesLeft === 0)
                        <button class="btn btn-sm" type="button" disabled>{{ __('Install') }}</button>
                    @else
                        <x-ui.button variant="primary" size="sm" icon="plus" :href="route($area.'.apps.create', ['app' => $id] + ($website ? ['website' => $website] : []))">{{ __('Install') }}</x-ui.button>
                    @endif
                </div>
            </article>
        @empty
            <x-ui.card><x-ui.empty icon="apps">{{ __('No apps are available on this server.') }}</x-ui.empty></x-ui.card>
        @endforelse
    </section>

    <x-ui.card :title="__('Installed apps')" flush>
        @if ($installations->isEmpty())
            <x-ui.empty icon="apps">{{ __('No apps installed yet.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <x-slot:head>
                    <th>{{ __('App') }}</th><th>{{ __('Location') }}</th>
                    @if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif
                    <th>{{ __('Version') }}</th><th>{{ __('Status') }}</th><th class="num"><span class="sr-only">{{ __('Actions') }}</span></th>
                </x-slot:head>
                @foreach ($installations as $i)
                    <tr>
                        <td><a href="{{ route($area.'.apps.show', $i) }}" class="app-name"><span class="app-logo app-logo-sm" style="--app-color: {{ $i->meta()['color'] }}" aria-hidden="true">{{ $i->meta()['monogram'] }}</span>{{ $i->meta()['name'] }}</a></td>
                        <td class="mono small">{{ $i->location() }}</td>
                        @if ($area === 'admin')<td class="small">{{ $i->website?->customer?->displayName() }}</td>@endif
                        <td class="small">{{ $i->version ?? '–' }}</td>
                        <td><x-ui.pill :status="$statusPill[$i->status] ?? 'neutral'">{{ $statusLabel[$i->status] ?? $i->status }}</x-ui.pill></td>
                        <td class="num">
                            @if ($i->status === 'installed' && $i->admin_url)
                                <a class="btn btn-sm" href="{{ $i->admin_url }}" target="_blank" rel="noopener">{{ __('Log in') }}<x-icon name="external" /></a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</x-dynamic-component>
