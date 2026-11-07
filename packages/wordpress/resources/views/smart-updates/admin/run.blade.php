<x-layouts.admin :title="__('Smart update').' · '.$install->label()">
    @include('wordpress::smart-updates.partials.styles')
    @include('wordpress::smart-updates.partials.run')
</x-layouts.admin>
