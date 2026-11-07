{{-- WordPress tab of a website's page (admin and client): its staging copy, or the way to make one. --}}
@php
    $stgOn = $o->has('staging.index') && $o->allows('subdomains.customer_staging');
    $stgManager = $stgOn ? app(\Rhis\Subdomains\Staging\StagingManager::class) : null;
    $stgOwn = $stgManager?->stagingOf($site);
    $stgList = $stgManager && ! $stgOwn ? \Rhis\Subdomains\Staging\Models\Staging::where('live_website_id', $site->id)->latest('id')->get() : collect();
@endphp
@if ($stgOn)
    <x-ui.card :title="__('Staging')" id="staging">
        @if ($stgOwn)
            <div class="stg-cta">
                <span class="stg-badge">{{ __('Staging') }}</span>
                <p class="small">{{ __('This website is the staging copy of :domain. Email from it is held back and search engines skip it.', ['domain' => $stgOwn->live_domain]) }}</p>
                <a class="btn btn-primary btn-sm" href="{{ $o->url('staging.show', $stgOwn) }}"><x-icon name="send" />{{ __('Push to :domain', ['domain' => $stgOwn->live_domain]) }}</a>
            </div>
        @elseif ($stgList->isNotEmpty())
            <ul class="stg-list">
                @foreach ($stgList as $s)
                    <li>
                        <div class="stg-grow"><span class="strong">{{ $s->staging_domain }}</span>
                            <div class="muted small">{{ $s->status === 'copying' ? __('Copying') : ($s->last_push_at ? __('Last pushed :time', ['time' => $s->last_push_at->diffForHumans()]) : __('Not pushed yet')) }}</div></div>
                        <a class="btn btn-sm" href="{{ $o->url('staging.show', $s) }}"><x-icon name="settings" />{{ __('Manage') }}</a>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="stg-cta">
                <p class="small muted">{{ __('Try an update, a new plugin or a new design on a copy first. Happy with it? Push it to the live website with one click; a backup is made first.') }}</p>
                @if ($site->status === 'active')
                    <a class="btn btn-sm" href="{{ $o->url('staging.index', $site) }}"><x-icon name="plus" />{{ __('Make staging copy') }}</a>
                @endif
            </div>
        @endif
    </x-ui.card>
    @include('subdomains::staging.partials.styles')
@endif
