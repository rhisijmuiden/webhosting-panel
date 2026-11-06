@php
    $sortLink = function (string $key) use ($sort, $dir) {
        $next = $sort === $key && $dir === 'asc' ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => null]);
    };
    $arrow = fn (string $key) => $sort === $key ? ($dir === 'asc' ? '↑' : '↓') : '';
    $aria = fn (string $key) => $sort === $key ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $ids = $subscriptions->pluck('id')->values();
@endphp
<x-layouts.admin :title="__('Subscriptions')">
    <link rel="stylesheet" href="{{ asset('assets/subscriptions.css') }}?v={{ @filemtime(public_path('assets/subscriptions.css')) }}">
    <div class="page-head">
        <div>
            <h1>{{ __('Subscriptions') }}</h1>
            <p class="sub">{{ __('Every website is a subscription: one domain on one hosting package, with an owner, a setup date and an optional expiry date. The limits of the package apply to that website alone.') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.subscriptions.bulk') }}" class="subs" id="subs-form"
          x-data="{ selected: [], ids: @js($ids), get all() { return this.ids.length > 0 && this.selected.length === this.ids.length }, toggleAll(on) { this.selected = on ? [...this.ids] : [] }, open(name) { this.$refs[name].showModal() } }">
        @csrf
        <div class="subs-toolbar">
            <div class="subs-actions" role="toolbar" aria-label="{{ __('Actions') }}">
                <x-ui.button variant="primary" icon="plus" :href="route('admin.websites.create', array_filter(['customer' => $filterCustomer?->id]))">{{ __('Add subscription') }}</x-ui.button>
                <button type="button" class="btn" :disabled="!selected.length" @click="open('dlgPackage')"><x-icon name="package" />{{ __('Change package') }}</button>
                <button type="button" class="btn" :disabled="!selected.length" @click="open('dlgOwner')"><x-icon name="user" />{{ __('Change owner') }}</button>
                <div class="dropdown" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape="menu = false">
                    <button type="button" class="btn" :disabled="!selected.length" @click="menu = !menu" :aria-expanded="menu" aria-haspopup="menu">{{ __('Set status') }} <span aria-hidden="true">▾</span></button>
                    <div class="dropdown-menu" role="menu" x-show="menu" x-cloak>
                        <button type="submit" name="action" value="activate" role="menuitem"><x-ui.pill status="active">{{ __('Activate') }}</x-ui.pill></button>
                        <button type="submit" name="action" value="suspend" role="menuitem"><x-ui.pill status="suspended">{{ __('Suspend') }}</x-ui.pill></button>
                    </div>
                </div>
                <button type="button" class="btn" :disabled="!selected.length" @click="open('dlgExpiry')"><x-icon name="refresh" />{{ __('Expiry date') }}</button>
                <button type="button" class="btn subs-danger" :disabled="!selected.length" @click="open('dlgRemove')"><x-icon name="trash" />{{ __('Remove') }}</button>
            </div>
            <div class="subs-search">
                <label class="search" role="search"><x-icon name="search" />
                    <input type="search" form="subs-search" name="q" value="{{ $search }}" placeholder="{{ __('Domain, owner or package') }}" aria-label="{{ __('Search') }}"></label>
            </div>
        </div>

        @if ($filterCustomer || request('package') || request('status'))
            <p class="small subs-filters">{{ __('Filtered') }}:
                @if ($filterCustomer)<span class="pill pill-info">{{ $filterCustomer->displayName() }}</span>@endif
                @if (request('package'))<span class="pill pill-info">{{ request('package') === 'none' ? __('No package') : ($packages->firstWhere('id', (int) request('package'))?->name ?? '?') }}</span>@endif
                @if (request('status'))<span class="pill pill-info">{{ __(ucfirst(request('status'))) }}</span>@endif
                <a href="{{ route('admin.subscriptions.index') }}">{{ __('Show all') }}</a></p>
        @endif

        <div class="subs-meta small muted">
            <span>{{ trans_choice(':count item in total|:count items in total', $subscriptions->total()) }}<template x-if="selected.length"><span> · <strong x-text="selected.length"></strong> {{ __('selected') }}</span></template></span>
            <span class="subs-per">{{ __('Items per page') }}:
                @foreach ([10, 25, 100, 'all'] as $n)
                    @if ($per === $n)<strong aria-current="true">{{ $n === 'all' ? __('All') : $n }}</strong>@else<a href="{{ request()->fullUrlWithQuery(['per' => $n, 'page' => null]) }}">{{ $n === 'all' ? __('All') : $n }}</a>@endif
                @endforeach
            </span>
        </div>

        <x-ui.card flush>
            @if ($subscriptions->isEmpty())
                <x-ui.empty icon="apps">{{ $search ? __('No subscriptions match your search.') : __('No subscriptions yet.') }}</x-ui.empty>
            @else
            <x-ui.table class="subs-table">
                <x-slot:head>
                    <th class="subs-check"><input type="checkbox" :checked="all" @change="toggleAll($event.target.checked)" aria-label="{{ __('Select all') }}"></th>
                    <th aria-sort="{{ $aria('domain') }}"><a href="{{ $sortLink('domain') }}">{{ __('Subscription') }} {{ $arrow('domain') }}</a></th>
                    <th aria-sort="{{ $aria('owner') }}"><a href="{{ $sortLink('owner') }}">{{ __('Subscription holder') }} {{ $arrow('owner') }}</a></th>
                    <th aria-sort="{{ $aria('created') }}"><a href="{{ $sortLink('created') }}">{{ __('Setup date') }} {{ $arrow('created') }}</a></th>
                    <th aria-sort="{{ $aria('expires') }}"><a href="{{ $sortLink('expires') }}">{{ __('Expiry date') }} {{ $arrow('expires') }}</a></th>
                    <th><span class="sr-only">{{ __('Open') }}</span></th>
                </x-slot:head>
                @foreach ($subscriptions as $s)
                    @php $w = $s->website; $state = $w && in_array($w->status, ['provisioning', 'failed', 'removing'], true) ? $w->status : $s->status; @endphp
                    <tr :class="selected.includes({{ $s->id }}) && 'is-selected'">
                        <td class="subs-check"><input type="checkbox" name="ids[]" value="{{ $s->id }}" x-model.number="selected" aria-label="{{ __('Select :domain', ['domain' => $w?->domain]) }}"></td>
                        <td>
                            <div class="subs-name">
                                <x-ui.pill :status="$state" class="subs-state" :title="$s->suspended_reason ? __($s->suspended_reason) : null">{{ __(ucfirst($state)) }}</x-ui.pill>
                                <a class="strong" href="{{ route('admin.subscriptions.edit', $s) }}">{{ $w?->domain }}</a>
                                <span class="muted">{{ $s->package ? '('.$s->package->name.')' : '('.__('No package').')' }}</span>
                            </div>
                            @if ($s->status === 'suspended' && $s->suspended_reason)<div class="small muted">{{ __($s->suspended_reason) }}</div>@endif
                        </td>
                        <td>@if ($w?->customer)<a href="{{ route('admin.subscriptions.index', ['customer' => $w->customer_id]) }}" title="{{ __('Subscriptions of :name', ['name' => $w->customer->displayName()]) }}">{{ $w->customer->displayName() }}</a>@else – @endif</td>
                        <td class="mono small">{{ $s->created_at?->toDateString() }}</td>
                        <td class="mono small">@if ($s->expires_on)<span @class(['subs-expired' => $s->isExpired()])>{{ $s->expires_on->toDateString() }}</span>@else – @endif</td>
                        <td class="subs-open">@if ($w)<a href="{{ route('admin.websites.show', $w) }}">{{ __('Open website') }} <x-icon name="external" /></a>@endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>
        {{ $subscriptions->links('partials.pagination') }}

        {{-- Bulk action dialogs (part of the form, so the selected subscriptions go along). --}}
        <dialog class="modal" x-ref="dlgPackage" aria-labelledby="dlg-package-title">
            <div class="modal-head"><h2 id="dlg-package-title">{{ __('Change package') }}</h2><button type="button" class="btn btn-ghost icon-btn" @click="$refs.dlgPackage.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <p class="small muted"><span x-text="selected.length"></span> {{ __('subscriptions selected. The new limits, disk quota and resource jail are applied right away.') }}</p>
                <x-ui.field name="hosting_package_id" type="select" :label="__('Package')">
                    <option value="">{{ __('No package (unlimited)') }}</option>
                    @foreach ($packages as $p)<option value="{{ $p->id }}">{{ $p->name }}@unless ($p->is_active) ({{ __('inactive') }})@endunless</option>@endforeach
                </x-ui.field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" @click="$refs.dlgPackage.close()">{{ __('Cancel') }}</button><button type="submit" name="action" value="package" class="btn btn-primary">{{ __('Change package') }}</button></div>
        </dialog>

        <dialog class="modal" x-ref="dlgOwner" aria-labelledby="dlg-owner-title">
            <div class="modal-head"><h2 id="dlg-owner-title">{{ __('Change owner') }}</h2><button type="button" class="btn btn-ghost icon-btn" @click="$refs.dlgOwner.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <p class="small muted"><span x-text="selected.length"></span> {{ __('subscriptions selected. The website, its mail domain, aliases and databases move to the new owner; files and settings stay as they are.') }}</p>
                <x-ui.field name="customer_id" type="select" :label="__('New owner')">
                    @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->displayName() }}@if ($c->company) ({{ $c->name }})@endif</option>@endforeach
                </x-ui.field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" @click="$refs.dlgOwner.close()">{{ __('Cancel') }}</button><button type="submit" name="action" value="owner" class="btn btn-primary">{{ __('Change owner') }}</button></div>
        </dialog>

        <dialog class="modal" x-ref="dlgExpiry" aria-labelledby="dlg-expiry-title">
            <div class="modal-head"><h2 id="dlg-expiry-title">{{ __('Expiry date') }}</h2><button type="button" class="btn btn-ghost icon-btn" @click="$refs.dlgExpiry.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <p class="small muted">{{ __('After this date the subscription is suspended automatically. Leave empty for no expiry date.') }}</p>
                <x-ui.field name="expires_on" type="date" :label="__('Expiry date')" />
            </div>
            <div class="modal-foot"><button type="button" class="btn" @click="$refs.dlgExpiry.close()">{{ __('Cancel') }}</button><button type="submit" name="action" value="expiry" class="btn btn-primary">{{ __('Save') }}</button></div>
        </dialog>

        <dialog class="modal" x-ref="dlgRemove" aria-labelledby="dlg-remove-title">
            <div class="modal-head"><h2 id="dlg-remove-title">{{ __('Remove subscriptions') }}</h2><button type="button" class="btn btn-ghost icon-btn" @click="$refs.dlgRemove.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <div class="alert alert-bad" role="alert"><x-icon name="trash" /><div><strong><span x-text="selected.length"></span> {{ __('subscriptions will be removed.') }}</strong>
                    <p class="small">{{ __('The websites, their files and databases are deleted from the server. Make a backup first if you may need them again.') }}</p></div></div>
                <label class="check"><input type="checkbox" name="confirm" value="1"><span>{{ __('Yes, remove these websites and their files') }}</span></label>
            </div>
            <div class="modal-foot"><button type="button" class="btn" @click="$refs.dlgRemove.close()">{{ __('Cancel') }}</button><button type="submit" name="action" value="remove" class="btn btn-danger">{{ __('Remove') }}</button></div>
        </dialog>
    </form>

    {{-- The search box sits in the toolbar but submits on its own (GET). --}}
    <form id="subs-search" method="GET" action="{{ route('admin.subscriptions.index') }}" hidden>
        @foreach (request()->only(['customer', 'package', 'status', 'per', 'sort', 'dir']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    </form>
</x-layouts.admin>
