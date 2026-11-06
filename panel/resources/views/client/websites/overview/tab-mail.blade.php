{{-- Mail tab: shortcuts into the mail domain of this website. --}}
@if ($o->mailDomain)
    @php $m = $o->mailDomain; $local = $o->hasLocalMail(); @endphp
    <div class="tool-grid">
        @if ($local)
            @include('client.websites.overview.tile', ['href' => $o->mailLink('mailboxes'), 'icon' => 'inbox', 'label' => __('Mailboxes'), 'sub' => trans_choice(':count mailbox|:count mailboxes', $m->mailboxes_count)])
        @endif
        @include('client.websites.overview.tile', ['href' => $o->mailLink('mail'), 'icon' => 'settings', 'label' => __('Mail settings'), 'sub' => __('Provider, DKIM, SPF and DMARC')])
        @if ($local)
            @include('client.websites.overview.tile', ['href' => $o->mailLink('spam'), 'icon' => 'filter', 'label' => __('Spam & quarantine'), 'sub' => __('Filter level and held messages')])
        @endif
        @if ($local && $o->webmailHost)
            @include('client.websites.overview.tile', ['href' => 'https://'.$o->webmailHost, 'external' => true, 'icon' => 'mail', 'label' => __('Webmail'), 'sub' => $o->webmailHost])
        @elseif ($m->provider === 'microsoft365')
            @include('client.websites.overview.tile', ['href' => 'https://outlook.office.com/mail/', 'external' => true, 'icon' => 'mail', 'label' => 'Outlook', 'sub' => 'Microsoft 365'])
        @endif
        @include('client.websites.overview.tile', ['href' => $o->mailLink('dns'), 'icon' => 'globe', 'label' => __('DNS records'), 'sub' => __('MX, SPF, DKIM and DMARC records')])
    </div>
@else
    <x-ui.empty icon="mail">{{ __('Email is not set up for this domain yet.') }}</x-ui.empty>
    @if ($o->has('mail.domains.index'))
        <div><a class="btn btn-primary" href="{{ $o->url('mail.domains.index') }}"><x-icon name="mail" />{{ __('Set up email') }}</a></div>
    @endif
@endif
