<x-layouts.admin :title="$customer->displayName()">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.customers.index') }}">{{ __('Customers') }}</a></div>
            <div class="actions"><h1>{{ $customer->displayName() }}</h1><x-ui.pill :status="$customer->status">{{ __(ucfirst($customer->status)) }}</x-ui.pill></div>
            <p class="sub">{{ $customer->email }}@if ($customer->phone) · {{ $customer->phone }}@endif</p>
        </div>
        <div class="actions">
            <x-ui.button icon="edit" :href="route('admin.customers.edit', $customer)">{{ __('Edit') }}</x-ui.button>
            <x-ui.button variant="primary" icon="plus" :href="route('admin.websites.create', ['customer' => $customer->id])">{{ __('New website') }}</x-ui.button>
        </div>
    </div>

    @include('admin.customers.quick-actions')

    @if ($customer->websites->filter(fn ($w) => $w->subscription?->suspended_reason === \App\Services\Traffic::SUSPENDED)->isNotEmpty())
        <div class="alert alert-warn" role="status"><x-icon name="info" /><div><strong>{{ __('Websites of this customer are suspended: the data traffic limit of their package is reached.') }}</strong>
            <p class="small">{{ __('They come back online automatically on the first day of next month.') }}</p></div>
            <form method="POST" action="{{ route('admin.customers.traffic-unsuspend', $customer) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Put online now') }}</button></form></div>
    @endif
    <section class="grid grid-stats">
        <div class="card stat"><span class="label">{{ __('Subscriptions') }}</span><strong class="value"><a href="{{ route('admin.subscriptions.index', ['customer' => $customer->id]) }}">{{ $customer->websites->count() }}</a></strong></div>
        <div class="card stat"><span class="label">{{ __('Disk') }}</span><strong class="value" style="font-size:24px">{{ \Illuminate\Support\Number::fileSize($customer->diskUsedMb() * 1024 * 1024) }}</strong></div>
        <div class="card stat"><span class="label">{{ __('Data traffic this month') }}</span><strong class="value" style="font-size:24px">{{ \Illuminate\Support\Number::fileSize($customer->trafficBytes(), 1) }}</strong></div>
        <div class="card stat"><span class="label">CRM</span><strong class="value mono" style="font-size:18px">{{ $customer->crm_id ?? '–' }}</strong></div>
    </section>

    <x-ui.card :title="__('Subscriptions')" flush>
        @if ($customer->websites->isEmpty())
            <x-ui.empty icon="globe">{{ __('This customer has no websites yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Domain') }}</th><th>{{ __('Package') }}</th><th>{{ __('Expiry date') }}</th><th>PHP</th><th>{{ __('Type') }}</th><th>SSL</th><th>{{ __('Status') }}</th></x-slot:head>
            @foreach ($customer->websites as $w)
                <tr>
                    <td><a class="strong" href="{{ route('admin.websites.show', $w) }}">{{ $w->domain }}</a></td>
                    <td>{{ $w->subscription?->package?->name ?? __('No package') }}</td>
                    <td>{{ $w->subscription?->expires_on?->toDateString() ?? '–' }}</td>
                    <td>{{ $w->php_version }}</td><td>{{ __(ucfirst($w->profile)) }}</td>
                    <td><x-ui.pill :status="$w->ssl_status">{{ __(ucfirst($w->ssl_status)) }}</x-ui.pill></td>
                    <td><x-ui.pill :status="$w->status">{{ __(ucfirst($w->status)) }}</x-ui.pill></td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Logins')">
            <p class="muted small">{{ __('People who can log in to the client panel for this customer.') }}</p>
            <div>
                @forelse ($customer->users as $u)
                    <div class="row">
                        <span><strong>{{ $u->name }}</strong><br><span class="small muted">{{ $u->email }} · {{ $locales[$u->locale] ?? $u->locale }}@if ($u->hasTwoFactor()) · 2FA @endif</span></span>
                        <span class="login-actions">
                            <form method="POST" action="{{ route('admin.customers.users.password', [$customer, $u]) }}" data-confirm="{{ __('Give :email a new password? The old password stops working.', ['email' => $u->email]) }}">@csrf
                                <button class="btn btn-sm" type="submit" title="{{ __('New password') }}"><x-icon name="key" /><span class="login-action-label">{{ __('New password') }}</span></button></form>
                            @if ($u->hasTwoFactor())
                                <form method="POST" action="{{ route('admin.customers.users.two-factor', [$customer, $u]) }}" data-confirm="{{ __('Turn off two-factor authentication for :email?', ['email' => $u->email]) }}">@csrf
                                    <button class="btn btn-sm" type="submit" title="{{ __('Turn off 2FA') }}"><x-icon name="lock" /><span class="login-action-label">{{ __('Turn off 2FA') }}</span></button></form>
                            @endif
                            <form method="POST" action="{{ route('admin.customers.users.destroy', [$customer, $u]) }}" data-confirm="{{ __('Delete this login?') }}">@csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger" type="submit" aria-label="{{ __('Delete') }} {{ $u->email }}"><x-icon name="trash" /></button></form>
                        </span>
                    </div>
                @empty
                    <p class="small muted">{{ __('No logins yet.') }}</p>
                @endforelse
            </div>
            <details>
                <summary class="btn btn-sm" style="display:inline-flex">{{ __('Add login') }}</summary>
                <form method="POST" action="{{ route('admin.customers.users.store', $customer) }}" class="form" style="margin-top:14px">
                    @csrf
                    <div class="form-grid">
                        <x-ui.field name="name" :label="__('Name')" :value="$customer->name" required />
                        <x-ui.field name="email" type="email" :label="__('E-mail address')" :value="$customer->email" required />
                        <x-ui.field name="password" type="password" :label="__('Password')" :help="__('At least 12 characters.')" required autocomplete="new-password" />
                        <x-ui.field name="locale" type="select" :label="__('Language')">
                            @foreach ($locales as $code => $name)<option value="{{ $code }}" @selected($customer->locale === $code)>{{ $name }}</option>@endforeach
                        </x-ui.field>
                    </div>
                    <div><button class="btn btn-dark" type="submit">{{ __('Create login') }}</button></div>
                </form>
            </details>
        </x-ui.card>

        <x-ui.card :title="__('Activity')">
            <div>
                @forelse ($activity as $log)
                    <div class="row"><span class="small">{{ $log->label() }}@if ($log->subject) · <strong>{{ $log->subject }}</strong>@endif</span><span class="small muted" title="{{ $log->created_at }}">{{ $log->created_at->diffForHumans() }}</span></div>
                @empty
                    <p class="small muted">{{ __('Nothing yet.') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    </section>

    @if ($customer->notes)
        <x-ui.card :title="__('Notes')"><p style="white-space:pre-line">{{ $customer->notes }}</p></x-ui.card>
    @endif

    <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}" data-confirm="{{ __('Delete this customer?') }}">
        @csrf @method('DELETE')<button type="submit" class="btn btn-danger btn-sm"><x-icon name="trash" />{{ __('Delete customer') }}</button>
    </form>
</x-layouts.admin>
