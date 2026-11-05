@php
    $r = fn (string $name, array $extra = []) => route($area . '.mail.domains.' . $name, [$domain->id, ...$extra]);
    $tabs = ['mail' => __('Mail settings'), 'mailboxes' => __('Mailboxes'), 'dns' => __('DNS records')];
@endphp
<x-dynamic-component :component="'layouts.' . $area" :title="$domain->domain">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area . '.mail.domains.index') }}">{{ $area === 'admin' ? __('Domains & mail') : __('Mail & DNS') }}</a>
                @if ($area === 'admin') / <a href="{{ route('admin.customers.show', $domain->customer) }}">{{ $domain->customer->displayName() }}</a>@endif</div>
            <div class="actions"><h1>{{ $domain->domain }}</h1>
                @include('dns-mail::partials.provider-pill', ['provider' => $domain->provider])
                @include('dns-mail::partials.dns-pill', ['domain' => $domain])</div>
        </div>
        <div class="actions">
            @if ($domain->hasLocalMail() && $webmailHost)
                <a class="btn" href="https://{{ $webmailHost }}" target="_blank" rel="noopener"><x-icon name="mail" />{{ __('Webmail') }}<x-icon name="external" /></a>
            @elseif ($domain->provider === 'microsoft365')
                <a class="btn" href="https://outlook.office.com/mail/" target="_blank" rel="noopener"><x-icon name="mail" />Outlook<x-icon name="external" /></a>
            @endif
            @if ($area === 'admin' && $domain->website)
                <a class="btn" href="{{ route('admin.websites.show', $domain->website) }}"><x-icon name="globe" />{{ __('Website') }}</a>
            @endif
        </div>
    </div>

    @include('dns-mail::partials.warnings')

    <nav class="seg" aria-label="{{ __('Sections') }}" style="margin-bottom:4px">
        @foreach ($tabs as $key => $label)
            <a href="{{ $r('show', ['tab' => $key]) }}" @class(['btn', 'btn-dark' => $tab === $key, 'btn-ghost' => $tab !== $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @include('dns-mail::domain.tab-' . $tab)
</x-dynamic-component>
