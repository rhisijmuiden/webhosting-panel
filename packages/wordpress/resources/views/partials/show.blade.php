{{-- One WordPress install: updates, WP-cron, administrators and snapshots. Shared by admin and client. --}}
@php
    $r = fn ($name) => route($area.'.wordpress.'.$name, $install);
    $outdatedPlugins = collect($install->plugins ?? [])->where('update', true);
    $outdatedThemes = collect($install->themes ?? [])->where('update', true);
@endphp
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / {{ $install->label() }}</div>
        <div class="actions"><h1>{{ $install->label() }}</h1>
            <x-ui.pill :status="$install->core_update ? 'warn' : 'ok'">WordPress {{ $install->version ?? '?' }}</x-ui.pill>
            @if ($install->isOutdated())<x-ui.pill status="warn">{{ trans_choice(':count update|:count updates', $install->outdatedCount()) }}</x-ui.pill>@endif
        </div>
        <p class="sub">{{ $install->checked_at ? __('Checked :time', ['time' => $install->checked_at->diffForHumans()]) : __('Not checked yet') }}</p>
    </div>
    <div class="actions">
        <form method="POST" action="{{ $r('refresh') }}">@csrf<x-ui.button icon="refresh">{{ __('Check now') }}</x-ui.button></form>
        @if (Route::has($area.'.wordpress.extensions.index') && ($area === 'admin' || config('wordpress-extensions.customer_access')))<x-ui.button :href="route($area.'.wordpress.extensions.index', $install)" icon="apps">{{ __('Plugins & themes') }}</x-ui.button>@endif
        <form method="POST" action="{{ $r('login') }}" target="_blank">@csrf<x-ui.button variant="primary" icon="external">{{ __('Log in to WP admin') }}</x-ui.button></form>
    </div>
</div>

@foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
@if ($install->last_error)<div class="alert alert-warn" role="status"><x-icon name="info" /><div><strong>{{ __('Not everything could be checked') }}</strong><p class="small mono">{{ $install->last_error }}</p></div></div>@endif
@if (session('wp_password'))
    <div class="alert alert-ok" role="status" x-data="{ copied: false }"><x-icon name="lock" />
        <div><strong>{{ __('New password for :user', ['user' => session('wp_password')['user']]) }}</strong>
            <p><code class="mono" style="font-size:16px">{{ session('wp_password')['password'] }}</code>
                <button type="button" class="btn btn-sm" x-on:click="navigator.clipboard.writeText(@js(session('wp_password')['password'])); copied = true"><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></p>
            <p class="small muted">{{ __('This password is shown only once. Store it in your password manager.') }}</p></div>
    </div>
@endif

@includeIf('wordpress::switches.card')

<x-ui.card :title="__('Updates')">
    <x-slot:actions>
        <form method="POST" action="{{ $r('update') }}" onsubmit="return confirm(@js(__('Update WordPress, all plugins, themes and translations now?')))">
            @csrf<input type="hidden" name="scope" value="all">
            <x-ui.button variant="dark" icon="refresh">{{ __('Update everything') }}</x-ui.button>
        </form>
    </x-slot:actions>
    <p class="muted small">{{ __('Before every update the database, plugins and themes are saved. If the website stops working after the update, it is put back automatically.') }}</p>
    <form method="POST" action="{{ $r('update') }}" class="form">
        @csrf<input type="hidden" name="scope" value="selected">
        @if ($install->core_update)
            <label class="check"><input type="checkbox" name="core" value="1" checked><span>{{ __('WordPress :from to :to', ['from' => $install->version, 'to' => $install->core_update]) }}</span></label>
        @endif
        @foreach ([['plugins', __('Plugins'), $install->plugins ?? []], ['themes', __('Themes'), $install->themes ?? []]] as [$kind, $title, $items])
            <h3 class="small" style="margin:12px 0 4px">{{ $title }} <span class="muted">({{ count($items) }})</span></h3>
            @if (empty($items))
                <p class="muted small">{{ __('None') }}</p>
            @else
            <x-ui.table>
                <x-slot:head><th style="width:36px"><span class="sr-only">{{ __('Select') }}</span></th><th>{{ __('Name') }}</th><th>{{ __('Status') }}</th><th>{{ __('Version') }}</th><th>{{ __('Available') }}</th></x-slot:head>
                @foreach (collect($items)->sortByDesc('update') as $item)
                    <tr>
                        <td>@if ($item['update'])<input type="checkbox" name="{{ $kind }}[]" value="{{ $item['name'] }}" checked aria-label="{{ __('Update :name', ['name' => $item['title']]) }}">@endif</td>
                        <td><span class="strong">{{ $item['title'] }}</span> <span class="muted small mono">{{ $item['name'] }}</span></td>
                        <td><x-ui.pill :status="in_array($item['status'], ['active', 'parent'], true) ? 'ok' : 'neutral'">{{ __(ucfirst($item['status'])) }}</x-ui.pill></td>
                        <td class="mono">{{ $item['version'] ?: '–' }}</td>
                        <td>@if ($item['update'])<x-ui.pill status="warn">{{ $item['update_version'] }}</x-ui.pill>@else<span class="muted small">{{ __('Up to date') }}</span>@endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        @endforeach
        @if ($install->isOutdated())
            <div class="actions">
                <button type="submit" class="btn btn-dark">{{ __('Update selected') }}</button>
                @if ($area === 'admin')
                    <input type="hidden" name="snapshot" value="1">
                    <label class="check small"><input type="checkbox" name="snapshot" value="0"><span>{{ __('Skip the snapshot (faster, no automatic rollback)') }}</span></label>
                @endif
            </div>
        @endif
    </form>
    @if ($install->last_update_at)
        <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
        <p class="small"><strong>{{ __('Last update') }}</strong> {{ $install->last_update_at->diffForHumans() }}
            <x-ui.pill :status="$install->last_update_status === 'succeeded' ? 'ok' : 'bad'">{{ __(ucfirst(str_replace('_', ' ', $install->last_update_status))) }}</x-ui.pill></p>
        @php $s = $install->last_update_summary ?? []; @endphp
        <ul class="small muted">
            @if ($s['core'] ?? null)<li>WordPress {{ $s['core']['old_version'] }} → {{ $s['core']['new_version'] }}</li>@endif
            @foreach (array_merge($s['plugins'] ?? [], $s['themes'] ?? []) as $u)<li>{{ $u['name'] }} {{ $u['old_version'] }} → {{ $u['new_version'] }}</li>@endforeach
            @foreach ($s['errors'] ?? [] as $err)<li class="mono">{{ $err }}</li>@endforeach
        </ul>
    @endif
</x-ui.card>

@includeIf('wp-integrity::partials.wordpress-card')

<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('WP-cron')">
        <p class="muted small">{{ __('WordPress normally runs scheduled tasks when someone visits the site. On quiet sites tasks run late, on busy sites it slows pages down. Let the server run them on a fixed schedule instead.') }}</p>
        <form method="POST" action="{{ $r('cron') }}" class="form" x-data="{ mode: @js(old('mode', $cron['mode'] ?? $install->cron_mode)) }">
            @csrf @method('PUT')
            <div class="seg" role="radiogroup" aria-label="{{ __('Who runs WP-cron') }}">
                <label><input type="radio" name="mode" value="system" x-model="mode"><span>{{ __('Server (recommended)') }}</span></label>
                <label><input type="radio" name="mode" value="wordpress" x-model="mode"><span>{{ __('WordPress itself') }}</span></label>
            </div>
            <div x-show="mode === 'system'">
                <x-ui.field name="interval" type="select" :label="__('Run every')">
                    @foreach (config('wordpress.cron_intervals') as $n)
                        <option value="{{ $n }}" @selected((int) old('interval', $cron['interval'] ?? $install->cron_interval ?? 5) === $n)>{{ trans_choice(':count minute|:count minutes', $n) }}</option>
                    @endforeach
                </x-ui.field>
            </div>
            @if (($cron['mode'] ?? null) === 'system')
                <p class="small">{{ __('Last run') }}: <span class="mono">{{ $cron['last_run'] ?: __('not yet') }}</span>
                    @if ($cron['last_result'])<x-ui.pill :status="$cron['last_result'] === 'success' ? 'ok' : 'bad'">{{ $cron['last_result'] }}</x-ui.pill>@endif</p>
            @endif
            <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Administrators')">
        @if (empty($admins))
            <p class="muted small">{{ __('No administrators found.') }}</p>
        @else
            @foreach ($admins as $u)
                <div x-data="{ open: false, mode: 'generate' }" style="border-bottom:1px solid var(--line);padding:10px 0">
                    <div class="actions" style="justify-content:space-between">
                        <div><span class="strong">{{ $u['login'] }}</span> <span class="muted small">{{ $u['email'] }}</span></div>
                        <div class="actions">
                            <form method="POST" action="{{ $r('login') }}" target="_blank">@csrf<input type="hidden" name="user_id" value="{{ $u['id'] }}"><button class="btn btn-sm" type="submit"><x-icon name="external" />{{ __('Log in') }}</button></form>
                            <button type="button" class="btn btn-sm" x-on:click="open = !open"><x-icon name="lock" />{{ __('Password') }}</button>
                        </div>
                    </div>
                    <form method="POST" action="{{ $r('password') }}" class="form" x-show="open" x-cloak style="margin-top:10px">
                        @csrf<input type="hidden" name="user_id" value="{{ $u['id'] }}">
                        <div class="seg" role="radiogroup" aria-label="{{ __('New password') }}">
                            <label><input type="radio" name="mode" value="generate" x-model="mode"><span>{{ __('Generate a strong password') }}</span></label>
                            <label><input type="radio" name="mode" value="set" x-model="mode"><span>{{ __('Choose one') }}</span></label>
                        </div>
                        <div class="form-grid" x-show="mode === 'set'">
                            <x-ui.field name="password" type="password" :label="__('New password')" autocomplete="new-password" minlength="12" />
                            <x-ui.field name="password_confirmation" type="password" :label="__('Repeat password')" autocomplete="new-password" />
                        </div>
                        <div><button type="submit" class="btn btn-dark">{{ __('Change password') }}</button></div>
                    </form>
                </div>
            @endforeach
        @endif
    </x-ui.card>
</section>

@include('wordpress::search-replace.card')

<x-ui.card :title="__('Snapshots')" flush>
    @if (empty($snapshots))
        <x-ui.empty icon="backup">{{ __('A snapshot is made automatically before every update.') }}</x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head><th>{{ __('Made') }}</th><th class="num">{{ __('Size') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
        @foreach ($snapshots as $snap)
            @php $at = \Illuminate\Support\Carbon::createFromFormat('Ymd-His', $snap['id']); @endphp
            <tr>
                <td>{{ $at->isoFormat('LLL') }} <span class="muted small">({{ $at->diffForHumans() }})</span></td>
                <td class="num">{{ number_format($snap['size'] / 1048576, 1) }} MB</td>
                <td class="num"><form method="POST" action="{{ $r('restore') }}" onsubmit="return confirm(@js(__('Put this snapshot back? The database, plugins and themes return to this moment.')))">@csrf<input type="hidden" name="snapshot" value="{{ $snap['id'] }}"><button class="btn btn-sm" type="submit">{{ __('Restore') }}</button></form></td>
            </tr>
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>

@includeIf('wordpress::mail.partials.card', ['install' => $install, 'area' => $area])
