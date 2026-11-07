@php use Illuminate\Support\Number; use App\Services\Databases\DatabaseManager; @endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Databases')">
    @include('databases.partials.styles')
    <div class="page-head">
        <div>
            <h1>{{ __('Databases') }}</h1>
            <p class="sub">{{ __('MariaDB databases and database users per website. Open a database in phpMyAdmin with one click: no password needed.') }}</p>
        </div>
    </div>

    @if ($area === 'admin')
        <div class="list-toolbar">
            <form class="list-filters" method="GET" role="search">
                <label class="check"><input type="checkbox" name="with" value="1" @checked($with) onchange="this.form.submit()"><span>{{ __('Only websites with databases') }}</span></label>
                <label class="search"><x-icon name="search" /><input type="search" name="q" value="{{ $q }}" placeholder="{{ __('Domain, database or customer') }}" aria-label="{{ __('Search') }}"></label>
            </form>
        </div>
    @endif

    <x-ui.card flush>
        @if ($websites->isEmpty())
            <x-ui.empty icon="database">{{ $q ? __('Nothing matches your search.') : __('No websites yet.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <x-slot:head>
                    <th>{{ __('Website') }}</th>
                    @if ($area === 'admin')<th class="db-hide-sm">{{ __('Customer') }}</th>@endif
                    <th>{{ __('Databases') }}</th><th class="num db-hide-sm">{{ __('Size') }}</th><th class="db-hide-sm"></th><th></th>
                </x-slot:head>
                @foreach ($websites as $w)
                    @php
                        $limit = DatabaseManager::limit($w); $used = DatabaseManager::used($w);
                        $size = $w->databases->sum(fn ($d) => $sizes[$d->name]['size_bytes'] ?? 0);
                    @endphp
                    <tr>
                        <td><div class="db-name"><span class="db-icon"><x-icon name="globe" /></span>
                            <div><a class="strong" href="{{ route($area.'.databases.show', $w) }}">{{ $w->domain }}</a>
                            @if ($w->parent_id)<div class="muted small">{{ __('Subdomain') }}</div>@endif</div></div></td>
                        @if ($area === 'admin')<td class="db-hide-sm">{{ $w->customer?->name ?? '–' }}</td>@endif
                        <td><div class="db-usage"><strong>{{ $w->databases_count }}</strong>
                            <span class="muted small">{{ $limit === null ? __('of unlimited') : __('of :max in the package', ['max' => $limit]) }}</span>
                            @if ($limit)<span class="bar"><span class="{{ $used >= $limit ? 'full' : '' }}" style="width: {{ min(100, round($used / max(1, $limit) * 100)) }}%"></span></span>@endif
                        </div></td>
                        <td class="num db-hide-sm">{{ $size ? Number::fileSize($size, 1) : '–' }}</td>
                        <td class="db-hide-sm"><div class="db-list-tags">
                            @foreach ($w->databases->take(3) as $d)<span class="db-tag">{{ $d->name }}</span>@endforeach
                            @if ($w->databases->count() > 3)<span class="muted small">+{{ $w->databases->count() - 3 }}</span>@endif
                        </div></td>
                        <td class="num"><x-ui.button size="sm" :href="route($area.'.databases.show', $w)">{{ __('Manage') }}</x-ui.button></td>
                    </tr>
                @endforeach
            </x-ui.table>
            @if ($area === 'admin' && $websites->hasPages())<div class="list-foot">{{ $websites->links() }}</div>@endif
        @endif
    </x-ui.card>
</x-dynamic-component>
