<x-layouts.client :title="__('Database cleanup').' · '.$install->label()">
    @include('wordpress::dbclean.show')
</x-layouts.client>
