{{-- Smart update settings and history of one WordPress install. --}}
@php
    use Rhis\WordPress\SmartUpdates\Models\UpdatePolicy;
    $tz = config('server-admin.timezone') ?: config('app.timezone');
    $mode = old('mode', $policy->mode ?? 'default');
    $threshold = (int) old('threshold', $policy->threshold ?? $options->get('threshold'));
    $running = $runs->firstWhere('status', 'running');
    $pagesText = implode("\n", $policy->pages ?? []);
@endphp
<div class="su-page">
<div class="page-head">
    <div>
        <p class="small" style="margin:0 0 4px"><a href="{{ route($area.'.wordpress.smart.index') }}">{{ __('Smart updates') }}</a></p>
        <h1>{{ $install->label() }}</h1>
        <p class="sub">WordPress {{ $install->version ?? '?' }}
            @if ($install->isOutdated()) · {{ trans_choice(':count update waiting|:count updates waiting', $install->outdatedCount()) }}@else · {{ __('Up to date') }}@endif
            @if ($area === 'admin' && $install->website->customer) · {{ $install->website->customer->displayName() }}@endif</p>
    </div>
    <div class="actions">
        <x-ui.button icon="wordpress" :href="route($area.'.wordpress.show', $install)">{{ __('Manage WordPress') }}</x-ui.button>
        @if ($running)
            <x-ui.button variant="primary" icon="refresh" :href="route($area.'.wordpress.smart.runs.show', $running)">{{ __('Update running…') }}</x-ui.button>
        @else
            <form method="POST" action="{{ route($area.'.wordpress.smart.run', $install) }}" onsubmit="return confirm(@js(__('Update everything now, with the screenshot check? A restore point is made first.')))">
                @csrf<x-ui.button variant="primary" icon="refresh">{{ __('Update now') }}</x-ui.button>
            </form>
        @endif
    </div>
</div>

@if (session('status'))<div class="alert alert-ok" role="status"><x-icon name="check" /><div>{{ session('status') }}</div></div>@endif
@if ($errors->has('agent'))<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $errors->first('agent') }}</div></div>@endif

<div class="su-two">
    <x-ui.card :title="__('Automatic updates')">
        @if (! $mayChange)
            <div class="alert alert-info" style="margin-bottom:16px"><x-icon name="info" /><div>{{ __('Your hosting provider manages this setting. Ask them if you want it changed.') }}</div></div>
        @endif
        <form method="POST" action="{{ route($area.'.wordpress.smart.save', $install) }}" style="display:grid;gap:18px">
            @csrf @method('PUT')
            <fieldset class="field" style="border:0;padding:0;margin:0" @disabled(! $mayChange)>
                <legend class="label">{{ __('What may be updated automatically?') }}</legend>
                <div class="su-modes" role="radiogroup">
                    <label><input type="radio" name="mode" value="default" @checked($mode === 'default')>
                        <span class="box"><span class="su-mode su-mode-{{ $options->get('default_mode') }}"><span class="ic"><x-icon name="settings" /></span><span>{{ __('Panel default') }}<small>{{ UpdatePolicy::modeLabel($options->get('default_mode')) }}</small></span></span>
                            <p>{{ __('Follows the setting of your hosting provider.') }}</p></span></label>
                    @foreach (UpdatePolicy::MODES as $m)
                        <label><input type="radio" name="mode" value="{{ $m }}" @checked($mode === $m)>
                            <span class="box">@include('wordpress::smart-updates.partials.mode', ['policy' => new UpdatePolicy(['mode' => $m])])
                                <p>{{ UpdatePolicy::modeHelp($m) }}</p></span></label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset style="border:0;padding:0;margin:0;display:grid;gap:16px" @disabled(! $mayChange)>
                <label class="check"><input type="checkbox" name="visual_check" value="1" @checked(old('visual_check', $policy->visual_check))>
                    <span>{{ __('Check with screenshots') }}<small>{{ __('Compare the website before and after the update, on desktop and mobile, and roll back when it looks different.') }}</small></span></label>
                <div class="field">
                    <label for="f-threshold">{{ __('Allowed visual difference') }}</label>
                    <div class="su-threshold" x-data="{ v: {{ $threshold }} }">
                        <input id="f-threshold" type="range" name="threshold" min="1" max="50" x-model="v" value="{{ $threshold }}">
                        <output x-text="v + '%'">{{ $threshold }}%</output>
                    </div>
                    <span class="help">{{ __('Higher for sites with a lot of changing content (news, shop offers), lower for sites that rarely change.') }}</span>
                </div>
                <x-ui.field name="pages" type="textarea" rows="3" :label="__('Extra pages to check')" :value="$pagesText" placeholder="/contact, /shop" :help="__('The home page is always checked. Up to :n extra pages, one per line, for example your shop or contact page.', ['n' => UpdatePolicy::MAX_PAGES])" />
                <x-ui.field name="mail" type="select" :label="__('Mail me')">
                    @foreach (['always' => __('After every update'), 'problems' => __('Only about problems and waiting updates'), 'never' => __('Never')] as $k => $label)
                        <option value="{{ $k }}" @selected(old('mail', $policy->mail) === $k)>{{ $label }}</option>
                    @endforeach
                </x-ui.field>
            </fieldset>
            @if ($mayChange)<div><x-ui.button variant="primary" icon="check">{{ __('Save') }}</x-ui.button></div>@endif
        </form>
    </x-ui.card>

    <div class="su-side">
        <x-ui.card :title="__('Next run')">
            <dl class="kv">
                <dt>{{ __('Setting') }}</dt><dd>{{ UpdatePolicy::modeLabel($policy->effectiveMode()) }}</dd>
                <dt>{{ __('When') }}</dt><dd>{{ in_array($policy->effectiveMode(), ['off'], true) ? '–' : ($summary['next_window']?->timezone($tz)->isoFormat('dd D MMM HH:mm') ?? '–') }}</dd>
                <dt>{{ __('Pages') }}</dt><dd>{{ implode(', ', $policy->pageList()) }}</dd>
            </dl>
            @if ($policy->effectiveMode() === 'security')
                <p class="small" style="margin:14px 0 6px"><strong>{{ __('Security updates waiting') }}</strong></p>
                @php $sec = array_merge($security['plugins'], $security['themes']); @endphp
                @if ($sec)
                    <ul class="su-mini">@foreach ($sec as $slug)<li><span>{{ $slug }}</span><x-ui.pill status="bad">{{ __('Vulnerable') }}</x-ui.pill></li>@endforeach</ul>
                @else
                    <p class="muted small" style="margin:0">{{ __('No plugin or theme with a known vulnerability. Security releases of WordPress itself are installed as they come.') }}</p>
                @endif
            @endif
        </x-ui.card>
        <x-ui.card :title="__('Recent updates')">
            @if ($runs->isEmpty())
                <p class="muted small" style="margin:0">{{ __('No smart updates yet.') }}</p>
            @else
                <ul class="su-mini">
                    @foreach ($runs as $r)
                        <li><a href="{{ route($area.'.wordpress.smart.runs.show', $r) }}">{{ $r->started_at?->timezone($tz)->isoFormat('D MMM HH:mm') }}
                            <span class="muted small">· {{ trans_choice(':count part|:count parts', $r->changeCount()) }}</span></a>
                            <x-ui.pill :status="$r->pill()">{{ $r->statusLabel() }}</x-ui.pill></li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</div>
</div>
