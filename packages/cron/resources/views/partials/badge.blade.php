<span @class(['cj-badge', 'off' => ! $job->enabled]) aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">@switch($job->type)
    @case('php')<path d="m8 8-4 4 4 4M16 8l4 4-4 4"/>@break
    @case('url')<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>@break
    @default<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
@endswitch</svg></span>
