<x-layouts.client :title="__('Mail & DNS')">
    <div class="page-head"><div><h1>{{ __('Mail & DNS') }}</h1><p class="sub">{{ __('Your domains, mailboxes and DNS records.') }}</p></div></div>
    <x-ui.card flush>
        @if ($domains->isEmpty())
            <x-ui.empty icon="mail">{{ __('No domains yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Domain') }}</th><th>{{ __('Mail') }}</th><th class="num">{{ __('Mailboxes') }}</th><th>DNS</th></x-slot:head>
            @foreach ($domains as $d)
                <tr>
                    <td><a class="strong" href="{{ route('client.mail.domains.show', $d->id) }}">{{ $d->domain }}</a></td>
                    <td>@include('dns-mail::partials.provider-pill', ['provider' => $d->provider])</td>
                    <td class="num">{{ $d->hasLocalMail() ? $d->mailboxes_count : '–' }}</td>
                    <td>@include('dns-mail::partials.dns-pill', ['domain' => $d])</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
</x-layouts.client>
