<nav class="sv-tabs" aria-label="{{ __('Server management') }}">
    @foreach (['index' => __('Updates & reboot'), 'maintenance' => __('Maintenance window'), 'tasks' => __('Scheduled tasks'), 'php' => __('PHP versions')] as $r => $label)
        <a href="{{ route('admin.server.'.$r) }}" @if (request()->routeIs('admin.server.'.$r, 'admin.server.'.$r.'.*')) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
