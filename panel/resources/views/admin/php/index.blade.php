<x-layouts.admin :title="__('PHP settings')">
    <div class="page-head">
        <div><h1>{{ __('PHP settings') }}</h1><p class="sub">{{ __('Fine-tune PHP for the whole server and install extra extensions.') }}</p></div>
    </div>
    <div class="actions" style="margin-bottom:16px">
        <a href="{{ route('admin.php.index') }}" @class(['btn', 'btn-dark' => $tab === 'settings', 'btn-ghost' => $tab !== 'settings']) @if ($tab === 'settings') aria-current="page" @endif>{{ __('Fine-tuning') }}</a>
        <a href="{{ route('admin.php.index', ['tab' => 'extensions', 'php' => $version]) }}" @class(['btn', 'btn-dark' => $tab === 'extensions', 'btn-ghost' => $tab !== 'extensions']) @if ($tab === 'extensions') aria-current="page" @endif>{{ __('Extensions') }}</a>
    </div>

    @if ($tab === 'settings')
    <form method="POST" action="{{ route('admin.php.update') }}" class="form">
        @csrf @method('PUT')
        <x-ui.card :title="__('Limits per website')">
            <div class="section-note"><x-icon name="info" /><div>{{ __('The default is what every website gets. Customers can change the value of their own website in the client panel, up to the maximum. The values are set as php_admin_value, so scripts cannot raise them, and the Nginx upload size and timeouts follow automatically.') }}</div></div>
            <div class="php-grid">
                <span class="head name-head">{{ __('Setting') }}</span><span class="head">{{ __('Default') }}</span><span class="head">{{ __('Maximum for customers') }}</span>
                @foreach (\App\Services\PhpSettings::KEYS as $key => [$label, $unit, , , $lowest, $highest])
                    <div class="name"><strong>{{ __($label) }}</strong><code>{{ $key }}</code></div>
                    @foreach (['default', 'max'] as $col)
                        @php $field = "{$key}[{$col}]"; $err = $errors->first("$key.$col"); @endphp
                        <div>
                            <span class="input-unit"><input class="input" type="number" name="{{ $field }}" min="{{ $lowest }}" max="{{ $highest }}" required
                                value="{{ old("$key.$col", $server[$key][$col]) }}" aria-label="{{ __($label) }} {{ $col === 'max' ? __('maximum') : __('default') }}" @if ($err) aria-invalid="true" @endif>
                                @if ($unit)<span class="unit">{{ $unit }}</span>@endif</span>
                            @if ($err)<span class="error small">{{ $err }}</span>@endif
                        </div>
                    @endforeach
                @endforeach
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Workers and errors')">
            <div class="form-grid">
                <x-ui.field name="workers" type="number" min="1" max="999" :label="__('PHP workers per website')" :value="$server['workers']" required
                    :help="__('Requests one website handles at the same time, unless its package sets its own number.')" />
            </div>
            <label class="check"><input type="checkbox" name="display_errors" value="1" @checked(old('display_errors', $server['display_errors']))><span>{{ __('Show PHP errors in the browser by default') }}<small>{{ __('Leave this off for production websites: errors can show paths and passwords. Errors are always written to the error log of the website.') }}</small></span></label>
            <label class="check"><input type="checkbox" name="customer_display_errors" value="1" @checked(old('customer_display_errors', $server['customer_display_errors']))><span>{{ __('Customers may switch error display on for their own website') }}</span></label>
        </x-ui.card>
        <div class="actions"><button type="submit" class="btn btn-primary">{{ __('Save and apply to all websites') }}</button></div>
    </form>
    @else
        @if (session('php_job'))
            <div class="alert alert-info" role="status" x-data="{ s: 'running', log: [], async poll() {
                    try { const r = await fetch(@js(route('admin.system.job', session('php_job'))), { headers: { Accept: 'application/json' } });
                        const j = await r.json(); this.s = j.status || 'failed'; this.log = (j.log || []).slice(-6);
                        if (this.s === 'running') setTimeout(() => this.poll(), 2000); else setTimeout(() => location.reload(), 1200);
                    } catch (e) { setTimeout(() => this.poll(), 4000); } } }" x-init="poll()">
                <x-icon name="refresh" /><div><strong x-text="s === 'running' ? @js(__('Working… this can take a few minutes for PECL extensions.')) : (s === 'succeeded' ? @js(__('Done.')) : @js(__('That did not work: see the log below.')))"></strong>
                <pre class="log small" x-show="log.length" x-text="log.join('\n')"></pre></div>
            </div>
        @endif
        <div class="actions" style="margin-bottom:16px; justify-content:space-between">
            <div class="seg" role="tablist" aria-label="{{ __('PHP version') }}">
                @foreach (\App\Models\Website::PHP_VERSIONS as $v)
                    <a href="{{ route('admin.php.index', ['tab' => 'extensions', 'php' => $v]) }}" style="text-decoration:none"><label><input type="radio" @checked($v === $version) tabindex="-1"><span>PHP {{ $v }} <span class="small" style="margin-left:6px; opacity:.7">{{ trans_choice(':count site|:count sites', $sitesPerVersion[$v] ?? 0) }}</span></span></label></a>
                @endforeach
            </div>
        </div>
        @if ($error)
            <div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ __('The extension list could not be loaded: :error', ['error' => $error]) }}</div></div>
        @else
        <section class="grid grid-ext" x-data="{ q: @js($q), only: 'all' }">
            <x-ui.card :title="__('Debian packages')" flush>
                <x-slot:actions><span class="small muted">{{ __('Recommended: updated with the security updates') }}</span></x-slot:actions>
                <div style="padding:12px 16px; display:flex; gap:10px; flex-wrap:wrap">
                    <label class="search"><x-icon name="search" /><input type="search" x-model="q" placeholder="{{ __('Search extensions') }}" aria-label="{{ __('Search extensions') }}"></label>
                    <div class="seg"><label><input type="radio" value="all" x-model="only"><span>{{ __('All') }}</span></label><label><input type="radio" value="installed" x-model="only"><span>{{ __('Installed') }}</span></label></div>
                </div>
                <x-ui.table>
                    <x-slot:head><th>{{ __('Extension') }}</th><th>{{ __('Status') }}</th><th></th></x-slot:head>
                    @foreach ($extensions['extensions'] as $ext)
                        <tr x-show="(only === 'all' || @js($ext['installed'])) && (!q || @js(strtolower($ext['name'].' '.$ext['description'])).includes(q.toLowerCase()))">
                            <td><strong>{{ $ext['name'] }}</strong>@if ($ext['source'] === 'pecl') <x-ui.pill status="info">PECL</x-ui.pill>@endif<div class="small muted">{{ $ext['description'] }}</div></td>
                            <td>@if (! $ext['installed'])<x-ui.pill status="neutral">{{ __('Not installed') }}</x-ui.pill>
                                @elseif ($ext['loaded'])<x-ui.pill status="ok">{{ __('On') }}</x-ui.pill>
                                @else<x-ui.pill status="warn">{{ __('Off') }}</x-ui.pill>@endif</td>
                            <td class="num">
                                @if (! $ext['installed'])
                                    <form method="POST" action="{{ route('admin.php.extensions.install') }}">@csrf<input type="hidden" name="php" value="{{ $version }}"><input type="hidden" name="name" value="{{ $ext['name'] }}"><input type="hidden" name="source" value="apt">
                                        <button class="btn btn-sm" type="submit"><x-icon name="plus" />{{ __('Install') }}</button></form>
                                @elseif (! $ext['core'])
                                    <div class="actions" style="justify-content:flex-end; flex-wrap:nowrap">
                                        <form method="POST" action="{{ route('admin.php.extensions.toggle') }}">@csrf<input type="hidden" name="php" value="{{ $version }}"><input type="hidden" name="name" value="{{ $ext['name'] }}"><input type="hidden" name="enabled" value="{{ $ext['loaded'] ? 0 : 1 }}">
                                            <button class="btn btn-sm btn-ghost" type="submit">{{ $ext['loaded'] ? __('Switch off') : __('Switch on') }}</button></form>
                                        <form method="POST" action="{{ route('admin.php.extensions.remove') }}" data-confirm="{{ __('Remove :name from PHP :php? Websites that use it stop working.', ['name' => $ext['name'], 'php' => $version]) }}">@csrf @method('DELETE')<input type="hidden" name="php" value="{{ $version }}"><input type="hidden" name="name" value="{{ $ext['name'] }}">
                                            <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Remove') }} {{ $ext['name'] }}"><x-icon name="trash" /></button></form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
            <x-ui.card :title="__('PECL extensions')">
                <div x-data="{ term: '', busy: false, results: null, err: null, async find() {
                        if (this.term.length < 2) return; this.busy = true; this.err = null;
                        try { const r = await fetch(@js(route('admin.php.extensions.search')) + '?q=' + encodeURIComponent(this.term), { headers: { Accept: 'application/json' } });
                            const j = await r.json(); if (!r.ok) throw new Error(j.error?.message || j.message || r.status); this.results = j.results; }
                        catch (e) { this.err = e.message; } finally { this.busy = false; } } }">
                    <p class="muted small">{{ __('For extensions without a Debian package. They are compiled for PHP :php on this server and are not updated automatically: check for updates now and then.', ['php' => $version]) }}</p>
                    <form class="actions" @submit.prevent="find()">
                        <label class="search"><x-icon name="search" /><input type="search" x-model="term" pattern="[A-Za-z0-9_]{2,40}" placeholder="{{ __('e.g. swoole, mailparse') }}" aria-label="{{ __('Search PECL') }}"></label>
                        <button class="btn btn-dark" type="submit" :disabled="busy">{{ __('Search') }}</button>
                    </form>
                    <p class="error small" x-show="err" x-text="err"></p>
                    <template x-if="results && !results.length"><p class="muted small">{{ __('Nothing found on pecl.php.net.') }}</p></template>
                    <template x-for="r in results || []" :key="r.name">
                        <div class="row" style="align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid var(--line-soft)">
                            <span><strong x-text="r.name"></strong> <span class="muted small" x-text="r.version"></span><span class="small muted" style="display:block" x-text="r.summary"></span></span>
                            <form method="POST" action="{{ route('admin.php.extensions.install') }}">@csrf<input type="hidden" name="php" value="{{ $version }}"><input type="hidden" name="name" :value="r.name"><input type="hidden" name="source" value="pecl">
                                <button class="btn btn-sm" type="submit"><x-icon name="plus" />{{ __('Install') }}</button></form>
                        </div>
                    </template>
                </div>
            </x-ui.card>
        </section>
        @endif
    @endif
</x-layouts.admin>
