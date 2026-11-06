<x-dynamic-component :component="'layouts.'.$area" :title="__('File manager')">
    <div class="page-head">
        <div>
            <h1>{{ __('File manager') }}</h1>
            <div class="sub">{{ __('Choose a website to browse, upload, edit and unpack its files.') }}</div>
        </div>
    </div>
    <x-ui.card flush>
        @if ($websites->isEmpty())
            <x-ui.empty icon="globe">{{ __('No websites yet.') }}</x-ui.empty>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Website') }}</th>@if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif<th class="fm-hide-sm">{{ __('System user') }}</th><th class="num">{{ __('Disk') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($websites as $site)
                    <tr>
                        <td><a class="strong" href="{{ route($area.'.files.browse', $site) }}">{{ $site->domain }}</a></td>
                        @if ($area === 'admin')<td class="muted">{{ $site->customer?->displayName() ?? '–' }}</td>@endif
                        <td class="mono small fm-hide-sm">{{ $site->system_user ?? '–' }}</td>
                        <td class="num">{{ $site->disk_used_mb }} MB</td>
                        <td class="num"><a class="btn btn-sm" href="{{ route($area.'.files.browse', $site) }}">{{ __('Open') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-ui.card>
</x-dynamic-component>
