<x-layouts.client :title="__('Database cleanup').' · '.$install->label()">
    @include('wordpress::dbclean.status')
</x-layouts.client>
