<x-layouts.admin :title="__('Database cleanup').' · '.$install->label()">
    @include('wordpress::dbclean.status')
</x-layouts.admin>
