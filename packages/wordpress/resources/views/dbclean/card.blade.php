{{-- Card on the install page: link to the database cleanup and the last result. --}}
@php
    $dbcLast = \Rhis\WordPress\DbCleanup\DbCleanup::where('wordpress_install_id', $install->id)->latest('id')->first();
@endphp
<x-ui.card :title="__('Database cleanup')">
    <x-slot:actions>
        <x-ui.button :href="route($area.'.wordpress.dbclean.show', $install)" icon="database">{{ __('Review and clean up') }}</x-ui.button>
    </x-slot:actions>
    <p class="muted small">{{ __('Remove old revisions, spam, trash and expired temporary data, and optimize the tables. You see what will be removed first, and a snapshot is made before anything changes.') }}</p>
    @if ($dbcLast)
        <p class="small"><strong>{{ __('Last cleanup') }}</strong> {{ $dbcLast->created_at->diffForHumans() }}
            @include('wordpress::dbclean.pill', ['cleanup' => $dbcLast])
            @if ($dbcLast->status === 'succeeded')
                <span class="muted">· {{ trans_choice(':count item removed|:count items removed', $dbcLast->removedTotal()) }}@if ($dbcLast->freed()) · {{ __(':size smaller', ['size' => \Illuminate\Support\Number::fileSize($dbcLast->freed(), 1)]) }}@endif</span>
            @endif
        </p>
    @endif
</x-ui.card>
