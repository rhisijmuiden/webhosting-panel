@php $running = \Rhis\Migration\Models\MigrationItem::whereIn('status', ['queued', ...\Rhis\Migration\Models\MigrationItem::ACTIVE])->count(); @endphp
<nav class="mig-tabs" aria-label="{{ __('Migration') }}">
    <a href="{{ route('admin.migration.index') }}" @class(['is-active' => request()->routeIs('admin.migration.index', 'admin.migration.sources.*')])>{{ __('Source servers') }}</a>
    <a href="{{ route('admin.migration.status') }}" @class(['is-active' => request()->routeIs('admin.migration.status', 'admin.migration.items.*')])>{{ __('Status and log') }}@if ($running)<span class="mig-count">{{ $running }}</span>@endif</a>
</nav>
