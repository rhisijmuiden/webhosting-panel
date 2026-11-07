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
        @if ($area === 'admin' && \Illuminate\Support\Facades\Route::has('admin.apps.sets.index'))
            <div class="actions"><x-ui.button icon="wordpress" :href="route('admin.apps.sets.index')">{{ __('WordPress plugin sets') }}</x-ui.button></div>
        @endif
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if ($databasesLeft === 0)
        <div class="alert alert-warn" role="status"><x-icon name="database" />{{ __('Your package allows no more databases. Remove an app or ask us for a larger package.') }}</div>
    @endif

    @if (count($categories) > 1)
        <nav class="app-filter" aria-label="{{ __('Categories') }}">
            <a href="{{ route($area.'.apps.index', $website ? ['website' => $website] : []) }}" @if (! $category) aria-current="page" @endif>{{ __('All apps') }}</a>
            @foreach ($categories as $key => $label)
                <a href="{{ route($area.'.apps.index', ['category' => $key] + ($website ? ['website' => $website] : [])) }}" @if ($category === $key) aria-current="page" @endif>{{ __($label) }}</a>
            @endforeach
        </nav>
    @endif

    <section class="app-grid" aria-label="{{ __('Available apps') }}">
        @forelse ($catalog as $id => $app)
            @php
                $held = ($app['maintained'] ?? null) === false;
                $needsDb = $app['database'] ?? true;
            @endphp
            <article class="card app-tile @if ($held) app-tile-held @endif">
                <div class="app-tile-head">
                    <span class="app-logo" style="--app-color: {{ $app['color'] }}" aria-hidden="true">{{ $app['monogram'] }}</span>
                    <div><h2>{{ $app['name'] }}</h2><p class="small muted">{{ __(config('apps.categories.'.$app['category'], $app['category'])) }}</p></div>
                </div>
                <p class="small">{{ __($app['description']) }}</p>
                @if ($held)
                    <p class="small app-held"><x-icon name="shield" />{{ ($app['end_of_life'] ?? false)
                        ? __('Not offered: this version is end of life.')
                        : __('Not offered: no release in the last :days days.', ['days' => $maxAge]) }}</p>
                @endif
                <ul class="app-facts small muted">
                    @if (! empty($app['latest_version']))
                        <li title="{{ __('Latest release') }}">v{{ $app['latest_version'] }}@if (! empty($app['released_at'])) · {{ \Illuminate\Support\Carbon::createFromTimestamp($app['released_at'])->isoFormat('LL') }}@endif</li>
                    @endif
                    <li>{{ collect($app['locales'])->map(fn ($l) => strtoupper($l))->implode(' · ') }}</li>
                    @unless ($needsDb)<li>{{ __('No database') }}</li>@endunless
                </ul>
                <div class="app-tile-foot">
                    @if ($held || ($needsDb && $databasesLeft === 0))
                        <button class="btn btn-sm" type="button" disabled>{{ __('Install') }}</button>
                    @else
                        <x-ui.button variant="primary" size="sm" icon="plus" :href="route($area.'.apps.create', ['app' => $id] + ($website ? ['website' => $website] : []))">{{ __('Install') }}</x-ui.button>
                    @endif
                    @if (! empty($app['homepage']))
                        <a class="small muted" href="{{ $app['homepage'] }}" target="_blank" rel="noopener">{{ parse_url($app['homepage'], PHP_URL_HOST) }}</a>
                    @endif
                </div>
            </article>
        @empty
            <x-ui.card><x-ui.empty icon="apps">{{ __('No apps are available on this server.') }}</x-ui.empty></x-ui.card>
        @endforelse
    </section>

    <p class="small muted app-policy"><x-icon name="shield" />{{ __('Only apps that are actively maintained are offered: every app must have had a release in the last :days days. Installed apps show when an update is out.', ['days' => $maxAge]) }}</p>

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
                        <td class="small">{{ $i->version ?? '–' }}
                            @if ($updates[$i->id]['update_available'] ?? false)
                                <x-ui.pill status="warn">{{ __('Update: :v', ['v' => $updates[$i->id]['latest_version']]) }}</x-ui.pill>
                            @endif
                        </td>
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
