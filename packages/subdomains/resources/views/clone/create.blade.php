@php
    $kindName = ['wordpress' => 'WordPress', 'joomla' => 'Joomla'];
    $installs = collect($plan['installs'] ?? []);
    $blocked = $installs->firstWhere('problem');
    $statusLabel = ['waiting' => __('Waiting'), 'running' => __('Cloning'), 'succeeded' => __('Done'), 'failed' => __('Failed')];
    $statusPill = ['waiting' => 'warn', 'running' => 'warn', 'succeeded' => 'ok', 'failed' => 'bad'];
    $defaultTarget = old('target', $target?->id ?? ($canAddSubdomain ? 'new' : $targets->first()?->id));
    $targetHasContent = $target && ! empty($plan['target_content']);
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Clone :domain', ['domain' => $website->domain])">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.websites.index') }}">{{ __('Websites') }}</a> / <a href="{{ route($area.'.websites.show', $website) }}">{{ $website->domain }}</a> / {{ __('Clone') }}</div>
            <h1>{{ __('Clone :domain', ['domain' => $website->domain]) }}</h1>
            <p class="sub">{{ __('A full working copy on another address: files, database and settings. Ideal to test an update or a new design first.') }}</p>
        </div>
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @error('target')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @isset($plan['error'])<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $plan['error'] }}</div>@endisset

    <div class="clone-layout">
        <form method="POST" action="{{ route($area.'.clones.store', $website) }}" class="form clone-form"
              x-data="{ target: @js((string) $defaultTarget), replace: @js((bool) old('replace', false)) }">
            @csrf
            <x-ui.card :title="__('Copy to')">
                <div class="clone-targets" role="radiogroup" aria-label="{{ __('Copy to') }}">
                    @if ($canAddSubdomain)
                        <label class="clone-target" :class="{ 'is-on': target === 'new' }">
                            <input type="radio" name="target" value="new" x-model="target">
                            <span class="clone-target-body">
                                <strong>{{ __('A new subdomain') }}</strong>
                                <span class="muted small">{{ __('Made for you, part of this subscription') }}</span>
                                <span class="sd-input" x-show="target === 'new'">
                                    <input class="input" name="new_label" value="{{ old('new_label', $website->isSubdomain() ? '' : 'test') }}" autocomplete="off" spellcheck="false" maxlength="63"
                                           pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?" aria-label="{{ __('Name of the subdomain') }}" @error('new_label') aria-invalid="true" @enderror>
                                    <span class="sd-suffix">.{{ $main->domain }}</span>
                                </span>
                                @error('new_label')<span class="error">{{ $message }}</span>@enderror
                            </span>
                        </label>
                    @endif
                    @foreach ($targets as $t)
                        <label class="clone-target" :class="{ 'is-on': target === @js((string) $t->id) }">
                            <input type="radio" name="target" value="{{ $t->id }}" x-model="target">
                            <span class="clone-target-body">
                                <strong>{{ $t->domain }}</strong>
                                <span class="muted small">
                                    @if ($t->isSubdomain()){{ __('Subdomain of :domain', ['domain' => $t->parent?->domain]) }}@elseif ($t->is($main) || $t->is($website->parent)){{ __('Your live website') }}@else{{ __('Website') }}@endif
                                    @if ($t->status !== 'active') · {{ __('Being set up') }}@endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                    @if (! $canAddSubdomain && $targets->isEmpty())
                        <x-ui.empty icon="globe">{{ __('There is no other website to copy to. Add a subdomain first.') }}</x-ui.empty>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Options')">
                <input type="hidden" name="noindex" value="0">
                <label class="check"><input type="checkbox" name="noindex" value="1" @checked(old('noindex', '1') === '1')>
                    <span>{{ __('Hide the copy from search engines') }}<small>{{ __('Google will not show the test address next to your real website (WordPress)') }}</small></span></label>

                <div x-show="target !== 'new'" class="clone-replace">
                    <input type="hidden" name="replace" value="0">
                    <label class="check"><input type="checkbox" name="replace" value="1" x-model="replace" @checked(old('replace'))>
                        <span>{{ __('Replace the website that is there now') }}<small>{{ __('All files and the cloned database on the target are overwritten') }}</small></span></label>
                    @error('replace')<span class="error">{{ $message }}</span>@enderror
                    @if ($targetHasContent)
                        <div class="alert alert-warn" role="alert"><x-icon name="info" />{{ __(':domain already has a website (:files). Make a backup first if you want to keep it.', ['domain' => $target->domain, 'files' => implode(', ', array_slice($plan['target_content'], 0, 4))]) }}</div>
                    @endif
                    <div x-show="replace">
                        <x-ui.field name="confirm" :label="__('Type the address of the target to confirm')" autocomplete="off" spellcheck="false" />
                    </div>
                </div>
            </x-ui.card>

            <div class="actions">
                <x-ui.button variant="primary" icon="migrate" :disabled="(bool) $blocked || isset($plan['error'])">{{ __('Start cloning') }}</x-ui.button>
                <a class="btn btn-ghost" href="{{ route($area.'.websites.show', $website) }}">{{ __('Cancel') }}</a>
            </div>
        </form>

        <aside class="clone-side">
            <x-ui.card :title="__('What is copied')">
                <dl class="kv kv-tight">
                    <dt>{{ __('Files') }}</dt><dd>{{ isset($plan['size_mb']) ? \Illuminate\Support\Number::fileSize($plan['size_mb'] * 1024 * 1024) : '–' }}</dd>
                    @forelse ($installs as $i)
                        <dt>{{ $kindName[$i['kind']] ?? $i['kind'] }} @if ($i['version'] ?? null)<span class="muted">{{ $i['version'] }}</span>@endif</dt>
                        <dd>/{{ $i['path'] }} · <span class="mono small">{{ $i['db_name'] ?? '–' }}</span>
                            @if ($i['problem'])<div class="error small">{{ __('Cannot be cloned: :problem', ['problem' => $i['problem']]) }}</div>@endif</dd>
                    @empty
                        <dt>{{ __('Database') }}</dt><dd class="muted">{{ __('No WordPress or Joomla found: only the files are copied') }}</dd>
                    @endforelse
                </dl>
            </x-ui.card>
            <x-ui.card :title="__('How it works')">
                <ol class="clone-steps small">
                    <li>{{ __('All files are copied. Caches are left out.') }}</li>
                    <li>{{ __('The database gets a copy of its own: the live website is never touched.') }}</li>
                    <li>{{ __('Addresses and folders are changed to the new address, also in the database.') }}</li>
                    <li>{{ __('WordPress gets new security keys: you log in again on the copy with the same account.') }}</li>
                </ol>
                <p class="small muted">{{ __('The copy can still send email, for example from contact forms or orders. Test with your own email address.') }}</p>
            </x-ui.card>
        </aside>
    </div>

    @if ($history->isNotEmpty())
        <x-ui.card :title="__('Earlier clones')" flush>
            <x-ui.table>
                <x-slot:head><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Status') }}</th><th>{{ __('Started') }}</th></x-slot:head>
                @foreach ($history as $c)
                    <tr>
                        <td>{{ $c->source_domain }}</td>
                        <td><a href="{{ route($area.'.clones.show', $c) }}">{{ $c->target_domain }}</a></td>
                        <td><x-ui.pill :status="$statusPill[$c->status] ?? 'neutral'">{{ $statusLabel[$c->status] ?? $c->status }}</x-ui.pill></td>
                        <td class="small muted">{{ $c->created_at?->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
    @include('subdomains::partials.styles')
</x-dynamic-component>
