@php $lp = app(\Rhis\Security\LoginProtection::class); $on = collect($lp->services())->where('enabled', true)->count(); @endphp
<div class="alert alert-info" role="status" style="justify-content:space-between">
    <span><x-icon name="lock" />{{ __('Login protection is on for :on of :total services.', ['on' => $on, 'total' => count(\Rhis\Security\LoginProtection::SERVICES)]) }}</span>
    <a class="btn btn-sm" href="{{ route('admin.protection.edit') }}">{{ __('Settings') }}</a>
</div>
