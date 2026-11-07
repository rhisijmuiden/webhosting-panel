{{-- Shown on a website's page (admin and client, Hosting & DNS tab): its extra FTP/SFTP accounts. --}}
@php
    $ftpArea = request()->routeIs('admin.*') ? 'admin' : 'client';
    $ftpManager = app(\App\Services\FtpAccounts::class);
    $ftpAccounts = \App\Models\FtpAccount::with('website')->where('website_id', $website->id)->orderBy('login')->get();
    $ftpLimit = $ftpManager->limit($website);
    $ftpManage = $ftpArea === 'admin' || config('ftp-accounts.customer_manage');
    $ftpCanAdd = $ftpManage && $website->status === 'active' && ($ftpArea === 'admin' || $ftpManager->canAdd($website));
@endphp
@if (\Illuminate\Support\Facades\Route::has($ftpArea.'.ftp-accounts.index') && ($ftpManage || $ftpAccounts->isNotEmpty()))
<x-ui.card :title="__('FTP accounts')" id="ftp-accounts">
    <x-slot:actions>
        <span class="muted small">{{ $ftpLimit === null ? trans_choice(':count account|:count accounts', $ftpAccounts->count()) : __(':used of :limit used', ['used' => $ftpAccounts->count(), 'limit' => $ftpLimit]) }}</span>
    </x-slot:actions>
    <p class="muted small">{{ __('Extra logins for a web designer, a photographer or a backup tool. Each account only sees its own folder of this website, and can be read only. The main SFTP account stays as it is.') }}</p>
    @include('ftp-accounts.partials.styles')
    @include('ftp-accounts.partials.messages')
    @if ($ftpAccounts->isNotEmpty())
        @include('ftp-accounts.partials.table', ['accounts' => $ftpAccounts, 'area' => $ftpArea, 'showWebsite' => false, 'canManage' => $ftpManage, 'manager' => $ftpManager])
    @endif
    @if ($ftpCanAdd)
        <details class="ftp-details" @if (($errors->hasAny(['name', 'folder', 'password']) && ! old('_ftp')) || $ftpAccounts->isEmpty()) open @endif>
            <summary class="btn btn-sm"><x-icon name="plus" />{{ __('Add FTP account') }}</summary>
            @include('ftp-accounts.partials.add-form', ['area' => $ftpArea, 'websites' => collect([$website])])
        </details>
    @elseif ($ftpManage && $ftpArea === 'client' && $ftpLimit !== null && $ftpAccounts->count() >= $ftpLimit)
        <p class="small muted">{{ __('Your package allows :count FTP accounts for this website. Ask us for a larger package if you need more.', ['count' => $ftpLimit]) }}</p>
    @endif
    <details class="ftp-details">
        <summary class="btn btn-sm btn-ghost"><x-icon name="info" />{{ __('How to connect') }}</summary>
        @include('ftp-accounts.partials.connection', ['manager' => $ftpManager, 'exampleDomain' => $website->domain])
    </details>
</x-ui.card>
@endif
