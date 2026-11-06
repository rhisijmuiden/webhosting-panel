<x-ui.card :title="__('Set up your mail app')">
    <p class="muted">{{ __('Most apps find the settings by themselves: enter your address and password.') }}</p>
    <section class="grid grid-3">
        <div><h3>{{ __('iPhone, iPad and Mac') }}</h3><p class="muted small">{{ __('Download the Apple profile next to the mailbox, open it and tap Install. You enter your password on the device.') }}</p></div>
        <div><h3>Outlook</h3><p class="muted small">{{ __('Add account, enter your address and choose IMAP if asked. The settings are found automatically.') }}</p></div>
        <div><h3>{{ __('Android, Thunderbird and others') }}</h3><p class="muted small">{{ __('Enter your address and password; the app looks up the rest. Otherwise use the settings below.') }}</p></div>
    </section>
    <h3 style="margin-top:16px">{{ __('Manual settings') }}</h3>
    <x-ui.table>
        <x-slot:head><th></th><th>{{ __('Server') }}</th><th>{{ __('Port') }}</th><th>{{ __('Security') }}</th></x-slot:head>
        <tr><td class="strong">{{ __('Incoming mail (IMAP)') }}</td><td class="mono">{{ $mailHost }}</td><td>993</td><td>SSL/TLS</td></tr>
        <tr><td class="strong">{{ __('Outgoing mail (SMTP)') }}</td><td class="mono">{{ $mailHost }}</td><td>465</td><td>SSL/TLS</td></tr>
        <tr><td class="muted">{{ __('or') }}</td><td class="mono">{{ $mailHost }}</td><td>587</td><td>STARTTLS</td></tr>
    </x-ui.table>
    <p class="muted small">{{ __('User name: your full email address. Outgoing mail needs the same user name and password.') }}</p>
</x-ui.card>
