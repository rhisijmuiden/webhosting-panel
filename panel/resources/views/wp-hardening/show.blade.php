{{-- Security hardening of one WordPress install. Shared by the client and the admin panel ($area). --}}
@php
    $r = fn (string $name) => route($area.'.wp-hardening.'.$name, $install);
    $m = $status['measures'] ?? [];
    $on = (int) ($status['on'] ?? 0);
    $total = (int) ($status['total'] ?? count($definitions));
    $score = $total ? (int) round($on / $total * 100) : 0;
    $tone = $on >= $total ? 'ok' : ($on >= 3 ? 'warn' : 'bad');
    $salts = $status['salts'] ?? [];
    $refreshed = ($salts['refreshed_at'] ?? null) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $salts['refreshed_at']) : null;
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Security hardening').' · '.$install->label()">
    <link rel="stylesheet" href="{{ asset('assets/wp-hardening.css') }}?v={{ @filemtime(public_path('assets/wp-hardening.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.wordpress.index') }}">{{ __('WordPress') }}</a> / <a href="{{ route($area.'.wordpress.show', $install) }}">{{ $install->label() }}</a> / {{ __('Security hardening') }}</div>
            <h1>{{ __('Security hardening') }}</h1>
            <p class="muted">{{ __('Extra protection for :site with one click. Every measure can be switched off again at any time.', ['site' => $install->label()]) }}</p>
        </div>
        <a class="btn" href="{{ route($area.'.wordpress.show', $install) }}">{{ __('Back to WordPress') }}</a>
    </div>

    @if (session('status'))<div class="alert alert-ok" role="status"><x-icon name="check" />{{ session('status') }}</div>@endif
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if ($agentError)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __('The server could not be asked for the current state: :message', ['message' => $agentError]) }}</div>@endif
    @foreach ($status['problems'] ?? [] as $p)<div class="alert alert-warn" role="status"><x-icon name="info" />{{ $p }}</div>@endforeach

    @if ($status)
    <section class="card wph-hero">
        <x-ui.ring :value="$score" :tone="$tone" :size="120"><strong>{{ $on }}/{{ $total }}</strong><span>{{ __('on') }}</span></x-ui.ring>
        <div class="wph-hero-text">
            <p class="eyebrow">{{ __('Protection level') }}</p>
            <h2>
                @if ($on >= $total){{ __('This WordPress site is fully hardened') }}
                @elseif ($on === 0){{ __('No extra measures are on yet') }}
                @else{{ trans_choice(':count measure is still off|:count measures are still off', $total - $on) }}
                @endif
            </h2>
            <p class="muted">{{ __('All measures are recommended for almost every site. Each one can break a rare plugin; you see per measure what to watch for.') }}</p>
        </div>
        @if ($on < $total)
            <form method="POST" action="{{ $r('all') }}" class="wph-hero-action">@csrf
                <x-ui.button variant="primary" icon="shield">{{ __('Switch everything on') }}</x-ui.button>
            </form>
        @endif
    </section>

    <x-ui.card :title="__('Measures')" flush>
        <ul class="wph-list">
            @foreach ($definitions as $key => $d)
                @php $state = $m[$key] ?? ['on' => false]; $isOn = (bool) ($state['on'] ?? false); @endphp
                <li @class(['wph-item', 'is-on' => $isOn])>
                    <span class="wph-icon" aria-hidden="true"><x-icon :name="$d['icon']" /></span>
                    <div class="wph-text">
                        <h3>{{ __($d['title']) }}
                            @if ($isOn)<x-ui.pill status="ok">{{ __('On') }}</x-ui.pill>@else<x-ui.pill status="neutral">{{ __('Off') }}</x-ui.pill>@endif
                        </h3>
                        <p>{{ __($d['text']) }}</p>
                        <p class="muted small">{{ __($d['note']) }}</p>
                        @if ($key === 'file_edit' && ($state['via'] ?? null))
                            <p class="small">{{ __('Already off through :constant in wp-config.php.', ['constant' => $state['via']]) }}</p>
                        @endif
                        @if ($key === 'php_folders' && ($state['uploads_by_server'] ?? false) && ! $isOn)
                            <p class="small">{{ __('PHP in the uploads folder is already refused by the server for this site; this measure adds the system folders.') }}</p>
                        @endif
                        @if ($key === 'wp_config' && ($state['mode'] ?? null))
                            <p class="small">{{ __('Permissions now') }}: <code>{{ $state['mode'] }}</code>
                                @if (! ($state['private'] ?? false) && ($state['web_blocked'] ?? false)) · <span class="is-warn">{{ __('the permissions were changed after hardening') }}</span>@endif
                                @if (($state['location'] ?? '') === 'parent') · {{ __('wp-config.php is in the folder above WordPress') }}@endif
                            </p>
                        @endif
                    </div>
                    <form method="POST" action="{{ $r('update') }}" class="wph-toggle-form">
                        @csrf @method('PUT')
                        <input type="hidden" name="measure" value="{{ $key }}">
                        <input type="hidden" name="enabled" value="{{ $isOn ? 0 : 1 }}">
                        <button type="submit" role="switch" aria-checked="{{ $isOn ? 'true' : 'false' }}" @class(['wph-switch', 'is-on' => $isOn])
                                aria-label="{{ $isOn ? __('Switch off: :measure', ['measure' => __($d['title'])]) : __('Switch on: :measure', ['measure' => __($d['title'])]) }}">
                            <span class="wph-knob"></span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Security keys (salts)')">
            <p class="muted small">{{ __('WordPress signs login cookies with eight secret keys in wp-config.php. New keys log out everybody, also an attacker with a stolen cookie. Do this after a hack, after removing an administrator, or once a year.') }}</p>
            <dl class="kv">
                <dt>{{ __('Last renewed') }}</dt>
                <dd>{{ $refreshed ? $refreshed->isoFormat('LLL').' ('.$refreshed->diffForHumans().')' : __('Not through the panel') }}</dd>
            </dl>
            @if ($salts['default'] ?? false)
                <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('This site still uses the example keys of WordPress. Renew them now.') }}</div>
            @elseif (! ($salts['complete'] ?? true))
                <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('Some keys are missing in wp-config.php. Renewing adds them.') }}</div>
            @endif
            <div class="actions">
                <form method="POST" action="{{ $r('salts') }}" onsubmit="return confirm(@js(__('Renew the security keys? Everybody who is logged in, including you, has to log in again.')))">@csrf
                    <x-ui.button variant="dark" icon="refresh">{{ __('Renew keys') }}</x-ui.button>
                </form>
                @if ($salts['undo'] ?? false)
                    <form method="POST" action="{{ $r('salts') }}" onsubmit="return confirm(@js(__('Put the previous keys back? Logins from before the renewal work again.')))">@csrf
                        <input type="hidden" name="undo" value="1">
                        <button type="submit" class="btn">{{ __('Undo last renewal') }}</button>
                    </form>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Always on for every site')">
            <p class="muted small">{{ __('The server already does this for every website. There is nothing to switch.') }}</p>
            <ul class="checks">
                <li class="is-ok"><strong>{{ __('No directory listing') }}</strong><br><span class="muted small">{{ __('Folders without an index page never show their files.') }}</span></li>
                <li class="is-ok"><strong>{{ __('No PHP in wp-content/uploads') }}</strong><br><span class="muted small">{{ __('For WordPress in the main folder of the website.') }}</span></li>
                <li class="is-ok"><strong>{{ __('XML-RPC blocked') }}</strong><br><span class="muted small">{{ __('xmlrpc.php is a favourite target for password guessing.') }}</span></li>
                <li class="is-ok"><strong>{{ __('Hidden files and backups blocked') }}</strong><br><span class="muted small">{{ __('.env, .git, .sql, .bak and log files are never served.') }}</span></li>
            </ul>
        </x-ui.card>
    </section>
    @endif
</x-dynamic-component>
