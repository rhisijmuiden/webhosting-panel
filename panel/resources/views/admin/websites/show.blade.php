{{-- The same domain page the customer sees, with the admin sidebar and admin routes. --}}
<x-layouts.admin :title="$o->website->domain">
    @include('client.websites.overview.page')
</x-layouts.admin>
