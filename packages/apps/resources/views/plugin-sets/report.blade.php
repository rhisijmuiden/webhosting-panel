{{-- What the plugin set did on this installation. Needs $installation. --}}
@php $psReport = $installation->plugin_set_report; @endphp
@if ($installation->plugin_set_name)
<link rel="stylesheet" href="{{ asset('assets/plugin-sets.css') }}?v={{ @filemtime(public_path('assets/plugin-sets.css')) }}">
<x-ui.card :title="__('Plugin set: :name', ['name' => $installation->plugin_set_name])">
    @if (! is_array($psReport))
        <p class="muted small">{{ __('No report from the server for this plugin set.') }}</p>
    @else
        <div class="ps-report">
            @if (($psReport['failed'] ?? 0) > 0)
                <div class="alert alert-warn" role="status"><x-icon name="info" />{{ trans_choice(':count item of the set could not be installed. WordPress itself works; add it later from wp-admin.|:count items of the set could not be installed. WordPress itself works; add them later from wp-admin.', $psReport['failed']) }}</div>
            @endif
            @if (! empty($psReport['items']))
                <ul>
                    @foreach ($psReport['items'] as $item)
                        <li>
                            @if (! ($item['ok'] ?? false))
                                <x-ui.pill status="bad">{{ __('Failed') }}</x-ui.pill>
                            @elseif ($item['activated'] ?? false)
                                <x-ui.pill status="ok">{{ __('Switched on') }}</x-ui.pill>
                            @else
                                <x-ui.pill status="neutral">{{ __('Installed') }}</x-ui.pill>
                            @endif
                            <span>{{ ($item['type'] ?? '') === 'theme' ? __('Theme') : __('Plugin') }}: <strong>{{ $item['name'] ?? '' }}</strong></span>
                            @if (! empty($item['error']))<span class="small muted">{{ $item['error'] }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if (! empty($psReport['removed_themes']))
                <p class="small muted">{{ __('Unused themes removed: :themes.', ['themes' => implode(', ', $psReport['removed_themes'])]) }}</p>
            @endif
            @foreach ($psReport['warnings'] ?? [] as $warning)
                <p class="small muted"><x-icon name="info" /> {{ $warning }}</p>
            @endforeach
        </div>
    @endif
</x-ui.card>
@endif
