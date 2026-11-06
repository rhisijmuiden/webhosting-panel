@php
    use Illuminate\Support\Number;
    $sortUrl = fn (string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $sort === $col && $direction === 'asc' ? 'desc' : 'asc', 'page' => null]);
    $sortMark = fn (string $col) => $sort === $col ? ($direction === 'asc' ? '↑' : '↓') : '';
    $aliasCount = collect($extras)->sum(fn ($e) => count($e['aliases']));
    $dnsBad = ['changes', 'missing', 'conflict', 'error', 'no_zone'];
@endphp
<x-layouts.admin :title="__('Domains')">
    <div class="domains-page" x-data="{ selected: [], all: @js($websites->pluck('id')->all()),
        toggleAll(on) { this.selected = on ? [...this.all] : []; },
        submit(action) { this.$refs.action.value = action; this.$refs.bulk.submit(); } }">
        <div class="page-head">
            <div>
                <h1>{{ __('Domains') }}</h1>
                <p class="sub">{{ trans_choice(':count domain|:count domains', $websites->total()) }}@if ($aliasCount) · {{ trans_choice(':count alias|:count aliases', $aliasCount) }}@endif</p>
            </div>
            <div class="actions">
                @if (Route::has('admin.domain-aliases.index'))
                    <x-ui.button icon="globe" :href="route('admin.domain-aliases.index')">{{ __('Domain aliases') }}</x-ui.button>
                @endif
                <x-ui.button variant="primary" icon="plus" :href="route('admin.websites.create')">{{ __('Add domain') }}</x-ui.button>
            </div>
        </div>

        <form x-ref="bulk" method="POST" action="{{ route('admin.websites.bulk', request()->only('q', 'status', 'php', 'sort', 'dir', 'per_page')) }}" id="bulk">
            @csrf
            <input type="hidden" name="action" x-ref="action">
            @foreach (request()->only('q', 'status', 'php', 'sort', 'dir', 'per_page') as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
        </form>

        <div class="list-toolbar">
            <div class="list-bulk" :class="{ 'is-on': selected.length }">
                <span class="list-selected" x-text="selected.length ? @js(__('Selected:')) + ' ' + selected.length : @js(__('Select domains for bulk actions'))"></span>
                <button type="button" class="btn btn-sm" :disabled="! selected.length" @click="submit('ssl')"><x-icon name="lock" />{{ __('Request certificate') }}</button>
                <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="btn btn-sm" :disabled="! selected.length" @click="open = ! open" :aria-expanded="open"><x-icon name="refresh" />{{ __('Page cache') }}</button>
                    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity>
                        <button type="button" @click="submit('cache_on')"><x-icon name="check" />{{ __('Turn on') }}</button>
                        <button type="button" @click="submit('cache_off')"><x-icon name="close" />{{ __('Turn off') }}</button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-danger" :disabled="! selected.length" @click="$refs.del.showModal()"><x-icon name="trash" />{{ __('Delete') }}</button>
            </div>
            <form class="list-filters" method="GET" role="search">
                <select name="status" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach (['active', 'provisioning', 'suspended', 'failed'] as $st)
                        <option value="{{ $st }}" @selected(request('status') === $st)>{{ __(ucfirst($st)) }}</option>
                    @endforeach
                </select>
                <label class="search"><x-icon name="search" /><input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Domain or owner') }}" aria-label="{{ __('Search') }}"></label>
                <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $direction }}"><input type="hidden" name="per_page" value="{{ $perPage }}">
            </form>
        </div>

        <x-ui.card flush>
            @if ($websites->isEmpty())
                <x-ui.empty icon="globe">{{ $search ? __('No domain matches your search.') : __('No websites yet.') }}</x-ui.empty>
            @else
            <x-ui.table class="domain-table">
                <x-slot:head>
                    <th class="check"><input type="checkbox" aria-label="{{ __('Select all') }}" :checked="selected.length && selected.length === all.length" @change="toggleAll($event.target.checked)"></th>
                    <th><a href="{{ $sortUrl('domain') }}">{{ __('Domain name') }} {{ $sortMark('domain') }}</a></th>
                    <th>{{ __('Owner') }}</th>
                    <th class="num"><a href="{{ $sortUrl('disk') }}">{{ __('Disk usage') }} {{ $sortMark('disk') }}</a></th>
                    <th class="num"><a href="{{ $sortUrl('traffic') }}">{{ __('Traffic') }} {{ $sortMark('traffic') }}</a></th>
                    <th>{{ __('Status') }}</th>
                    <th class="actions-col"><span class="sr-only">{{ __('Quick actions') }}</span></th>
                </x-slot:head>
                @foreach ($websites as $w)
                    @php($x = $extras[$w->id])
                    <tr @class(['is-selected' => false]) :class="{ 'is-selected': selected.includes({{ $w->id }}) }">
                        <td class="check"><input type="checkbox" name="ids[]" form="bulk" value="{{ $w->id }}" x-model.number="selected" aria-label="{{ $w->domain }}"></td>
                        <td>
                            <div class="domain-cell">
                                <span class="domain-avatar" aria-hidden="true">{{ strtoupper(mb_substr(preg_replace('/^www\./', '', $w->domain), 0, 2)) }}</span>
                                <div>
                                    <a class="strong" href="{{ route('admin.websites.show', $w) }}">{{ $w->domain }}</a>
                                    @if ($w->ssl_status !== 'active')<span class="domain-flag" title="{{ __('No valid certificate yet') }}">{{ __('no SSL') }}</span>@endif
                                    @if ($x['mail'] && in_array($x['mail']['status'], $dnsBad, true))
                                        <div class="domain-warn"><x-icon name="info" />{{ __('DNS needs attention.') }}
                                            @if (Route::has('admin.mail.domains.index'))<a href="{{ route('admin.mail.domains.index') }}">{{ __('Check DNS') }}</a>@endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                            @foreach ($x['aliases'] as $alias)
                                <div class="domain-alias">
                                    <x-icon name="chevron" />
                                    <span>{{ $alias['domain'] }}</span>
                                    <small>{{ $alias['mode'] === 'redirect' ? __('redirects to :domain', ['domain' => $w->domain]) : __('alias of :domain', ['domain' => $w->domain]) }}</small>
                                    @if ($alias['status'] !== 'active')<x-ui.pill :status="$alias['status']">{{ __(ucfirst($alias['status'])) }}</x-ui.pill>@endif
                                </div>
                            @endforeach
                        </td>
                        <td>@if ($w->customer)<a href="{{ route('admin.customers.show', $w->customer) }}">{{ $w->customer->displayName() }}</a>@endif</td>
                        <td class="num">
                            {{ Number::fileSize(($w->disk_used_mb ?? 0) * 1024 * 1024) }}
                            @if ($w->disk_quota_mb)<span class="muted small">/ {{ Number::fileSize($w->disk_quota_mb * 1024 * 1024) }}</span>@endif
                        </td>
                        <td class="num">{{ Number::fileSize((int) $w->traffic_bytes) }}<span class="muted small"> / {{ __('month') }}</span></td>
                        <td><x-ui.pill :status="$w->status">{{ __(ucfirst($w->status)) }}</x-ui.pill></td>
                        <td class="actions-col">
                            <div class="quick-actions">
                                @if ($x['wordpress'])
                                    <a href="{{ route('admin.wordpress.show', $x['wordpress']) }}" title="WordPress" aria-label="WordPress {{ $w->domain }}"><x-icon name="wordpress" /></a>
                                @endif
                                @if (Route::has('admin.files.browse'))
                                    <a href="{{ route('admin.files.browse', $w) }}" title="{{ __('Files') }}" aria-label="{{ __('Files') }} {{ $w->domain }}"><x-icon name="code" /></a>
                                @endif
                                @if ($x['mail'] && Route::has('admin.mail.domains.index'))
                                    <a href="{{ route('admin.mail.domains.index') }}" title="{{ __('Mail & DNS') }}" aria-label="{{ __('Mail & DNS') }} {{ $w->domain }}"><x-icon name="mail" /></a>
                                @endif
                                <a href="{{ route('admin.websites.show', [$w, 'tab' => 'hosting']) }}#databases" title="{{ __('Databases') }}" aria-label="{{ __('Databases') }} {{ $w->domain }}"><x-icon name="database" /></a>
                                <a href="{{ route('admin.websites.show', [$w, 'tab' => 'hosting']) }}" title="{{ __('Settings') }}" aria-label="{{ __('Settings') }} {{ $w->domain }}"><x-icon name="tools" /></a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            @endif
        </x-ui.card>

        <div class="list-foot">
            <span class="muted small">{{ trans_choice(':count item in total|:count items in total', $websites->total()) }}</span>
            <span class="per-page small">{{ __('Per page:') }}
                @foreach ([10, 25, 100] as $n)
                    @if ($n === $perPage)<strong>{{ $n }}</strong>@else<a href="{{ request()->fullUrlWithQuery(['per_page' => $n, 'page' => null]) }}">{{ $n }}</a>@endif
                @endforeach
            </span>
        </div>
        {{ $websites->links('partials.pagination') }}

        <dialog class="modal" x-ref="del" aria-labelledby="del-title">
            <div class="modal-body">
                <h2 id="del-title">{{ __('Delete domains') }}</h2>
                <p class="muted" x-text="@js(__('This removes the selected websites from the server:')) + ' ' + selected.length"></p>
                <label class="field"><span class="label">{{ __('Type :word to confirm.', ['word' => __('delete')]) }}</span><input type="text" name="confirm" form="bulk" autocomplete="off"></label>
                <label class="check-row"><input type="checkbox" name="keep_files" value="1" form="bulk" checked> {{ __('Keep the files in the home directory') }}</label>
                <div class="modal-actions modal-foot">
                    <button type="button" class="btn" @click="$refs.del.close()">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" @click="submit('delete')"><x-icon name="trash" />{{ __('Delete') }}</button>
                </div>
            </div>
        </dialog>
    </div>
</x-layouts.admin>
