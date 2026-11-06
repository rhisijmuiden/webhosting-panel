@php
    use Rhis\DnsMail\Models\MailLogMessage;
    $dirLabels = ['outgoing' => __('Outgoing'), 'incoming' => __('Incoming'), 'internal' => __('Internal'), 'unknown' => __('Unknown')];
    $originLabels = ['authenticated' => __('SMTP login'), 'website' => __('Website (PHP)'), 'system' => __('Server'), 'inbound' => __('From outside'), 'bounce' => __('Bounce'), 'unknown' => __('Unknown')];
    $statusLabels = ['queued' => __('Queued'), 'sent' => __('Delivered'), 'deferred' => __('Deferred'), 'bounced' => __('Bounced'), 'rejected' => __('Refused'), 'expired' => __('Expired'), 'mixed' => __('Partly delivered')];
    $tz = config('app.timezone');
@endphp
<x-layouts.admin :title="__('Mail log')">
    <div class="page-head">
        <div>
            <nav class="crumbs" aria-label="{{ __('Breadcrumb') }}"><a href="{{ route('admin.mail.log.index') }}">{{ __('Mail log') }}</a></nav>
            <h1>{{ $m->subject ?: __('Message :id', ['id' => $m->queue_id ?? '#'.$m->id]) }}</h1>
            <p class="sub">{{ $m->logged_at->timezone($tz)->format('d-m-Y H:i:s') }} · {{ $dirLabels[$m->direction] ?? $m->direction }} · {{ $originLabels[$m->origin] ?? $m->origin }}</p>
        </div>
        <div class="actions"><x-ui.pill :status="MailLogMessage::statusTone($m->status)">{{ $statusLabels[$m->status] ?? $m->status }}</x-ui.pill></div>
    </div>

    <div class="grid grid-2">
        <x-ui.card :title="__('Message')">
            <dl class="kv">
                <dt>{{ __('From') }}</dt><dd>{{ $m->sender === '' ? '<> ('.__('bounce').')' : ($m->sender ?? '–') }}</dd>
                @if ($m->sasl_username)<dt>{{ __('SMTP login') }}</dt><dd>{{ $m->sasl_username }}</dd>@endif
                @if ($m->system_user)<dt>{{ __('Website user') }}</dt><dd>{{ $m->system_user }}</dd>@endif
                <dt>{{ __('Client') }}</dt><dd>{{ $m->client_host && $m->client_host !== 'unknown' ? $m->client_host.' ' : '' }}{{ $m->client_ip ?? '–' }}@if ($m->service) <span class="muted small">({{ $m->service }})</span>@endif</dd>
                <dt>{{ __('Size') }}</dt><dd>{{ $m->size !== null ? \Illuminate\Support\Number::fileSize($m->size, precision: 1) : '–' }}</dd>
                <dt>{{ __('Queue ID') }}</dt><dd><code>{{ $m->queue_id ?? '–' }}</code></dd>
                <dt>{{ __('Message ID') }}</dt><dd style="word-break:break-all"><code>{{ $m->message_id ?? '–' }}</code></dd>
                @if ($m->finished_at)<dt>{{ __('Finished') }}</dt><dd>{{ $m->finished_at->timezone($tz)->format('d-m-Y H:i:s') }}</dd>@endif
                @if ($m->reject_reason)<dt>{{ __('Reason') }}</dt><dd>{{ $m->reject_reason }}</dd>@endif
            </dl>
        </x-ui.card>
        <x-ui.card :title="__('Customer')">
            <dl class="kv">
                <dt>{{ __('Customer') }}</dt><dd>@if ($m->customer)<a href="{{ route('admin.customers.show', $m->customer) }}">{{ $m->customer->displayName() }}</a>@else{{ __('Not linked to a customer') }}@endif</dd>
                @if ($m->mailDomain)<dt>{{ __('Domain') }}</dt><dd><a href="{{ route('admin.mail.domains.show', $m->mailDomain->id) }}">{{ $m->mailDomain->domain }}</a></dd>@endif
                @if ($m->mailbox)<dt>{{ __('Mailbox') }}</dt><dd>{{ $m->mailbox->address() }}</dd>@endif
                @if ($m->website)<dt>{{ __('Website') }}</dt><dd><a href="{{ route('admin.websites.show', $m->website) }}">{{ $m->website->domain }}</a></dd>@endif
            </dl>
            @if ($m->customer)
                <p style="margin-top:16px"><x-ui.button size="sm" icon="list" :href="route('admin.mail.log.index', ['customer' => $m->customer_id, 'direction' => 'outgoing', 'period' => '7d'])">{{ __('All outgoing mail of this customer') }}</x-ui.button></p>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Recipients')" flush>
        @if ($m->recipients->isEmpty())
            <x-ui.empty icon="mail">{{ __('No delivery attempts logged yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Recipient') }}</th><th>{{ __('Status') }}</th><th>{{ __('Delivered to') }}</th><th class="num">{{ __('Attempts') }}</th><th>{{ __('Server response') }}</th></x-slot:head>
            @foreach ($m->recipients as $r)
                <tr>
                    <td class="strong">{{ $r->recipient }}@if ($r->orig_recipient && $r->orig_recipient !== $r->recipient)<div class="small muted">{{ __('via :address', ['address' => $r->orig_recipient]) }}</div>@endif</td>
                    <td><x-ui.pill :status="MailLogMessage::statusTone($r->status)">{{ $statusLabels[$r->status] ?? $r->status }}</x-ui.pill>@if ($r->dsn)<div class="small muted">{{ $r->dsn }}</div>@endif</td>
                    <td>{{ $r->local ? __('Mailbox on this server') : ($r->relay ?? '–') }}@if ($r->delay !== null)<div class="small muted">{{ __(':seconds s', ['seconds' => $r->delay]) }}</div>@endif</td>
                    <td class="num">{{ $r->attempts }}</td>
                    <td class="small" style="max-width:380px;word-break:break-word">{{ $r->response ?? '–' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>

    @if ($m->spam_score !== null)
        <x-ui.card :title="__('Spam filter')">
            <p><strong>{{ __('Score :score', ['score' => number_format($m->spam_score, 2)]) }}</strong> · {{ $m->spam_action }}</p>
            @if ($m->spam_symbols)
                <x-ui.table>
                    <x-slot:head><th>{{ __('Rule') }}</th><th class="num">{{ __('Points') }}</th></x-slot:head>
                    @foreach ($m->spam_symbols as $s)<tr><td><code>{{ $s['name'] ?? '' }}</code></td><td class="num">{{ $s['score'] ?? '' }}</td></tr>@endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif

    @if ($related->isNotEmpty())
        <x-ui.card :title="__('Same message elsewhere in the log')" flush>
            <x-ui.table>
                <x-slot:head><th>{{ __('Time') }}</th><th>{{ __('Queue ID') }}</th><th>{{ __('Status') }}</th></x-slot:head>
                @foreach ($related as $r)
                    <tr><td><a href="{{ route('admin.mail.log.show', $r) }}">{{ $r->logged_at->timezone($tz)->format('d-m H:i:s') }}</a></td><td><code>{{ $r->queue_id }}</code></td>
                        <td><x-ui.pill :status="MailLogMessage::statusTone($r->status)">{{ $statusLabels[$r->status] ?? $r->status }}</x-ui.pill></td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</x-layouts.admin>
