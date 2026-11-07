{{-- Smart updates card on the WordPress tab of the domain page (admin and client). Needs $o (SiteOverview) and $site. --}}
@php
    $suInstalls = $o->wordpress ?? collect();
    $suRoute = $o->has('wordpress.smart.install');
@endphp
@if ($suRoute && $suInstalls->isNotEmpty())
    @include('wordpress::smart-updates.partials.styles')
    @foreach ($suInstalls as $suInstall)
        @php
            $suPolicy = \Rhis\WordPress\SmartUpdates\Models\UpdatePolicy::for($suInstall);
            $suLast = \Rhis\WordPress\SmartUpdates\Models\UpdateRun::where('wordpress_install_id', $suInstall->id)->latest('id')->first();
        @endphp
        <div class="wp-block">
            <div class="su-card-site">
                <div>
                    <h3 style="margin:0 0 6px">{{ __('Smart updates') }}@if ($suInstalls->count() > 1) <span class="muted small">{{ $suInstall->label() }}</span>@endif</h3>
                    @include('wordpress::smart-updates.partials.mode', ['policy' => $suPolicy])
                    <p class="muted small" style="margin:8px 0 0">
                        @if ($suLast)
                            {{ __('Last run :time:', ['time' => ($suLast->finished_at ?? $suLast->started_at)?->diffForHumans()]) }} <x-ui.pill :status="$suLast->pill()">{{ $suLast->statusLabel() }}</x-ui.pill>
                        @else
                            {{ __('Updates run at night in the maintenance window, checked with screenshots.') }}
                        @endif
                    </p>
                </div>
                <div class="actions">
                    @if ($suLast)<a class="btn btn-sm btn-ghost" href="{{ $o->url('wordpress.smart.runs.show', $suLast) }}">{{ __('Screenshots') }}</a>@endif
                    <a class="btn btn-sm" href="{{ $o->url('wordpress.smart.install', $suInstall) }}"><x-icon name="settings" />{{ __('Settings') }}</a>
                </div>
            </div>
        </div>
    @endforeach
@endif
