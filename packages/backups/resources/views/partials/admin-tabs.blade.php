@include('backups::partials.styles')
<nav class="bk-tabs" aria-label="{{ __('Backups') }}">
    <a href="{{ route('admin.backups.index') }}" @if (request()->routeIs('admin.backups.index')) aria-current="page" @endif><x-icon name="backup" />{{ __('Overview') }}</a>
    <a href="{{ route('admin.backups.browse') }}" @if (request()->routeIs('admin.backups.browse')) aria-current="page" @endif><x-icon name="list" />{{ __('Browse by date') }}</a>
    <a href="{{ route('admin.backups.customers') }}" @if (request()->routeIs('admin.backups.customers')) aria-current="page" @endif><x-icon name="customers" />{{ __('Customer backups') }}</a>
    <a href="{{ route('admin.backups.restore-tests.index') }}" @if (request()->routeIs('admin.backups.restore-tests.*')) aria-current="page" @endif><x-icon name="check" />{{ __('Restore tests') }}</a>
</nav>
