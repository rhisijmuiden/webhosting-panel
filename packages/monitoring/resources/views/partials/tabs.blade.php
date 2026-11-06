@php $openCount = \Rhis\Monitoring\Models\Incident::open()->count(); @endphp
<nav class="mo-tabs" aria-label="{{ __('Monitoring') }}">
    @foreach (['index' => __('Overview'), 'incidents' => __('Incidents'), 'alerts' => __('Alerts'), 'status' => __('Status page'), 'settings' => __('Settings')] as $r => $label)
        <a href="{{ route('admin.monitoring.'.$r) }}" @if (request()->routeIs('admin.monitoring.'.$r) || ($r === 'index' && request()->routeIs('admin.monitoring.website'))) aria-current="page" @endif>{{ $label }}@if ($r === 'incidents' && $openCount)<span class="count" aria-label="{{ __(':n open', ['n' => $openCount]) }}">{{ $openCount }}</span>@endif</a>
    @endforeach
</nav>
