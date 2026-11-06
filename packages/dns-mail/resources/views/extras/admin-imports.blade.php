@php
    $num = fn ($n) => number_format((int) $n, 0, ',', app()->getLocale() === 'nl' ? '.' : ',');
    $filterUrl = fn (?string $s) => route('admin.mail.imports.index', array_filter(['status' => $s]));
@endphp
<x-layouts.admin :title="__('Mail imports')">
    <div class="page-head">
        <div><h1>{{ __('Mail imports') }}</h1><p class="sub">{{ __('Mailboxes customers and admins copy over from other providers (IMAP). Start a new import on the Import mail tab of a domain.') }}</p></div>
        <div class="actions"><x-ui.button icon="mail" :href="route('admin.mail.domains.index')">{{ __('Domains & mail') }}</x-ui.button></div>
    </div>

    @if (! $available)
        <div class="alert alert-warn" role="status"><x-icon name="info" />
            <div><strong>{{ __('Mail import is not ready on this server yet.') }}</strong>
                <p class="small">{{ __('The next panel update installs imapsync and the import login for Dovecot.') }}</p></div>
        </div>
    @endif

    <section class="grid grid-stats" aria-label="{{ __('Totals') }}">
        <a class="card stat" href="{{ $filterUrl('active') }}"><span class="label">{{ __('Running or waiting') }}</span><strong class="value">{{ $counts['active'] }}</strong><span class="hint">{{ __('At most 3 run at the same time') }}</span></a>
        <a class="card stat" href="{{ $filterUrl('done') }}"><span class="label">{{ __('Finished') }}</span><strong class="value">{{ $counts['done'] }}</strong><span class="hint">{{ __('All messages copied') }}</span></a>
        <a class="card stat" href="{{ $filterUrl('problems') }}"><span class="label">{{ __('Need attention') }}</span><strong class="value">{{ $counts['problems'] }}</strong><span class="hint">{{ __('Failed or finished with errors') }}</span></a>
    </section>

    <nav class="seg" aria-label="{{ __('Filter') }}" style="margin-bottom:4px">
        @foreach ([null => __('All'), 'active' => __('Running or waiting'), 'done' => __('Finished'), 'problems' => __('Need attention')] as $key => $label)
            <a href="{{ $filterUrl($key ?: null) }}" @class(['btn', 'btn-dark' => $status === ($key ?: null), 'btn-ghost' => $status !== ($key ?: null)])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-ui.card flush>
        @if ($imports->isEmpty())
            <x-ui.empty icon="migrate">{{ __('No mail imports yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Into') }}</th><th>{{ __('Customer') }}</th><th>{{ __('From') }}</th><th style="min-width:200px">{{ __('Progress') }}</th><th>{{ __('Started') }}</th></x-slot:head>
            @foreach ($imports as $import)
                @php $d = $import->mailbox?->domain; @endphp
                <tr>
                    <td>@if ($d)<a class="strong" href="{{ route('admin.mail.domains.show', [$d->id, 'tab' => 'import']) }}">{{ $import->mailbox->local_part . '@' . $d->domain }}</a>@endif</td>
                    <td>@if ($d?->customer)<a href="{{ route('admin.customers.show', $d->customer) }}">{{ $d->customer->displayName() }}</a>@else<span class="muted">–</span>@endif</td>
                    <td>{{ $import->username }}<br><span class="muted small">{{ $import->host }}</span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px"><x-ui.pill :status="$import->pill()">{{ $import->statusLabel() }}</x-ui.pill>
                            @if ($import->total)<span class="small muted">{{ $num($import->done) }} / {{ $num($import->total) }}</span>@endif</div>
                        @if ($import->status !== 'queued')<x-ui.meter :label="__('Progress')" :percent="$import->percent" />@endif
                        @if ($import->errors)<div class="small error">{{ trans_choice(':count error|:count errors', $import->errors) }}</div>@endif
                    </td>
                    <td class="small">{{ $import->started_at?->diffForHumans() ?? '–' }}@if ($import->finished_at)<br><span class="muted">{{ __('took :time', ['time' => $import->started_at?->diffForHumans($import->finished_at, true) ?? '–']) }}</span>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    {{ $imports->links() }}
</x-layouts.admin>
