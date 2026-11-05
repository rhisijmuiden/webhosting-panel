<x-layouts.admin :title="__('Domains & mail')">
    <div class="page-head">
        <div><h1>{{ __('Domains & mail') }}</h1><p class="sub">{{ __('DNS at Cloudflare, mail routing and mailboxes per domain.') }}</p></div>
        <div class="actions">
            <form class="search" method="GET" role="search"><x-icon name="search" /><input type="search" name="q" value="{{ $q }}" placeholder="{{ __('Domain') }}" aria-label="{{ __('Search') }}"></form>
            <x-ui.button variant="primary" icon="plus" :href="route('admin.mail.domains.create')">{{ __('Add domain') }}</x-ui.button>
        </div>
    </div>
    @unless ($hasCloudflare)
        <div class="alert alert-info" role="status"><x-icon name="cloud" /><div>{{ __('Cloudflare is not connected yet, so DNS is not synced.') }} <a href="{{ route('admin.mail.settings.edit') }}">{{ __('Connect Cloudflare') }}</a></div></div>
    @endunless
    <x-ui.card flush>
        @if ($domains->isEmpty())
            <x-ui.empty icon="mail">{{ __('No domains yet. Domains are added automatically when you create a website.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Domain') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Mail') }}</th><th class="num">{{ __('Mailboxes') }}</th><th>DNS</th></x-slot:head>
            @foreach ($domains as $d)
                <tr>
                    <td><a class="strong" href="{{ route('admin.mail.domains.show', $d->id) }}">{{ $d->domain }}</a></td>
                    <td><a href="{{ route('admin.customers.show', $d->customer) }}">{{ $d->customer->displayName() }}</a></td>
                    <td>@include('dns-mail::partials.provider-pill', ['provider' => $d->provider])</td>
                    <td class="num">{{ $d->hasLocalMail() ? $d->mailboxes_count : '–' }}</td>
                    <td>@include('dns-mail::partials.dns-pill', ['domain' => $d])</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    {{ $domains->links('partials.pagination') }}
</x-layouts.admin>
