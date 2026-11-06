{{-- Shown at the top of the admin Security screen. --}}
@php $wp = \Rhis\WordPress\Toolkit::summary(); @endphp
<x-ui.card :title="__('WordPress')">
    <x-slot:actions>
        <x-ui.button size="sm" :href="route('admin.wordpress.index', ['filter' => 'outdated'])" icon="wordpress">{{ __('Open WordPress toolkit') }}</x-ui.button>
        @if ($wp['outdated_installs'] > 0)
            <form method="POST" action="{{ route('admin.wordpress.update-outdated') }}" onsubmit="return confirm(@js(__('Update everything that is outdated? A snapshot is taken first and a site that breaks is rolled back.')))">
                @csrf<x-ui.button size="sm" variant="primary" icon="refresh">{{ __('Update all outdated (:n)', ['n' => $wp['outdated_installs']]) }}</x-ui.button>
            </form>
        @endif
    </x-slot:actions>
    @if ($wp['installs'] === 0)
        <p class="muted small">{{ __('No WordPress found yet. Sites are checked every night.') }}</p>
    @elseif ($wp['outdated_installs'] === 0)
        <p><x-ui.pill status="ok">{{ __('Up to date') }}</x-ui.pill> <span class="muted small">{{ trans_choice(':count WordPress site, nothing outdated.|:count WordPress sites, nothing outdated.', $wp['installs']) }}</span></p>
    @else
        <p><x-ui.pill status="warn">{{ __(':n of :total sites outdated', ['n' => $wp['outdated_installs'], 'total' => $wp['installs']]) }}</x-ui.pill>
            <span class="muted small">{{ __(':c core updates, :p plugins and :t themes are outdated. Outdated plugins are the most common way WordPress sites get hacked.', ['c' => $wp['core'], 'p' => $wp['plugins'], 't' => $wp['themes']]) }}</span></p>
    @endif
</x-ui.card>
