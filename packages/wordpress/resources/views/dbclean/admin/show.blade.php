<x-layouts.admin :title="__('Database cleanup').' · '.$install->label()">
    @include('wordpress::dbclean.show')
</x-layouts.admin>
