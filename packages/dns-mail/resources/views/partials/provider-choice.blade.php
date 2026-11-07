{{-- Three big radio cards. $current: rhis | microsoft365 | external --}}
<div class="grid grid-3" role="radiogroup" aria-label="{{ __('Mail for this domain') }}">
    @foreach ([
        'rhis' => [__(':provider Mail', ['provider' => \App\Support\Brand::provider()]), __('Mailboxes on this server, with spam filter, virus scan, DKIM and webmail.')],
        'microsoft365' => ['Microsoft 365', __('Mailboxes in Microsoft 365. We set up all the DNS records for you.')],
        'external' => [__('Other provider'), __('Mail is handled elsewhere. MX, SPF and DMARC are left alone.')],
    ] as $value => [$title, $text])
        <label class="check card" style="margin:0;align-items:flex-start;cursor:pointer">
            <input type="radio" name="provider" value="{{ $value }}" @checked($current === $value) x-model="provider">
            <span><strong>{{ $title }}</strong><small>{{ $text }}</small></span>
        </label>
    @endforeach
</div>
