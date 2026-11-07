{{-- Tiles on the domain page (tab "Dashboard"): kind = redirects | access. --}}
@php
    $wrOn = $o->has('web-rules.show') && $o->allows('web-rules.customer_access');
    $wr = $wrOn ? \Rhis\WebRules\Models\SiteWebRules::where('website_id', $site->id)->first() : null;
    $wrSum = $wr?->summary() ?? ['redirects' => 0, 'folders' => 0, 'error_pages' => 0, 'blocking' => false];
@endphp
@if ($kind === 'redirects')
    @include('client.websites.overview.tile', ['href' => $wrOn ? $o->url('web-rules.show', [$site, 'tab' => 'redirects']) : null, 'icon' => 'link', 'label' => __('Redirects & error pages'),
        'sub' => $wrSum['redirects'] ? trans_choice(':count redirect|:count redirects', $wrSum['redirects']) : __('Redirect old addresses')])
@else
    @include('client.websites.overview.tile', ['href' => $wrOn ? $o->url('web-rules.show', [$site, 'tab' => $wrSum['folders'] ? 'folders' : 'access']) : null, 'icon' => 'key', 'label' => __('Access & passwords'),
        'sub' => collect([
            $wrSum['folders'] ? trans_choice(':count protected folder|:count protected folders', $wrSum['folders']) : null,
            $wrSum['blocking'] ? __('Blocking on') : null,
            $wr && ! $wr->force_https ? __('HTTP allowed') : null,
        ])->filter()->implode(' · ') ?: __('Passwords, HTTPS, blocking'),
        'tone' => $wr && ! $wr->force_https ? 'warn' : null])
@endif
