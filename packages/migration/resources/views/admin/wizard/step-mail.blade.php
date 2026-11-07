@php $provider = $domain?->provider ?? 'rhis'; $boxes = ($item->options['mail'] ?? false) ? ($item->source_data['mailboxes'] ?? []) : []; @endphp
<x-ui.card :title="__('Where does the mail go?')">
    <form method="POST" action="{{ route('admin.migration.wizard.mail', $item) }}" class="form">
        @csrf
        <div class="wz-choice">
            <label @class(['is-on' => $provider === 'rhis'])><input type="radio" name="provider" value="rhis" @checked($provider === 'rhis')>
                <span><strong>{{ __('Move mail to this server') }}</strong><small>{{ __('MX, SPF, DKIM, DMARC and autodiscover point to this server. The mailboxes below are used.') }}</small></span></label>
            <label @class(['is-on' => $provider === 'external'])><input type="radio" name="provider" value="external" @checked($provider === 'external')>
                <span><strong>{{ __('Keep mail where it is') }}@if ($externalMail) ({{ $externalMail }})@endif</strong><small>{{ __('The switch leaves every mail record alone, for example when the customer uses Microsoft 365.') }}</small></span></label>
        </div>
        <div><button class="btn" type="submit" @disabled($provider === 'microsoft365')>{{ __('Save mail choice') }}</button></div>
        @if ($provider === 'microsoft365')<p class="small muted">{{ __('This domain is set to Microsoft 365. The DNS step publishes the Microsoft 365 records.') }}</p>@endif
    </form>
</x-ui.card>
@if ($provider === 'rhis' && $boxes !== [])
<x-ui.card :title="__('Mailboxes')">
    <table class="table">
        <thead><tr><th>{{ __('Address') }}</th><th>{{ __('Size') }}</th><th>{{ __('Status') }}</th></tr></thead>
        <tbody>
        @foreach ($boxes as $box)
            @php $res = collect($item->result['mail'] ?? [])->firstWhere('local', $box['local']); @endphp
            <tr><td>{{ $box['local'] }}{{ '@'.$item->domain }}@if (! empty($box['aliases']))<br><span class="small muted">{{ __('Aliases') }}: {{ implode(', ', $box['aliases']) }}</span>@endif</td>
                <td class="small">{{ $box['size_mb'] ?? 0 }} MB</td>
                <td>@if ($res)<x-ui.pill :status="($res['ok'] ?? false) ? 'ok' : 'bad'">{{ ($res['ok'] ?? false) ? __('Copied') : __('Failed') }}</x-ui.pill>@else<x-ui.pill status="neutral">{{ __('Not copied yet') }}</x-ui.pill>@endif</td></tr>
        @endforeach
        </tbody>
    </table>
    <p class="small muted">{{ __('Mail that still arrives on the old server during the switch can be fetched afterwards with “Fetch late mail”.') }}</p>
</x-ui.card>
@endif
