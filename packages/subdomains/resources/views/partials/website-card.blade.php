{{-- Shown on a website's page (admin and client): its subdomains and the clone tool. --}}
@php
    $sdArea = request()->routeIs('admin.*') ? 'admin' : 'client';
    $sdManager = app(\Rhis\Subdomains\SubdomainManager::class);
    $sdCanManage = $sdArea === 'admin' || config('subdomains.customer_manage');
    $sdCanClone = $sdArea === 'admin' || config('subdomains.customer_clone');
    $sdParent = $website->isSubdomain() ? $website->parent : null;
    $sdList = $sdParent ? collect() : $website->subdomains()->get();
    $sdBlocker = $sdParent ? null : $sdManager->blocker($website);
    $sdLimit = $sdManager->limit($website);
    $sdStatus = ['provisioning' => __('Setting up'), 'active' => __('Online'), 'failed' => __('Failed'), 'removing' => __('Removing')];
@endphp
<x-ui.card :title="$sdParent ? __('Subdomain') : __('Subdomains')" id="subdomains">
    <x-slot:actions>
        @if ($sdCanClone && $website->status === 'active')
            <a class="btn btn-sm" href="{{ route($sdArea.'.clones.create', $website) }}"><x-icon name="migrate" />{{ __('Clone website') }}</a>
        @endif
    </x-slot:actions>
    @if ($sdList->contains(fn ($s) => in_array($s->status, ['provisioning', 'removing'], true)))<meta http-equiv="refresh" content="5">@endif
    @error('subdomain')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror

    @if ($sdParent)
        <p class="small">{{ __('This is a subdomain of :domain. It is part of that subscription: disk space and limits are shared.', ['domain' => $sdParent->domain]) }}</p>
        <div class="actions">
            <a class="btn btn-sm btn-ghost" href="{{ route($sdArea.'.websites.show', $sdParent) }}">{{ __('Go to :domain', ['domain' => $sdParent->domain]) }}</a>
            @if ($sdCanClone && $website->status === 'active' && $sdParent->status === 'active')
                <a class="btn btn-sm" href="{{ route($sdArea.'.clones.create', [$website, 'target' => $sdParent->id]) }}"><x-icon name="upload" />{{ __('Copy to :domain', ['domain' => $sdParent->domain]) }}</a>
            @endif
        </div>
    @else
        <p class="muted small">{{ __('Test a change on test.:domain before it goes live, or run a shop or blog on its own address. Subdomains are part of this subscription: no extra package needed.', ['domain' => $website->domain]) }}</p>
        @if ($sdList->isNotEmpty())
            <x-ui.table>
                <x-slot:head><th>{{ __('Subdomain') }}</th><th>{{ __('Status') }}</th><th class="num"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                @foreach ($sdList as $s)
                    <tr>
                        <td><a class="strong" href="{{ route($sdArea.'.websites.show', $s) }}">{{ $s->domain }}</a>
                            <div class="muted small">{{ $s->profile === 'wordpress' ? 'WordPress' : __(ucfirst($s->profile ?? 'php')) }} · PHP {{ $s->php_version }}</div></td>
                        <td><x-ui.pill :status="$s->status">{{ $sdStatus[$s->status] ?? $s->status }}</x-ui.pill>
                            @if ($s->status === 'active')<x-ui.pill :status="$s->ssl_status">{{ $s->ssl_status === 'active' ? __('SSL valid') : __('SSL :status', ['status' => __($s->ssl_status)]) }}</x-ui.pill>@endif
                            @if ($s->status === 'failed' && $s->last_error)<div class="small mono muted">{{ \Illuminate\Support\Str::limit($s->last_error, 160) }}</div>@endif</td>
                        <td class="num">
                            <div class="actions" style="justify-content:flex-end">
                                @if ($s->status === 'active')
                                    <a class="btn btn-sm btn-ghost" href="https://{{ $s->domain }}" target="_blank" rel="noopener" title="{{ __('Visit website') }}"><x-icon name="external" /><span class="sr-only">{{ __('Visit website') }}</span></a>
                                    @if ($sdCanClone && $website->status === 'active')
                                        <a class="btn btn-sm" href="{{ route($sdArea.'.clones.create', [$website, 'target' => $s->id]) }}"><x-icon name="migrate" />{{ __('Clone into') }}</a>
                                    @endif
                                @endif
                                @if ($sdCanManage && in_array($s->status, ['active', 'failed'], true))
                                    <details class="sd-remove">
                                        <summary class="btn btn-sm btn-ghost" title="{{ __('Remove') }}"><x-icon name="trash" /><span class="sr-only">{{ __('Remove :domain', ['domain' => $s->domain]) }}</span></summary>
                                        <form method="POST" action="{{ route($sdArea.'.subdomains.destroy', [$website, $s]) }}" class="form sd-remove-form">
                                            @csrf @method('DELETE')
                                            <p class="small">{{ __('All files and databases of :domain are deleted. This cannot be undone.', ['domain' => $s->domain]) }}</p>
                                            <x-ui.field name="confirm" :label="__('Type :domain to confirm', ['domain' => $s->domain])" autocomplete="off" required />
                                            <div><button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" />{{ __('Remove subdomain') }}</button></div>
                                        </form>
                                    </details>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
        @if ($sdCanManage && ! $sdBlocker)
            <form method="POST" action="{{ route($sdArea.'.subdomains.store', $website) }}" class="form sd-add">
                @csrf
                <div class="field">
                    <label for="sd-label">{{ __('New subdomain') }}</label>
                    <div class="sd-input"><input id="sd-label" class="input" name="label" value="{{ old('label') }}" placeholder="test" autocomplete="off" spellcheck="false" maxlength="63" required
                        pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?" @error('label') aria-invalid="true" @enderror><span class="sd-suffix">.{{ $website->domain }}</span></div>
                    @error('label')<span class="error">{{ $message }}</span>@enderror
                </div>
                <x-ui.field name="profile" type="select" :label="__('Type')">
                    <option value="php">{{ __('Website (PHP)') }}</option>
                    <option value="wordpress" @selected(old('profile', $website->profile) === 'wordpress')>WordPress</option>
                    <option value="laravel" @selected(old('profile') === 'laravel')>Laravel</option>
                </x-ui.field>
                <div class="sd-add-btn"><x-ui.button variant="primary" icon="plus">{{ __('Add subdomain') }}</x-ui.button></div>
            </form>
            <p class="small muted">{{ __('The address works within a minute, with its own SSL certificate. With RHIS DNS the DNS record is added for you.') }}
                @if ($sdLimit !== null) {{ __(':used of :limit used', ['used' => $sdList->count(), 'limit' => $sdLimit]) }}@endif</p>
        @elseif ($sdBlocker && $website->status === 'active')
            <p class="small muted">{{ $sdBlocker }}</p>
        @endif
    @endif
</x-ui.card>
@include('subdomains::partials.styles')
