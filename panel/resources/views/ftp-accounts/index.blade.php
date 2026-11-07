<x-dynamic-component :component="'layouts.'.$area" :title="__('FTP accounts')">
    <div class="page-head">
        <div>
            <h1>{{ __('FTP accounts') }}</h1>
            <p class="sub">{{ __('Extra FTP and SFTP logins per website. Each account is locked in one folder of its website and can be read only.') }}</p>
        </div>
    </div>
    @include('ftp-accounts.partials.messages')

    <x-ui.card :title="__('All FTP accounts')" flush>
        @if ($accounts->isEmpty())
            <x-ui.empty icon="key">{{ __('No extra FTP accounts yet.') }}</x-ui.empty>
        @else
            @include('ftp-accounts.partials.table', ['accounts' => $accounts, 'area' => $area, 'showWebsite' => true, 'canManage' => $canManage, 'manager' => $manager])
        @endif
    </x-ui.card>

    <section class="grid grid-2" style="align-items:start">
        @php
            $addable = $area === 'admin' ? $websites : $websites->filter(fn ($w) => $manager->canAdd($w))->values();
        @endphp
        @if ($canManage && $addable->isNotEmpty())
            <x-ui.card :title="__('Add FTP account')">
                @include('ftp-accounts.partials.add-form', ['area' => $area, 'websites' => $addable])
            </x-ui.card>
        @elseif ($canManage && $websites->isNotEmpty())
            <x-ui.card :title="__('Add FTP account')">
                <p class="small muted">{{ __('Your package does not allow more FTP accounts. Ask us for a larger package if you need more.') }}</p>
            </x-ui.card>
        @endif
        <x-ui.card :title="__('How to connect')">
            @include('ftp-accounts.partials.connection', ['manager' => $manager, 'exampleDomain' => $websites->first()?->domain])
            <p class="small muted">{{ __('Use an FTP program such as FileZilla, WinSCP or Cyberduck. Choose SFTP with port :sftp, or "FTP over explicit TLS" with port :ftp.', ['sftp' => $manager->status()['sftp_port'], 'ftp' => $manager->status()['ftp_port']]) }}</p>
        </x-ui.card>
    </section>
</x-dynamic-component>
