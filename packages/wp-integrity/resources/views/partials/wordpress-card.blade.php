{{-- Integrity summary on the WordPress page of an install (admin and client). --}}
@php
    $wpiArea = $area ?? 'client';
    $wpiReport = \Rhis\WpIntegrity\Models\IntegrityReport::where('wordpress_install_id', $install->id)->first();
@endphp
@if (\Illuminate\Support\Facades\Route::has($wpiArea.'.wp-integrity.show') && ($wpiArea === 'admin' || config('wp-integrity.customer')))
    <x-ui.card :title="__('File integrity')">
        <x-slot:actions><a class="btn btn-sm {{ $wpiReport?->status === 'changed' ? 'btn-dark' : '' }}" href="{{ route($wpiArea.'.wp-integrity.show', $install) }}">{{ $wpiReport?->status === 'changed' ? __('Review and restore') : __('Open') }}</a></x-slot:actions>
        <div class="actions">
            @include('wp-integrity::partials.status', ['report' => $wpiReport])
            <span class="small muted">{{ $wpiReport?->checked_at
                ? __('WordPress and plugin files compared with WordPress.org :time.', ['time' => $wpiReport->checked_at->diffForHumans()])
                : __('WordPress and plugin files are compared with WordPress.org every night.') }}</span>
        </div>
    </x-ui.card>
@endif
