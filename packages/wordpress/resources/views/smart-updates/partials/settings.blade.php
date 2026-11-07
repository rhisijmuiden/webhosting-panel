{{-- Admin settings of the smart updates. --}}
@php use Rhis\WordPress\SmartUpdates\Models\UpdatePolicy; @endphp
<div class="su-two">
<x-ui.card :title="__('Settings')">
    <form method="POST" action="{{ route('admin.wordpress.smart.settings') }}" class="stack" style="display:grid;gap:18px">
        @csrf
        <fieldset class="field" style="border:0;padding:0;margin:0">
            <legend class="label">{{ __('Default for sites without their own choice') }}</legend>
            <div class="su-modes" role="radiogroup">
                @foreach (UpdatePolicy::MODES as $m)
                    <label><input type="radio" name="default_mode" value="{{ $m }}" @checked(old('default_mode', $options->get('default_mode')) === $m)>
                        <span class="box">@include('wordpress::smart-updates.partials.mode', ['policy' => new UpdatePolicy(['mode' => $m])])
                            @if ($m === 'security')<span class="rec">{{ __('Recommended') }}</span>@endif
                            <p>{{ UpdatePolicy::modeHelp($m) }}</p></span></label>
                @endforeach
            </div>
        </fieldset>
        <div class="form-grid">
            <div class="field">
                <label for="f-threshold">{{ __('Allowed visual difference') }}</label>
                <div class="su-threshold" x-data="{ v: {{ (int) old('threshold', $options->get('threshold')) }} }">
                    <input id="f-threshold" type="range" name="threshold" min="1" max="50" x-model="v" value="{{ (int) old('threshold', $options->get('threshold')) }}">
                    <output x-text="v + '%'">{{ (int) old('threshold', $options->get('threshold')) }}%</output>
                </div>
                <span class="help">{{ __('When more of a page than this looks different after an update, it is rolled back. 10% suits most sites.') }}</span>
            </div>
            <x-ui.field name="concurrency" type="number" min="1" max="6" :label="__('Sites updated at the same time')" :value="$options->get('concurrency')" :help="__('More is faster but puts more load on the server at night.')" />
        </div>
        <label class="check"><input type="checkbox" name="visual" value="1" @checked(old('visual', $options->get('visual')))>
            <span>{{ __('Compare screenshots before and after every update') }}<small>{{ __('Off: only the health check (an error page after the update) rolls back.') }}</small></span></label>
        <label class="check"><input type="checkbox" name="customer_policy" value="1" @checked(old('customer_policy', $options->get('customer_policy')))>
            <span>{{ __('Customers may choose the setting of their own sites') }}<small>{{ __('Customers always see the results and get the mails.') }}</small></span></label>
        <label class="check"><input type="checkbox" name="admin_notify" value="1" @checked(old('admin_notify', $options->get('admin_notify')))>
            <span>{{ __('Mail the admins when an update was rolled back or failed') }}</span></label>
        <x-ui.field name="admin_mail_to" :label="__('Send those mails to')" :value="$options->get('admin_mail_to')" placeholder="beheer@rhis.nl" :help="__('Comma separated. Empty: all active admins.')" />
        <div><x-ui.button variant="primary" icon="check">{{ __('Save settings') }}</x-ui.button></div>
    </form>
</x-ui.card>

<div class="su-side">
    <x-ui.card :title="__('Maintenance window')">
        <dl class="kv">
            <dt>{{ __('Window') }}</dt><dd>{{ $summary['window'] }}</dd>
            <dt>{{ __('Next') }}</dt><dd>{{ $summary['next_window']?->isoFormat('dd D MMM HH:mm') ?? '–' }}</dd>
            <dt>{{ __('Source') }}</dt><dd>{{ $summary['server_window'] ? __('Server management') : __('Own window (server management window is off)') }}</dd>
        </dl>
        <p class="muted small" style="margin:12px 0 0">{{ __('Updates start after the server\'s own updates and reboot of that window are done, and no new update starts in the last 15 minutes.') }}</p>
        @if (\Illuminate\Support\Facades\Route::has('admin.server.maintenance'))
            <p style="margin:12px 0 0"><x-ui.button size="sm" icon="clock" :href="route('admin.server.maintenance')">{{ __('Change the maintenance window') }}</x-ui.button></p>
        @endif
    </x-ui.card>
    <x-ui.card :title="__('Screenshots on this server')">
        @if ($capabilities === null)
            <p class="muted small" style="margin:0">{{ __('The server agent could not be reached.') }}</p>
        @else
            <ul class="su-checks">
                @foreach (['chromium' => __('Headless Chromium'), 'pillow' => __('Image comparison (Pillow)'), 'user' => __('Sandboxed user whp-shot')] as $k => $label)
                    <li><span class="{{ $capabilities[$k] ? 'ok' : 'bad' }}"><x-icon :name="$capabilities[$k] ? 'check' : 'close'" /></span>{{ $label }}</li>
                @endforeach
            </ul>
            @unless ($capabilities['visual'])
                <p class="muted small" style="margin:10px 0 0">{{ __('Missing parts are installed with the next panel update. Until then only the health check guards the updates.') }}</p>
            @endunless
        @endif
    </x-ui.card>
</div>
</div>
