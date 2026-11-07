{{-- Link in the WordPress tab of a domain page. Needs $o (SiteOverview) and $i (the install). --}}
@if ($o->has('wordpress.dbclean.show'))<a href="{{ $o->url('wordpress.dbclean.show', $i) }}"><x-icon name="database" />{{ __('Clean up database') }}</a>@endif
