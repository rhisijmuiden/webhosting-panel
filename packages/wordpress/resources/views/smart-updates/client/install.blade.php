<x-layouts.client :title="$install->label().' · '.__('Smart updates')">
    @include('wordpress::smart-updates.partials.styles')
    @include('wordpress::smart-updates.partials.install')
</x-layouts.client>
