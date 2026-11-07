<x-dynamic-component :component="'layouts.'.$area" :title="__('Website logs')">
    <div class="page-head">
        <div>
            <h1>{{ $area === 'admin' ? __('Website logs') : __('Logs') }}</h1>
            <div class="sub">{{ __('Visitors, errors and PHP messages of a website, searchable and live.') }}</div>
        </div>
        @if ($area === 'admin')
            <form method="GET" class="actions" role="search">
                <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Find a website') }}" aria-label="{{ __('Find a website') }}">
                <button type="submit" class="btn"><x-icon name="search" />{{ __('Search') }}</button>
            </form>
        @endif
    </div>
    <x-ui.card flush>
        @if ($websites->isEmpty())
            <x-ui.empty icon="globe">{{ __('No websites yet.') }}</x-ui.empty>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Website') }}</th>@if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif<th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($websites as $site)
                    <tr>
                        <td><a class="strong" href="{{ route($area.'.site-logs.show', $site) }}">{{ $site->domain }}</a></td>
                        @if ($area === 'admin')<td class="muted">{{ $site->customer?->displayName() ?? '–' }}</td>@endif
                        <td><x-ui.pill :status="$site->status === 'active' ? 'ok' : ($site->status === 'suspended' ? 'warn' : 'neutral')">{{ __(ucfirst((string) $site->status)) }}</x-ui.pill></td>
                        <td class="num" style="white-space:nowrap">
                            <a class="btn btn-sm" href="{{ route($area.'.site-logs.show', [$site, 'log' => 'access']) }}">{{ __('Access log') }}</a>
                            <a class="btn btn-sm" href="{{ route($area.'.site-logs.show', [$site, 'log' => 'php']) }}">{{ __('PHP errors') }}</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-ui.card>
</x-dynamic-component>
